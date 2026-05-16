<?php
// Comprehensive allocation fix.
// Section 1: Split 8 merged households, fix 4 wrong-site allocations.
// Section 2: Eric Thomas + Indigo both get their two sites; Jarra → Site 153.
// Section 3: John Williams joins Judy's household; Daniel Berkefeld gets new HH + Site 126.
// Section 4: Create new household+member for each unmatched legacy person (skips occupied sites).

$dump = '/opt/forgebox/uploads/arcamp_campo _1__20260501_105549.sql';
$pdo  = new PDO('mysql:host=127.0.0.1;dbname=campoffice', 'forgebox', 'Forgebox3.b');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ── Parser ────────────────────────────────────────────────────────────────────
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

// ── Parse legacy dump ─────────────────────────────────────────────────────────
echo "Loading legacy dump...\n";
$content = file_get_contents($dump);

$legacyMembers = [];
preg_match_all('/INSERT INTO `members`[^;]+;/s', $content, $mblocks);
foreach ($mblocks[0] as $block) {
    foreach (parseInsertBlock($block) as $row) {
        $id = (int)trim($row[0]);
        if (!$id) continue;
        $legacyMembers[$id] = [
            'first' => trim($row[1]),
            'last'  => trim($row[2]),
            'email' => trim($row[3]),
            'cs_id' => trim($row[7] ?? ''),
        ];
    }
}

$legacySiteMap = [];
if (preg_match('/INSERT INTO `sites`[^;]+;/s', $content, $sm)) {
    foreach (parseInsertBlock($sm[0]) as $row) {
        $legacySiteMap[(int)trim($row[0])] = trim($row[1]);
    }
}

// site_number → [member_ids] for is_current allocations
$legacyAllocsBySite = [];
preg_match_all('/INSERT INTO `site_allocations`[^;]+;/s', $content, $ablocks);
foreach ($ablocks[0] as $block) {
    foreach (parseInsertBlock($block) as $row) {
        if (trim($row[5] ?? '0') !== '1') continue;
        $siteNum = $legacySiteMap[(int)trim($row[1])] ?? null;
        if ($siteNum) $legacyAllocsBySite[$siteNum][] = (int)trim($row[2]);
    }
}
echo "Legacy parsed: " . count($legacyMembers) . " members, " . count($legacySiteMap) . " sites\n";

// ── CO lookups ────────────────────────────────────────────────────────────────
$coMemberByCs = [];
foreach ($pdo->query("SELECT id, first_name, last_name, household_id, churchsuite_person_id FROM members WHERE churchsuite_person_id IS NOT NULL AND churchsuite_person_id != ''")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $coMemberByCs[$r['churchsuite_person_id']] = $r;
}

$coSiteIdMap = [];
foreach ($pdo->query("SELECT id, site_number FROM sites")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $coSiteIdMap[$r['site_number']] = (int)$r['id'];
}

