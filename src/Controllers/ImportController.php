<?php

require_once __DIR__ . '/../Database.php';

class ImportController {
    /**
     * Parse date strings coming from CSV.
     * Accepts:
     * - YYYY-MM-DD
     * - DD/MM/YYYY or D/M/YYYY (Australian)
     * - YYYY (treated as end of year)
     * Returns Y-m-d or null.
     */
    private function parseCsvDate($str) {
        $str = trim((string)$str);
        if ($str === '') return null;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $str)) {
            return $str;
        }

        if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $str)) {
            $dt = \DateTime::createFromFormat('d/m/Y', $str);
            if (!$dt) $dt = \DateTime::createFromFormat('j/n/Y', $str);
            if ($dt) return $dt->format('Y-m-d');
        }

        if (preg_match('/^\d{4}$/', $str)) {
            return $str . '-12-31';
        }

        // Last resort (best effort)
        $ts = strtotime(str_replace('/', '-', $str));
        if ($ts !== false) return date('Y-m-d', $ts);

        return null;
    }

    /**
     * Check if a column exists on a table (used to handle schema drift safely).
     */
    private function columnExists($db, $table, $column) {
        try {
            $stmt = $db->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
            $stmt->execute([$column]);
            return (bool)$stmt->fetch();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function upload() {
        if (!isset($_FILES['file'])) {
            http_response_code(400);
            echo json_encode(['error' => 'No file uploaded']);
            return;
        }

        // Require Camp Selection for legacy import now
        if (!isset($_POST['camp_id'])) {
             http_response_code(400);
             echo json_encode(['error' => 'Camp ID required']);
             return;
        }
        $campId = $_POST['camp_id'];

        $file = $_FILES['file']['tmp_name'];
        $handle = fopen($file, "r");
        
        // Skip header
        fgetcsv($handle);

        $db = Database::connect();
        $db->beginTransaction();
        
        try {
            $count = 0;
            while (($row = fgetcsv($handle)) !== false) {
                // Map CSV columns (UPDATED for removed "Camp" column, kept Split Names):
                // Year(0), First Name(1), Last Name(2), 
                // Site Type(3), Site Number(4), Arrive(5), Depart(6), 
                // Total Nights(7), Pre-paid(8), Camp Fees(9), Site Fees(10), Total(11), 
                // Eftpos(12), Cash(13), Cheque(14), Other(15), Concession(16), 
                // Payment Date(17), Site Fee Year Paid(18), Headcount(19)

                // Skip incomplete rows
                if(count($row) < 5) continue;

                $firstName = trim($row[1]);
                $lastName = trim($row[2]);
                $siteNumber = trim($row[4]);
                
                // 1. Find or Create Member
                // Match by First AND Last
                $stmtMem = $db->prepare("SELECT id FROM members WHERE first_name = ? AND last_name = ?");
                $stmtMem->execute([$firstName, $lastName]);
                $member = $stmtMem->fetch();
                
                // Concession column is now index 16
                $isConcession = (isset($row[16]) && strtolower($row[16]) == 'yes') ? 'Yes' : 'No';

                if (!$member) {
                    $stmtNewMem = $db->prepare("INSERT INTO members (first_name, last_name, concession, site_fee_status) VALUES (?, ?, ?, ?)");
                    $stmtNewMem->execute([$firstName, $lastName, $isConcession, 'Unknown']);
                    $memberId = $db->lastInsertId();
                } else {
                    $memberId = $member['id'];
                }

                // 2. Find Camp (Using ID from POST now)
                
                // 3. Find Site
                $siteId = null;
                if ($siteNumber) {
                    $stmtSite = $db->prepare("SELECT id FROM sites WHERE site_number = ?");
                    $stmtSite->execute([$siteNumber]);
                    $site = $stmtSite->fetch();
                    $siteId = $site ? $site['id'] : null;
                }

                // 4. Create Payment
                // Arrive(5), Depart(6)
                $arrive = $this->parseCsvDate($row[5]);
                $depart = $this->parseCsvDate($row[6]);

                // Payment Date (17), CampFee(9), SiteFee(10), Prepaid(8), Other(15), Total(11), Headcount(19)
                $payDateRaw = $row[17] ?? '';
                $payDate = $this->parseCsvDate($payDateRaw) ?: date('Y-m-d H:i:s'); // Fallback if missing
                // Append time if only date returned by helper to match DATETIME format
                if (strlen($payDate) <= 10) $payDate .= ' 00:00:00';

                $stmtPay = $db->prepare("
                    INSERT INTO payments (
                        member_id, camp_id, site_id, payment_date, 
                        camp_fee, site_fee, prepaid_applied, other_amount, 
                        total, headcount, notes, arrival_date, departure_date
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Legacy Import', ?, ?)
                ");
                
                $stmtPay->execute([
                    $memberId,
                    $campId,
                    $siteId,
                    $payDate,
                    (float)($row[9] ?? 0),  // Camp Fees
                    (float)($row[10] ?? 0), // Site Fees
                    (float)($row[8] ?? 0),  // Pre-paid
                    (float)($row[15] ?? 0), // Other
                    (float)($row[11] ?? 0), // Total
                    (int)($row[19] ?? 0),   // Headcount
                    $arrive,
                    $depart
                ]);
                $paymentId = $db->lastInsertId();

                // 5. Tenders
                // Eftpos(12), Cash(13), Cheque(14)
                $tenders = [
                    'EFTPOS' => (float)($row[12] ?? 0),
                    'Cash'   => (float)($row[13] ?? 0),
                    'Cheque' => (float)($row[14] ?? 0)
                ];

                $stmtTender = $db->prepare("INSERT INTO payment_tenders (payment_id, method, amount) VALUES (?, ?, ?)");
                foreach ($tenders as $method => $amount) {
                    if ($amount > 0) {
                        $stmtTender->execute([$paymentId, $method, $amount]);
                    }
                }
                
                // 6. Update Site Fee Paid Until if applicable (Col 18)
                $feeYear = trim($row[18] ?? '');
                if (!empty($feeYear)) {
                    // Assuming row[18] is a year like "2025" or date
                    $paidUntil = (strlen($feeYear) == 4) ? $feeYear . '-12-31' : $this->parseCsvDate($feeYear);
                    
                    if ($paidUntil) {
                        $stmtCheck = $db->prepare("SELECT id FROM site_fee_accounts WHERE member_id = ?");
                        $stmtCheck->execute([$memberId]);
                        $account = $stmtCheck->fetch();

                        if ($account) {
                            $stmtUpdate = $db->prepare("UPDATE site_fee_accounts SET paid_until = ?, status = 'Paid' WHERE id = ?");
                            $stmtUpdate->execute([$paidUntil, $account['id']]);
                        } else {
                            $stmtCreate = $db->prepare("INSERT INTO site_fee_accounts (member_id, paid_until, status) VALUES (?, ?, 'Paid')");
                            $stmtCreate->execute([$memberId, $paidUntil]);
                        }
                        
                        $stmtMemUp = $db->prepare("UPDATE members SET site_fee_status = 'Paid' WHERE id = ?");
                        $stmtMemUp->execute([$memberId]);
                    }
                }

                $count++;
            }
            
            $db->commit();
            echo json_encode(['success' => true, 'count' => $count]);

        } catch (Exception $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function importMembers() {
        if (!isset($_FILES['file'])) {
            http_response_code(400);
            echo json_encode(['error' => 'No file uploaded']);
            return;
        }

        $file = $_FILES['file']['tmp_name'];
        $handle = fopen($file, "r");
        
        // Read Header
        $header = fgetcsv($handle);
        if (!$header) {
            echo json_encode(['error' => 'Empty CSV']);
            return;
        }

        // Map headers to indices
        $map = [];
        foreach ($header as $i => $col) {
            $col = strtolower(trim($col));
            if (strpos($col, 'first') !== false) $map['first_name'] = $i;
            elseif (strpos($col, 'last') !== false) $map['last_name'] = $i;
            elseif (strpos($col, 'fellowship') !== false) $map['fellowship'] = $i;
            elseif (strpos($col, 'concession') !== false) $map['concession'] = $i;
            elseif (strpos($col, 'fee') !== false && strpos($col, 'status') !== false) $map['site_fee_status'] = $i;
            elseif ((strpos($col, 'paid') !== false && strpos($col, 'until') !== false) || strpos($col, 'expiry') !== false) $map['site_fee_paid_until'] = $i;
            // Site allocation fields (allow multiple members per site via site_allocations)
            elseif ((strpos($col, 'site') !== false && (strpos($col, 'number') !== false || strpos($col, '#') !== false)) || $col === 'camp site') $map['site_number'] = $i;
            elseif (strpos($col, 'section') !== false) $map['section'] = $i;
            elseif (strpos($col, 'site') !== false && strpos($col, 'type') !== false) $map['site_type'] = $i;
        }

        // Fallbacks for missing headers (assume default order if mapping fails considerably)
        if (!isset($map['first_name']) && isset($header[0])) $map['first_name'] = 0;
        if (!isset($map['last_name']) && isset($header[1])) $map['last_name'] = 1;
        // Default others
        if (!isset($map['fellowship'])) $map['fellowship'] = 2; 
        if (!isset($map['concession'])) $map['concession'] = 3;
        if (!isset($map['site_fee_status'])) $map['site_fee_status'] = 4;
        // site_fee_paid_until is optional; leave unset if not provided

        // Site allocation fallbacks are optional (only set if column exists)

        $db = Database::connect();
        $db->beginTransaction();

        // Handle schema drift: some DBs have site_fee_accounts.site_id with a FK to sites.
        $sfaHasSiteId = false;
        try {
            $sfaHasSiteId = $this->columnExists($db, 'site_fee_accounts', 'site_id');
        } catch (\Throwable $e) {
            $sfaHasSiteId = false;
        }
        
        try {
            $count = 0;
            $updated = 0;
            
            $stmtCheck = $db->prepare("SELECT id FROM members WHERE first_name = ? AND last_name = ?");
            $stmtInsert = $db->prepare("INSERT INTO members (first_name, last_name, fellowship, concession, site_fee_status) VALUES (?, ?, ?, ?, ?)");
            $stmtUpdate = $db->prepare("UPDATE members SET fellowship = ?, concession = ?, site_fee_status = ? WHERE id = ?");

            // Site helpers for mapping multiple members to a single site
            $stmtFindSite = $db->prepare("SELECT id FROM sites WHERE site_number = ?");
            $stmtInsertSite = $db->prepare("INSERT INTO sites (site_number, section, site_type, status) VALUES (?, ?, ?, 'Allocated')");
            $stmtUpdateSite = $db->prepare("UPDATE sites SET section = COALESCE(NULLIF(?,''), section), site_type = COALESCE(NULLIF(?,''), site_type) WHERE id = ?");
            $stmtCheckAlloc = $db->prepare("SELECT id FROM site_allocations WHERE site_id = ? AND member_id = ? AND is_current = 1");
            $stmtInsertAlloc = $db->prepare("INSERT INTO site_allocations (site_id, member_id, start_date, is_current) VALUES (?, ?, CURDATE(), 1)");
            $stmtSetSiteAllocated = $db->prepare("UPDATE sites SET status = 'Allocated' WHERE id = ?");

            // Prepared statements for site fee account upsert (schema aware)
            $stmtCheckSfa = $db->prepare("SELECT id FROM site_fee_accounts WHERE member_id = ?");
            $stmtUpSfa = $sfaHasSiteId
                ? $db->prepare("UPDATE site_fee_accounts SET site_id = ?, paid_until = ?, status = ? WHERE id = ?")
                : $db->prepare("UPDATE site_fee_accounts SET paid_until = ?, status = ? WHERE id = ?");
            $stmtInsSfa = $sfaHasSiteId
                ? $db->prepare("INSERT INTO site_fee_accounts (member_id, site_id, paid_until, status) VALUES (?, ?, ?, ?)")
                : $db->prepare("INSERT INTO site_fee_accounts (member_id, paid_until, status) VALUES (?, ?, ?)");

            while (($row = fgetcsv($handle)) !== false) {
                // Use map to get values
                $firstName = isset($map['first_name'], $row[$map['first_name']]) ? trim($row[$map['first_name']]) : '';
                $lastName = isset($map['last_name'], $row[$map['last_name']]) ? trim($row[$map['last_name']]) : '';
                
                if (empty($firstName) || empty($lastName)) continue;

                $fellowship = isset($map['fellowship'], $row[$map['fellowship']]) ? trim($row[$map['fellowship']]) : '';
                $concessionRaw = isset($map['concession'], $row[$map['concession']]) ? strtolower(trim($row[$map['concession']])) : 'no';
                $feeStatusRaw = isset($map['site_fee_status'], $row[$map['site_fee_status']]) ? trim($row[$map['site_fee_status']]) : 'Unknown';

                // Robust Boolean Matching
                // Matches: yes, y, true, 1
                if (in_array($concessionRaw, ['yes', 'y', '1', 'true'])) {
                    $concession = 'Yes';
                } else {
                    $concession = 'No';
                }
                
                // Debug Log (remove in prod)
                error_log("Import Row: $firstName $lastName. Raw Concession: '$concessionRaw' -> Clean: '$concession'");

                $validStatuses = ['Paid', 'Unpaid', 'Overdue', 'Exempt', 'Unknown'];
                $feeStatus = in_array(ucfirst(strtolower($feeStatusRaw)), $validStatuses) ? ucfirst(strtolower($feeStatusRaw)) : 'Unknown';

                $stmtCheck->execute([$firstName, $lastName]);
                $exists = $stmtCheck->fetch();

                // Either insert or update the member. Track the member ID so we can update site fee info.
                $memId = null;
                if ($exists) {
                    $stmtUpdate->execute([$fellowship, $concession, $feeStatus, $exists['id']]);
                    $updated++;
                    $memId = $exists['id'];
                } else {
                    $stmtInsert->execute([$firstName, $lastName, $fellowship, $concession, $feeStatus]);
                    $count++;
                    $memId = $db->lastInsertId();
                }

                // Optional: map member to a site (many members can share the same site)
                // If the CSV includes a Site Number, we will:
                // 1) Find (or create) the site
                // 2) Create a current allocation row for this member+site (without removing others)
                $siteNumber = (isset($map['site_number']) && isset($row[$map['site_number']])) ? trim($row[$map['site_number']]) : '';
                $section = (isset($map['section']) && isset($row[$map['section']])) ? trim($row[$map['section']]) : '';
                $siteType = (isset($map['site_type']) && isset($row[$map['site_type']])) ? trim($row[$map['site_type']]) : '';

                $siteId = null;
                if ($siteNumber !== '') {
                    $stmtFindSite->execute([$siteNumber]);
                    $site = $stmtFindSite->fetch();
                    if ($site) {
                        $siteId = $site['id'];
                        // Update site metadata when provided
                        $stmtUpdateSite->execute([$section, $siteType, $siteId]);
                    } else {
                        $stmtInsertSite->execute([$siteNumber, $section, $siteType]);
                        $siteId = $db->lastInsertId();
                    }

                    // Insert allocation if not already a current allocation
                    $stmtCheckAlloc->execute([$siteId, $memId]);
                    if (!$stmtCheckAlloc->fetch()) {
                        $stmtInsertAlloc->execute([$siteId, $memId]);
                    }

                    $stmtSetSiteAllocated->execute([$siteId]);
                }

                // Update or create site fee account if paid_until column is present
                if (isset($map['site_fee_paid_until']) && $map['site_fee_paid_until'] !== null) {
                    $colIdx = $map['site_fee_paid_until'];
                    if (isset($row[$colIdx]) && trim($row[$colIdx]) !== '') {
                        $paidStr = trim($row[$colIdx]);
                        // Accept AU dates (D/M/YYYY), ISO dates, or year
                        $paidUntil = $this->parseCsvDate($paidStr);
                        if ($paidUntil) {
                            $stmtCheckSfa->execute([$memId]);
                            $sfa = $stmtCheckSfa->fetch();
                            // Upsert site fee account (schema aware)
                            $status = 'Paid';
                            if ($sfa) {
                                if ($sfaHasSiteId) {
                                    $stmtUpSfa->execute([$siteId, $paidUntil, $status, $sfa['id']]);
                                } else {
                                    $stmtUpSfa->execute([$paidUntil, $status, $sfa['id']]);
                                }
                            } else {
                                if ($sfaHasSiteId) {
                                    $stmtInsSfa->execute([$memId, $siteId, $paidUntil, $status]);
                                } else {
                                    $stmtInsSfa->execute([$memId, $paidUntil, $status]);
                                }
                            }
                            // Update member status
                            $db->prepare("UPDATE members SET site_fee_status = 'Paid' WHERE id = ?")->execute([$memId]);
                        }
                    }
                }


            }
            
            $db->commit();
            echo json_encode(['success' => true, 'count' => $count, 'updated' => $updated]);

        } catch (Exception $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function importPrepayments() {
        if (!isset($_FILES['file']) || !isset($_POST['camp_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'File and Camp ID required']);
            return;
        }
        
        $reqId = bin2hex(random_bytes(4));
        $campId = $_POST['camp_id'];
        $file = $_FILES['file']['tmp_name'];
        $fileSize = $_FILES['file']['size'];
        $fileName = $_FILES['file']['name'];

        error_log("IMPORT START reqId=$reqId campId=$campId file=$fileName size=$fileSize");

        $handle = fopen($file, "r");
        
        fgetcsv($handle); // Skip Header

        $db = Database::connect();
        $db->beginTransaction();

        try {
            $count = 0;
            $matched = 0;
            $skipped = 0;

            // Check for existing prepayments with same transaction_id (if available) or amount + name
            // Use existing check statement
            $stmtCheck = $db->prepare("SELECT id FROM prepayments WHERE camp_id = ? AND ((transaction_id != '' AND transaction_id = ?) OR (imported_name = ? AND amount = ?)) LIMIT 1");
            
            // Prepare an insert that includes the new columns first_name, last_name, and transaction_id.
            $stmtInsert = $db->prepare(
                "INSERT INTO prepayments (
                    camp_id, imported_name, first_name, last_name, amount, transaction_id, date, matched_member_id, original_data, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            // Fetch members for fuzzy matching (id, first_name, last_name)
            $members = $db->query("SELECT id, first_name, last_name FROM members")->fetchAll();

            while (($row = fgetcsv($handle)) !== false) {
                // Expect columns: First Name(0), Last Name(1), Amount(2), Transaction ID(3)
                $firstName = trim($row[0] ?? '');
                $lastName = trim($row[1] ?? '');
                $amountStr = $row[2] ?? '0';
                // Remove any currency symbols
                $amount = (float)preg_replace('/[^0-9.]/', '', $amountStr);
                $transactionId = trim($row[3] ?? '');

                if (($firstName === '' && $lastName === '') || $amount <= 0) continue;

                // Combine names into a single string for imported_name
                $importedName = trim($firstName . ' ' . $lastName);
                
                // Debug log check
                // error_log("CHECKING DUPLICATE reqId=$reqId name=$importedName amount=$amount tx=$transactionId");

                // Check for duplicate
                $stmtCheck->execute([$campId, $transactionId, $importedName, $amount]);
                if ($stmtCheck->fetch()) {
                    // Duplicate found, skip
                    // error_log("DUPLICATE FOUND reqId=$reqId name=$importedName");
                    $skipped++;
                    continue;
                }

                // Date column is no longer provided in this import; leave blank for now
                $date = '';

                // Match by exact first + last name ignoring case
                $matchId = null;
                $status = 'Unmatched';
                $cleanFirst = strtolower($firstName);
                $cleanLast = strtolower($lastName);

                foreach ($members as $m) {
                    if ($cleanFirst === strtolower($m['first_name']) && $cleanLast === strtolower($m['last_name'])) {
                        $matchId = $m['id'];
                        $status = 'Matched';
                        break;
                    }
                }

                // If not matched, see if any member has same last name as a hint
                if (!$matchId && $cleanLast !== '') {
                    foreach ($members as $m) {
                        if ($cleanLast === strtolower($m['last_name'])) {
                            $status = 'Needs Review';
                            break;
                        }
                    }
                }

                $stmtInsert->execute([
                    $campId,
                    $importedName,
                    $firstName,
                    $lastName,
                    $amount,
                    $transactionId,
                    $date,
                    $matchId,
                    json_encode($row),
                    $status
                ]);

                $count++;
                if ($matchId) $matched++;
            }

            $db->commit();
            error_log("IMPORT END reqId=$reqId count=$count matched=$matched skipped=$skipped");
            echo json_encode(['success' => true, 'count' => $count, 'matched' => $matched, 'skipped' => $skipped]);

        } catch (Exception $e) {
            $db->rollBack();
            error_log("IMPORT ERROR reqId=$reqId msg=".$e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function listPrepayments() {
        $campId = $_GET['camp_id'] ?? null;
        if (!$campId) {
            echo json_encode([]);
            return;
        }

        $db = Database::connect();
        // Join with members to get names; alias member names to avoid clobbering prepayment first_name/last_name
        $stmt = $db->prepare("
            SELECT p.*, m.first_name AS member_first_name, m.last_name AS member_last_name
            FROM prepayments p
            LEFT JOIN members m ON p.matched_member_id = m.id
            WHERE p.camp_id = ?
            ORDER BY p.id DESC
        ");
        $stmt->execute([$campId]);
        echo json_encode($stmt->fetchAll());
    }

    public function matchPrepayment() {
        $data = json_decode(file_get_contents('php://input'), true);
        $id = $data['id'] ?? null;
        $memberId = $data['member_id'] ?? null;

        if (!$id || !$memberId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing ID or Member ID']);
            return;
        }

        $db = Database::connect();
        try {
            $stmt = $db->prepare("UPDATE prepayments SET matched_member_id = ?, status = 'Matched' WHERE id = ?");
            $stmt->execute([$memberId, $id]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function importRates() {
        if (!isset($_FILES['file']) || !isset($_POST['camp_id'])) {
            echo json_encode(['success' => false, 'message' => 'File and Camp ID required']);
            return;
        }

        $campId = $_POST['camp_id'];
        $file = $_FILES['file']['tmp_name'];
        $handle = fopen($file, "r");
        
        // Header: Category, Item, User Type, Amount
        $header = fgetcsv($handle); 
        $count = 0;

        $db = Database::connect();
        $stmt = $db->prepare("INSERT INTO camp_rates (camp_id, category, item, user_type, amount) VALUES (?, ?, ?, ?, ?)");

        while (($row = fgetcsv($handle)) !== false) {
            // Flexible mapping or strict? 
            // Assert Order: Category(0), Item(1), User Type(2), Amount(3)
            if (count($row) < 4) continue;

            $category = trim($row[0]);
            $item = trim($row[1]);
            $userType = trim($row[2]);
            $amount = floatval(preg_replace('/[^0-9.]/', '', $row[3]));

            if ($category && $item) {
                $stmt->execute([$campId, $category, $item, $userType, $amount]);
                $count++;
            }
        }
        fclose($handle);
        echo json_encode(['success' => true, 'count' => $count]);
    }
}