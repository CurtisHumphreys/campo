<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only maintenance script
// Diagnostic: cross-reference legacy site allocations vs CampOffice data.
// Flags:
//   A) Person in legacy has site X, but their CO household has a different site (or no site).
//   B) Multiple legacy members with DIFFERENT sites map to the same CO household
//      → likely separate households collapsed into one.
//   C) Legacy allocations that couldn't be matched at all (no CS person ID / no CO member).

$dump = '/opt/forgebox/uploads/arcamp_campo _1__20260501_105549.sql';
$pdo  = new PDO('mysql:host=127.0.0.1;dbname=campoffice', 'forgebox', 'Forgebox3.b');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$content = file_get_contents($dump);

// ── Parser helpers ────────────────────────────────────────────────────────────
function parseInsertBlock(string $block): array {
    if (!preg_match('/\bVALUES\s+(.+)$/si', $block, $m)) return [];
    $valStr = rtrim($m[1], ';');
    $rows = []; $i = 0; $len = strlen($valStr);
    while ($i < $len) {
        while ($i < $len && $valStr[$i] !== '(') $i++;
        if ($i >= $len) break;
        $i++;
        $cells = []; $current = ''; $inStr = false; $strChar = '';
        while ($i < $len) {
            $ch = $valStr[$i];
            if ($inStr) {
                if ($ch === '\\') { $current .= $ch . ($valStr[$i+1] ?? ''); $i += 2; continue; }
                if ($ch === $strChar) { $inStr = false; $i++; continue; }
                $current .= $ch; $i++; continue;
            }
            if ($ch === "'" || $ch === '"') { $inStr = true; $strChar = $ch; $i++; continue; }
            if ($ch === ')') { $cells[] = $current; $current = ''; $i++; break; }
            if ($ch === ',') { $cells[] = $current; $current = ''; $i++; continue; }
            $current .= $ch; $i++;
        }
        if ($cells) $rows[] = $cells;
        while ($i < $len && in_array($valStr[$i], [',', ' ', "\n", "\r"])) $i++;
    }
    return $rows;
}

// ── Parse legacy members: id → {first, last, email, cs_person_id} ─────────────
$legacyMembers = [];
preg_match_all('/INSERT INTO `members`[^;]+;/s', $content, $allMemberBlocks);
foreach ($allMemberBlocks[0] as $block) {
    foreach (parseInsertBlock($block) as $row) {
        $id = (int)trim($row[0]);
        if (!$id) continue;
        $legacyMembers[$id] = [
            'first'      => trim($row[1]),
            'last'       => trim($row[2]),
            'email'      => trim($row[3]),
            'cs_person_id' => trim($row[7] ?? ''),
        ];
    }
}

// ── Parse legacy sites: id → site_number ──────────────────────────────────────
$legacySiteMap = [];
if (preg_match('/INSERT INTO `sites`[^;]+;/s', $content, $m)) {
    foreach (parseInsertBlock($m[0]) as $row) {
        $legacySiteMap[(int)trim($row[0])] = trim($row[1]);
    }
}

// ── Parse legacy site_allocations (is_current=1) ──────────────────────────────
// Columns: id(0), site_id(1), member_id(2), start_date(3), end_date(4), is_current(5)
$legacyAllocs = [];
preg_match_all('/INSERT INTO `site_allocations`[^;]+;/s', $content, $allocBlocks);
foreach ($allocBlocks[0] as $block) {
    foreach (parseInsertBlock($block) as $row) {
        if (trim($row[5] ?? '0') !== '1') continue;
        $legacyAllocs[] = [
            'site_id'   => (int)trim($row[1]),
            'member_id' => (int)trim($row[2]),
        ];
    }
}