// Live allocation state (kept in sync as we make changes)
$coSiteToHh = [];   // site_number => household_id
$coHhToSites = [];  // household_id => [site_number, ...]
foreach ($pdo->query("SELECT sa.household_id, s.site_number FROM site_allocations sa JOIN sites s ON s.id = sa.site_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $coSiteToHh[$r['site_number']] = (int)$r['household_id'];
    $coHhToSites[(int)$r['household_id']][] = $r['site_number'];
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function findCoMemberBySite(string $siteNum): ?array {
    global $legacyAllocsBySite, $legacyMembers, $coMemberByCs;
    foreach (($legacyAllocsBySite[$siteNum] ?? []) as $memberId) {
        $csId = $legacyMembers[$memberId]['cs_id'] ?? '';
        if ($csId && isset($coMemberByCs[$csId])) return $coMemberByCs[$csId];
    }
    return null;
}

function createHousehold(string $name): int {
    global $pdo;
    $pdo->prepare("INSERT INTO households (name) VALUES (?)")->execute([$name]);
    return (int)$pdo->lastInsertId();
}

function allocateSite(int $hhId, string $siteNum, string $label = ''): bool {
    global $pdo, $coSiteIdMap, $coSiteToHh, $coHhToSites;
    $siteId = $coSiteIdMap[$siteNum] ?? null;
    if (!$siteId) { echo "  !! Site '$siteNum' not found in CO\n"; return false; }
    if (isset($coSiteToHh[$siteNum]) && $coSiteToHh[$siteNum] !== $hhId) {
        echo "  !! CONFLICT: Site $siteNum already allocated to HH {$coSiteToHh[$siteNum]}\n";
        return false;
    }
    $pdo->prepare("INSERT IGNORE INTO site_allocations (site_id, household_id) VALUES (?,?)")->execute([$siteId, $hhId]);
    $coSiteToHh[$siteNum] = $hhId;
    if (!in_array($siteNum, $coHhToSites[$hhId] ?? [])) $coHhToSites[$hhId][] = $siteNum;
    echo "  -> Allocated Site $siteNum to HH $hhId" . ($label ? " ($label)" : '') . "\n";
    return true;
}

function deallocateSite(int $hhId, string $siteNum): void {
    global $pdo, $coSiteIdMap, $coSiteToHh, $coHhToSites;
    $siteId = $coSiteIdMap[$siteNum] ?? null;
    if ($siteId) $pdo->prepare("DELETE FROM site_allocations WHERE site_id = ? AND household_id = ?")->execute([$siteId, $hhId]);
    unset($coSiteToHh[$siteNum]);
    $coHhToSites[$hhId] = array_values(array_filter($coHhToSites[$hhId] ?? [], fn($s) => $s !== $siteNum));
    echo "  -> Removed Site $siteNum from HH $hhId\n";
}

function moveMembers(array $memberIds, int $newHhId): void {
    global $pdo;
    $ids = implode(',', array_map('intval', $memberIds));
    if ($ids) $pdo->exec("UPDATE members SET household_id = $newHhId WHERE id IN ($ids)");
}

// ── Step 0: Drop uq_household ─────────────────────────────────────────────────
echo "\n=== Step 0: Remove uq_household constraint ===\n";
try {
    $pdo->exec("ALTER TABLE site_allocations DROP INDEX uq_household");
    echo "  Dropped.\n";
} catch (Exception $e) {
    echo "  Already removed or not found.\n";
}

// ── SECTION 1: Split merged households ───────────────────────────────────────
echo "\n=== SECTION 1: Split merged households ===\n";

// [personBSite, personAHhId, personACurrentWrongSite, personACorrectSite]
// personACurrentWrongSite = null if Person A already has correct site
$splits = [
    ['152', 481, null,  null ],   // Darren Barnett out of Jake Manton HH; Jake stays on 33
    ['347', 783, null,  null ],   // Chanthan Kha out of John Van de Giessen HH; John stays on 66
    ['86',  188, '86',  '89' ],   // Zech McLean out; Rhys De Dezsery HH → Site 89
    ['308', 58,  null,  null ],   // Julie Blacket out of Michael Blacket HH; Michael stays on 128
    ['180', 115, '180', '311'],   // Lyn Tree out; Jan Campbell HH → Site 311
    ['214', 773, '214', '344'],   // Andrea Nankivell out; Chris Ttofa HH → Site 344
    ['35',  588, null,  null ],   // John St. Hill out of Luke Pater HH; Luke stays on 302
    ['364', 696, '364', '129'],   // Marie Hocking out; Anthony Schlotzer HH → Site 129
];

foreach ($splits as [$bSite, $aHhId, $aWrongSite, $aCorrectSite]) {
    echo "\nSite $bSite (splitting from HH $aHhId):\n";
    $coMember = findCoMemberBySite($bSite);
    if (!$coMember) {
        echo "  !! Cannot find CO member for legacy site $bSite — skipping\n";
        continue;
    }
    $fullName = trim($coMember['first_name'] . ' ' . $coMember['last_name']);
    $memberId = (int)$coMember['id'];
    $curHhId  = (int)$coMember['household_id'];
    echo "  Person B: $fullName (member ID=$memberId, currently in HH $curHhId)\n";

    // Create new household
    $newHhId = createHousehold($fullName);
    echo "  Created household '$fullName' (HH $newHhId)\n";

    // Move Person B to their own household
    moveMembers([$memberId], $newHhId);
    echo "  Moved $fullName to HH $newHhId\n";

    if ($aWrongSite !== null) {
        // Person A's HH currently has Person B's site — swap them
        deallocateSite($aHhId, $aWrongSite);
        allocateSite($newHhId, $bSite, $fullName);
        allocateSite($aHhId, $aCorrectSite, "Person A correct site");
    } else {
        // Person A already has the right site; just allocate Person B's new site
        allocateSite($newHhId, $bSite, $fullName);
    }
}

// ── SECTION 2: Multiple sites and corrections ─────────────────────────────────
echo "\n=== SECTION 2: Multiple sites and corrections ===\n";

// 2a: Eric Thomas — add Site 305 to HH 754 (already has 304)
echo "\nEric Thomas (HH 754): adding Site 305\n";
allocateSite(754, '305', 'Eric Thomas second site');

// 2b: Indigo Pinnegar — she and Simon Pinnegar both use Site 349.
// Simon is in a separate CO household. Move Simon to Indigo's HH 607, then allocate Site 349 to HH 607.
echo "\nIndigo Pinnegar (HH 607) + Simon Pinnegar: consolidating Site 349 under HH 607\n";
$simonRows = $pdo->query("SELECT id, household_id FROM members WHERE first_name = 'Simon' AND last_name = 'Pinnegar' LIMIT 1")->fetchAll(PDO::FETCH_ASSOC);
if ($simonRows) {
    $simon = $simonRows[0];
    $simonOldHh = (int)$simon['household_id'];
    echo "  Simon Pinnegar: member ID={$simon['id']}, currently HH $simonOldHh\n";
    // Remove Site 349 from Simon's old household
    if (isset($coSiteToHh['349']) && $coSiteToHh['349'] === $simonOldHh) {
        deallocateSite($simonOldHh, '349');
    }
    // Move Simon to Indigo's household
    moveMembers([(int)$simon['id']], 607);
    echo "  Moved Simon to HH 607 (Indigo's household)\n";
    // Allocate Site 349 to Indigo's HH
    allocateSite(607, '349', 'Indigo Pinnegar second site');
} else {
    echo "  !! Simon Pinnegar not found in CO — allocating Site 349 directly to HH 607\n";
    allocateSite(607, '349', 'Indigo Pinnegar second site');
}

// 2c: Jarra Montgomery (HH 524) — change from Site 458 to Site 153
// (Ella Montgomery is already in HH 524)
echo "\nJarra Montgomery (HH 524): Site 458 → Site 153\n";
if (in_array('458', $coHhToSites[524] ?? [])) {
    deallocateSite(524, '458');
}
allocateSite(524, '153', 'Jarra + Ella Montgomery');

// ── SECTION 3: Special cases ─────────────────────────────────────────────────
echo "\n=== SECTION 3: Special cases ===\n";

// 3a: John Williams — husband of Judy Williams; both on Site 78.
// Judy Williams' household already has Site 78. Move John into Judy's household.
echo "\nJohn Williams → Judy Williams' household (Site 78)\n";
$judyRows = $pdo->query("SELECT m.id, m.household_id FROM members m JOIN site_allocations sa ON sa.household_id = m.household_id JOIN sites s ON s.id = sa.site_id WHERE m.first_name = 'Judy' AND m.last_name = 'Williams' AND s.site_number = '78' LIMIT 1")->fetchAll(PDO::FETCH_ASSOC);
$johnRows = $pdo->query("SELECT id, household_id FROM members WHERE first_name = 'John' AND last_name = 'Williams' LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
if ($judyRows && $johnRows) {
    $judyHhId = (int)$judyRows[0]['household_id'];
    echo "  Judy's household: HH $judyHhId (Site 78)\n";
    // Find John Williams with no site allocation in his household
    $john = null;
    foreach ($johnRows as $jr) {
        $jhh = (int)$jr['household_id'];
        if (empty($coHhToSites[$jhh])) { $john = $jr; break; }
    }
    if (!$john && count($johnRows) === 1) $john = $johnRows[0];
    if ($john) {
        echo "  John Williams: member ID={$john['id']}, moving from HH {$john['household_id']} to HH $judyHhId\n";
        moveMembers([(int)$john['id']], $judyHhId);
    } else {
        echo "  !! Ambiguous — found " . count($johnRows) . " John Williams; manual review needed\n";
        foreach ($johnRows as $jr) echo "    member ID={$jr['id']}, HH={$jr['household_id']}\n";
    }
} else {
    echo "  !! Could not find Judy Williams with Site 78, or no John Williams found\n";
}

// 3b: Daniel Berkefeld — CO member exists but no household. Create HH + allocate Site 126.
echo "\nDaniel Berkefeld: create household + allocate Site 126\n";
$danRows = $pdo->query("SELECT id, household_id FROM members WHERE first_name = 'Daniel' AND last_name = 'Berkefeld' LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
if ($danRows) {
    // Pick the one with no household or null household
    $dan = null;
    foreach ($danRows as $dr) {
        if (!$dr['household_id'] || $dr['household_id'] == 0) { $dan = $dr; break; }
    }
    if (!$dan) $dan = $danRows[0]; // fall back to first
    echo "  Daniel Berkefeld: member ID={$dan['id']}, current HH={$dan['household_id']}\n";
    $danHhId = createHousehold('Daniel Berkefeld');
    echo "  Created HH $danHhId\n";
    moveMembers([(int)$dan['id']], $danHhId);
    allocateSite($danHhId, '126', 'Daniel Berkefeld');
} else {
    echo "  !! Daniel Berkefeld not found in CO — creating from scratch\n";
    $danHhId = createHousehold('Daniel Berkefeld');
    $pdo->prepare("INSERT INTO members (first_name, last_name, email, household_id) VALUES (?,?,?,?)")->execute(['Daniel', 'Berkefeld', 'danielrobyn@gmail.com', $danHhId]);
    allocateSite($danHhId, '126', 'Daniel Berkefeld');
}

// ── SECTION 4: Create new members for unmatched legacy people ─────────────────
echo "\n=== SECTION 4: Create households + members for unmatched legacy people ===\n";

$section4 = [
    ['Tom',      'Nobel',       '',                        '27'],
    ['Mark',     'Wickstein',   '',                        '37'],
    ['Phillip',  'Caffrey',     '',                        '40'],
    ['Mark G',   'Scrutton',    '',                        '63'],
    ['Phil',     'Macrow',      '',                        '90'],
    ['Kristian', 'Leister',     '',                        '92'],
    ['Julie',    'McLaughlin',  '',                        '101'],
    ['Myron',    'Ilko',        '',                        '107'],
    ['Ann',      "O'Connor",    '',                        '116'],
    ['Mario',    'Marusic',     '',                        '124'],
    ['Salvo',    'Dilettoso',   '',                        '139'],
    ['Valerie',  'Clee',        '',                        '140'],
    ['Mark',     'Richards',    '',                        '142'],
    ['Graeme',   'Pater',       '',                        '163'],
    ['Phil',     'Weber',       '',                        '167'],
    ['Hamish',   'Iveson',      'hamishiveson@gmail.com',  '172'],
    ['Ben',      'McDonald',    'bnbmcd@gmail.com',        '174'],
    ['Robert',   'Lucas',       '',                        '192'],
    ['Trevor',   'Gray',        '',                        '193'],
    ['Ray',      'Welch',       '',                        '213'],
    ['Peter',    'Nankivell',   '',                        '214'],
    ['John',     'Rudduck',     '',                        '226'],
    ['Jackie',   'Hewson',      '',                        '300'],
    ['Garry',    'Joske',       '',                        '313'],
    ['Kathy',    'Hawkswood',   'kathy_jh3@hotmail.com',   '343'],
    ['Fred',     'Thurma',      '',                        '348'],
    ['Luke',     'Howell',      '',                        '355'],
    ['Sue',      "O'Callaghan", '',                        '372'],
    ['Jeremy',   'Nelson',      '',                        '373'],
    ['Tony',     'Stevens',     '',                        '380'],
    ['Sid',      'Gunter',      '',                        '434'],
    ['Paul',     'Nobel',       '',                        '454'],
    ['Sarah',    'Robinson',    '',                        '117'],
    ['Louise',   'Benwell',     '',                        '30'],
    ['Alicia',   'Hogan',       '',                        '9'],
    ['Rob',      'McBride',     '',                        '15'],
    ['Georgia',  'Foord',       'georgiascott49@gmail.com','26'],
    ['Garry',    'Joske',       '',                        '313'],  // already in list, will skip
    ['Jen',      'Peterson',    '',                        '23'],
    ['Peter',    'Pedersen',    '',                        '106'],
    ['lisa',     'mason',       '',                        '36'],
    ['Mark',     'Nettleton',   'sirmark@adam.com.au',     '50'],
    ['Hamish',   'Iveson',      'hamishiveson@gmail.com',  '172'],  // duplicate — will skip
];

// Deduplicate by site number
$seen4 = [];
$created = 0; $skipped = 0;

foreach ($section4 as [$first, $last, $email, $siteNum]) {
    if (isset($seen4[$siteNum])) { continue; }
    $seen4[$siteNum] = true;

    $fullName = trim("$first $last");

    // Skip if site already allocated
    if (isset($coSiteToHh[$siteNum])) {
        $existingHh = $coSiteToHh[$siteNum];
        echo "  SKIP: Site $siteNum already allocated to HH $existingHh — $fullName\n";
        $skipped++;
        continue;
    }

    // Check CO sites table has this site number
    if (!isset($coSiteIdMap[$siteNum])) {
        echo "  SKIP: Site $siteNum not found in CO sites table — $fullName\n";
        $skipped++;
        continue;
    }

    // Create household
    $hhId = createHousehold($fullName);
    // Create member
    $pdo->prepare("INSERT INTO members (first_name, last_name, email, household_id) VALUES (?,?,?,?)")->execute([$first, $last, $email, $hhId]);
    // Allocate site
    allocateSite($hhId, $siteNum, $fullName);
    echo "  CREATED: $fullName → Site $siteNum (HH $hhId)\n";
    $created++;
}

echo "\n  Section 4 summary: $created created, $skipped skipped (site occupied or not found)\n";

// ── Final count ───────────────────────────────────────────────────────────────
echo "\n=== Final state ===\n";
$totalAllocs = (int)$pdo->query("SELECT COUNT(*) FROM site_allocations")->fetchColumn();
$totalHh     = (int)$pdo->query("SELECT COUNT(*) FROM households")->fetchColumn();
$totalMem    = (int)$pdo->query("SELECT COUNT(*) FROM members")->fetchColumn();
echo "site_allocations: $totalAllocs\n";
echo "households:       $totalHh\n";
echo "members:          $totalMem\n";
echo "\nDone.\n";
