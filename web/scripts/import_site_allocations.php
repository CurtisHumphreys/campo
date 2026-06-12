<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only maintenance script
// Import site allocations from legacy SQL dump.
// Matches legacy members → CampOffice households via churchsuite_person_id.
// Inserts perpetual (non-camp) allocations.

$dump = '/opt/forgebox/uploads/arcamp_campo _1__20260501_105549.sql';
$pdo  = new PDO('mysql:host=127.0.0.1;dbname=campoffice', 'forgebox', 'Forgebox3.b');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$content = file_get_contents($dump);
echo "Loaded dump (" . round(strlen($content) / 1024) . " KB)\n";

// ── Parse a single INSERT block into rows of column values ────────────────
function parseInsertBlock(string $block): array {
    // Find the VALUES section
    if (!preg_match('/\bVALUES\s+(.+)$/si', $block, $m)) return [];
    $valStr = rtrim($m[1], ';');
    $rows = [];
    $i = 0; $len = strlen($valStr);
    while ($i < $len) {
        // Skip to next opening paren
        while ($i < $len && $valStr[$i] !== '(') $i++;
        if ($i >= $len) break;
        $i++; // skip '('
        $cells = [];
        $current = '';
        $inStr = false; $strChar = '';
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
        // Skip comma between rows
        while ($i < $len && in_array($valStr[$i], [',', ' ', "\n", "\r"])) $i++;
    }
    return $rows;
}

// ── Extract a table's INSERT block ────────────────────────────────────────
function getInsertBlock(string $content, string $table): string {
    if (preg_match('/INSERT INTO `' . $table . '`[^;]+;/s', $content, $m)) return $m[0];
    return '';
}

// ── Parse legacy members: id → churchsuite_person_id ─────────────────────
// Columns: id(0), first_name(1), last_name(2), email(3), mobile(4), phone(5),
//          churchsuite_person_type(6), churchsuite_person_id(7), ...
$legacyMemberMap = []; // legacy_member_id => churchsuite_person_id
$memberBlock = getInsertBlock($content, 'members');
// Multiple INSERT blocks for members — get all
preg_match_all('/INSERT INTO `members`[^;]+;/s', $content, $allMemberBlocks);
foreach ($allMemberBlocks[0] as $block) {
    foreach (parseInsertBlock($block) as $row) {
        $id    = trim($row[0]);
        $csId  = trim($row[7] ?? '');
        if ($id !== '' && $csId !== '' && $csId !== 'NULL') {
            $legacyMemberMap[(int)$id] = $csId;
        }
    }
}
echo "Legacy members parsed: " . count($legacyMemberMap) . "\n";

// ── Parse legacy sites: id → site_number ─────────────────────────────────
// Columns: id(0), site_number(1), section(2), site_type(3), status(4), created_at(5), map_x(6), map_y(7)
$legacySiteMap = []; // legacy_site_id => site_number
$siteBlock = getInsertBlock($content, 'sites');
foreach (parseInsertBlock($siteBlock) as $row) {
    $id  = (int)trim($row[0]);
    $num = trim($row[1]);
    if ($id && $num !== '') $legacySiteMap[$id] = $num;
}
echo "Legacy sites parsed: " . count($legacySiteMap) . "\n";

// ── Parse legacy site_allocations: (site_id, member_id, is_current) ──────
// Columns: id(0), site_id(1), member_id(2), start_date(3), end_date(4), is_current(5), created_at(6)
$legacyAllocs = [];
$allocBlock = getInsertBlock($content, 'site_allocations');
foreach (parseInsertBlock($allocBlock) as $row) {
    $isCurrent = trim($row[5] ?? '0');
    if ($isCurrent !== '1') continue;
    $legacyAllocs[] = [
        'site_id'   => (int)trim($row[1]),
        'member_id' => (int)trim($row[2]),
    ];
}
echo "Legacy is_current allocations: " . count($legacyAllocs) . "\n";

// ── Build CampOffice lookup maps ──────────────────────────────────────────
// churchsuite_person_id → household_id
$coMemberMap = [];
$stmt = $pdo->query("SELECT churchsuite_person_id, household_id FROM members WHERE churchsuite_person_id IS NOT NULL AND churchsuite_person_id != '' AND household_id IS NOT NULL");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $coMemberMap[$r['churchsuite_person_id']] = (int)$r['household_id'];
}
echo "CampOffice members with cs_person_id: " . count($coMemberMap) . "\n";

// site_number → site_id
$coSiteMap = [];
$stmt = $pdo->query("SELECT id, site_number FROM sites");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $coSiteMap[$r['site_number']] = (int)$r['id'];
}
echo "CampOffice sites: " . count($coSiteMap) . "\n";

// ── Process allocations ───────────────────────────────────────────────────
$inserted   = 0;
$skippedNoMember = [];
$skippedNoSite   = [];
$skippedConflict = [];

$insStmt = $pdo->prepare("INSERT IGNORE INTO site_allocations (site_id, household_id) VALUES (?,?)");

foreach ($legacyAllocs as $alloc) {
    $legacySiteId   = $alloc['site_id'];
    $legacyMemberId = $alloc['member_id'];

    // Resolve site number
    $siteNumber = $legacySiteMap[$legacySiteId] ?? null;
    if (!$siteNumber) { $skippedNoSite[] = "legacy_site_id=$legacySiteId"; continue; }

    // Resolve CampOffice site_id
    $coSiteId = $coSiteMap[$siteNumber] ?? null;
    if (!$coSiteId) { $skippedNoSite[] = "site_number=$siteNumber"; continue; }

    // Resolve legacy churchsuite_person_id
    $csPersonId = $legacyMemberMap[$legacyMemberId] ?? null;
    if (!$csPersonId) { $skippedNoMember[] = "legacy_member_id=$legacyMemberId"; continue; }

    // Resolve CampOffice household_id
    $coHouseholdId = $coMemberMap[$csPersonId] ?? null;
    if (!$coHouseholdId) { $skippedNoMember[] = "cs_person_id=$csPersonId"; continue; }

    $insStmt->execute([$coSiteId, $coHouseholdId]);
    $inserted++;
}

echo "\n=== RESULTS ===\n";
echo "Inserted:            $inserted\n";
echo "Skipped (no member): " . count($skippedNoMember) . "\n";
echo "Skipped (no site):   " . count($skippedNoSite) . "\n";

if ($skippedNoMember) {
    echo "\nUnmatched members (first 20):\n";
    foreach (array_slice($skippedNoMember, 0, 20) as $s) echo "  $s\n";
}
if ($skippedNoSite) {
    echo "\nUnmatched sites:\n";
    foreach ($skippedNoSite as $s) echo "  $s\n";
}

$total = $pdo->query("SELECT COUNT(*) FROM site_allocations")->fetchColumn();
echo "\nTotal allocations now in CampOffice: $total\n";