// ── Load CampOffice lookup tables ─────────────────────────────────────────────
// cs_person_id → {co_member_id, household_id, first, last, email}
$coByCs = [];
foreach ($pdo->query("
    SELECT m.id, m.churchsuite_person_id, m.household_id,
           m.first_name, m.last_name, m.email
    FROM members m
    WHERE m.churchsuite_person_id IS NOT NULL AND m.churchsuite_person_id != ''
")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $coByCs[$r['churchsuite_person_id']] = $r;
}

// household_id → site_number (from site_allocations JOIN sites)
$householdSite = [];
foreach ($pdo->query("
    SELECT sa.household_id, s.site_number
    FROM site_allocations sa
    JOIN sites s ON s.id = sa.site_id
")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $householdSite[(int)$r['household_id']] = $r['site_number'];
}

// household_id → household name
$householdName = [];
foreach ($pdo->query("SELECT id, name FROM households")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $householdName[(int)$r['id']] = $r['name'];
}

// ── Cross-reference ────────────────────────────────────────────────────────────
// For each legacy allocation, build a record of:
//   legacy site_number, legacy member name, co household, co allocated site
$records = [];
foreach ($legacyAllocs as $alloc) {
    $legacySiteNum = $legacySiteMap[$alloc['site_id']] ?? null;
    $legMember     = $legacyMembers[$alloc['member_id']] ?? null;
    $csId          = $legMember['cs_person_id'] ?? '';
    $coMember      = $csId ? ($coByCs[$csId] ?? null) : null;
    $householdId   = $coMember ? (int)$coMember['household_id'] : null;
    $coSite        = $householdId ? ($householdSite[$householdId] ?? null) : null;
    $hhName        = $householdId ? ($householdName[$householdId] ?? "HH#$householdId") : null;

    $records[] = [
        'legacy_site'    => $legacySiteNum,
        'legacy_member'  => $legMember ? trim($legMember['first'] . ' ' . $legMember['last']) : "member#{$alloc['member_id']}",
        'legacy_email'   => $legMember['email'] ?? '',
        'cs_person_id'   => $csId,
        'co_household'   => $hhName,
        'co_hh_id'       => $householdId,
        'co_site'        => $coSite,
        'matched_in_co'  => $coMember !== null,
    ];
}

// ── Flag B: group by co_hh_id, find households with multiple different legacy sites ──
$hhSites = []; // co_hh_id → [site_number => [member names]]
foreach ($records as $r) {
    if (!$r['co_hh_id']) continue;
    $hhSites[$r['co_hh_id']][$r['legacy_site']][] = $r['legacy_member'];
}
$splitCandidates = [];
foreach ($hhSites as $hhId => $siteGroups) {
    if (count($siteGroups) > 1) {
        $splitCandidates[$hhId] = $siteGroups;
    }
}

// ── Flag A: person matched in CO but household has wrong/no site ───────────────
$wrongSite = [];
foreach ($records as $r) {
    if (!$r['matched_in_co']) continue;
    if ($r['co_site'] !== $r['legacy_site']) {
        $wrongSite[] = $r;
    }
}

// ── Flag C: unmatched (no CS ID or no CO member) ──────────────────────────────
$unmatched = array_filter($records, fn($r) => !$r['matched_in_co']);

// ── Report ────────────────────────────────────────────────────────────────────
echo "=== LEGACY ALLOCATION AUDIT ===\n\n";
echo "Total is_current legacy allocations: " . count($legacyAllocs) . "\n";
echo "Matched to CO member/household:       " . count(array_filter($records, fn($r) => $r['matched_in_co'])) . "\n";
echo "Unmatched (no CS ID or CO member):    " . count($unmatched) . "\n\n";

// ── SECTION 1: Households with multiple different sites ───────────────────────
echo "══════════════════════════════════════════════════════════════════\n";
echo "SECTION 1: CO households with MULTIPLE legacy sites assigned\n";
echo "(These are likely 2+ real households collapsed into one in CO)\n";
echo "══════════════════════════════════════════════════════════════════\n\n";
if (empty($splitCandidates)) {
    echo "None found.\n\n";
} else {
    foreach ($splitCandidates as $hhId => $siteGroups) {
        $hhNameStr = $householdName[$hhId] ?? "HH#$hhId";
        echo "Household: $hhNameStr (ID=$hhId)\n";
        foreach ($siteGroups as $site => $members) {
            echo "  Site $site → " . implode(', ', $members) . "\n";
        }
        echo "  CO currently allocated to: " . ($householdSite[$hhId] ?? 'NO SITE') . "\n\n";
    }
}

// ── SECTION 2: Site mismatch — CO household has wrong site ───────────────────
echo "══════════════════════════════════════════════════════════════════\n";
echo "SECTION 2: Person matched in CO but household has WRONG site\n";
echo "══════════════════════════════════════════════════════════════════\n\n";
// Filter out those already flagged in Section 1 (split candidates)
$wrongSiteNotSplit = array_filter($wrongSite, fn($r) => !isset($splitCandidates[$r['co_hh_id']]));
if (empty($wrongSiteNotSplit)) {
    echo "None found.\n\n";
} else {
    foreach ($wrongSiteNotSplit as $r) {
        echo "Person:      {$r['legacy_member']} ({$r['legacy_email']})\n";
        echo "Legacy site: {$r['legacy_site']}\n";
        echo "CO household: {$r['co_household']} (ID={$r['co_hh_id']})\n";
        echo "CO site:     " . ($r['co_site'] ?? 'NO SITE ALLOCATED') . "\n\n";
    }
}

// ── SECTION 3: Unmatched legacy allocations ───────────────────────────────────
echo "══════════════════════════════════════════════════════════════════\n";
echo "SECTION 3: Legacy allocations with no match in CampOffice\n";
echo "══════════════════════════════════════════════════════════════════\n\n";
if (empty($unmatched)) {
    echo "None.\n\n";
} else {
    foreach ($unmatched as $r) {
        $reason = $r['cs_person_id'] ? "CS ID {$r['cs_person_id']} not in CO" : "no churchsuite_person_id";
        echo "Person: {$r['legacy_member']} ({$r['legacy_email']}) — Site {$r['legacy_site']} — $reason\n";
    }
}

// ── SECTION 4: Full match summary ─────────────────────────────────────────────
echo "\n══════════════════════════════════════════════════════════════════\n";
echo "SECTION 4: All matched allocations (for verification)\n";
echo "══════════════════════════════════════════════════════════════════\n\n";
printf("%-10s %-28s %-30s %-10s\n", 'LegSite', 'Legacy Member', 'CO Household', 'CO Site');
echo str_repeat('-', 82) . "\n";
$matched = array_filter($records, fn($r) => $r['matched_in_co']);
usort($matched, fn($a,$b) => strnatcmp($a['legacy_site'], $b['legacy_site']));
foreach ($matched as $r) {
    $status = ($r['co_site'] === $r['legacy_site']) ? 'OK' : 'MISMATCH';
    printf("%-10s %-28s %-30s %-10s %s\n",
        $r['legacy_site'],
        substr($r['legacy_member'], 0, 27),
        substr($r['co_household'] ?? '', 0, 29),
        $r['co_site'] ?? 'NONE',
        $status
    );
}
