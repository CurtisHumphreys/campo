<?php

require_once __DIR__ . '/../Database.php';

class PaymentController {
    public function store() {
        $data = json_decode(file_get_contents('php://input'), true);
        $db = Database::connect();
        $db->beginTransaction();

        try {
            // Check for duplicate payment (same member, amount, and time window)
            // This prevents double submissions from frontend lag or user error
            $checkDate = date('Y-m-d H:i:s', strtotime('-1 minute'));
            $stmtDup = $db->prepare("
                SELECT id FROM payments 
                WHERE member_id = ? 
                AND total = ? 
                AND created_at > ?
                ORDER BY id DESC LIMIT 1
            ");
            
            // Calculate total for check
            $campFee = isset($data['camp_fee']) ? (float)$data['camp_fee'] : 0.0;
            $siteFee = isset($data['site_fee']) ? (float)$data['site_fee'] : 0.0;
            $prepaidApplied = isset($data['prepaid_applied']) ? (float)$data['prepaid_applied'] : 0.0;
            $otherAmount = isset($data['other_amount']) ? (float)$data['other_amount'] : 0.0;
            $totalCheck = isset($data['total']) ? (float)$data['total'] : ($campFee + $siteFee - $prepaidApplied + $otherAmount);

            $stmtDup->execute([$data['member_id'], $totalCheck, $checkDate]);
            if ($stmtDup->fetch()) {
                $db->rollBack();
                echo json_encode(['success' => true, 'message' => 'Duplicate payment detected, ignored.', 'id' => null]); 
                return;
            }

            // Normalize numeric inputs with safe defaults
            $headcount = isset($data['headcount']) ? $data['headcount'] : null;
            $notesIn = isset($data['notes']) ? trim($data['notes']) : '';
            // Capture site_type safely. Default to null if missing or empty string.
            $siteType = (isset($data['site_type']) && $data['site_type'] !== '') ? $data['site_type'] : null; 
            
            // Capture concession (boolean 1/0 for DB)
            $concession = (isset($data['concession']) && ($data['concession'] === 'Yes' || $data['concession'] === true || $data['concession'] == 1)) ? 1 : 0;

            // Determine payment date (client provided or now)
            $clientDate = (isset($data['payment_date']) && !empty($data['payment_date'])) ? $data['payment_date'] : null;
            
            // Stay dates
            $arrivalDate = (isset($data['arrival_date']) && !empty($data['arrival_date'])) ? $data['arrival_date'] : null;
            $departureDate = (isset($data['departure_date']) && !empty($data['departure_date'])) ? $data['departure_date'] : null;

            // Calculate Tender Totals for Summary Columns
            $tenderEftpos = 0.0;
            $tenderCash = 0.0;
            $tenderCheque = 0.0;

            if (isset($data['tenders']) && is_array($data['tenders'])) {
                foreach ($data['tenders'] as $t) {
                    $amt = (float)($t['amount'] ?? 0);
                    $method = strtoupper($t['method'] ?? '');
                    if ($method === 'EFTPOS') $tenderEftpos += $amt;
                    elseif ($method === 'CASH') $tenderCash += $amt;
                    elseif ($method === 'CHEQUE') $tenderCheque += $amt;
                }
            }

            // Compute new site fee expiry and audit note if a site contribution is being made
            // Only calculate expiry extension if site fee is POSITIVE. Refunds shouldn't extend expiry.
            $newPaidUntilISO = null;
            $auditNote = '';
            if ($siteFee > 0) {
                $tz = new \DateTimeZone('Australia/Adelaide');
                $today = new \DateTime('now', $tz);
                $stmtCheck = $db->prepare("SELECT id, paid_until FROM site_fee_accounts WHERE member_id = ?");
                $stmtCheck->execute([$data['member_id']]);
                $accountRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                
                $baseDate = clone $today;
                if ($accountRow && !empty($accountRow['paid_until'])) {
                    $existing = new \DateTime($accountRow['paid_until'], $tz);
                    if ($existing > $today) {
                        $baseDate = $existing;
                    }
                }
                $yearsToAdd = 1;
                $newDate = clone $baseDate;
                $newDate->modify('+' . $yearsToAdd . ' year');
                $newPaidUntilISO = $newDate->format('Y-m-d');
                $newPaidUntilDisplay = $newDate->format('d/m/Y');

                $auditNote = sprintf('Site contribution: +%d year (%s%.2f). New paid until: %s',
                    $yearsToAdd,
                    '$',
                    $siteFee,
                    $newPaidUntilDisplay
                );
            }

            $finalNotes = $notesIn;
            if ($auditNote !== '') {
                $finalNotes .= ($finalNotes !== '' ? "\n" : '') . $auditNote;
            }

            // 1. Create Payment Record with Tenders and Site Type and Concession
            $stmt = $db->prepare(
                "INSERT INTO payments (
                    member_id, camp_id, site_id, payment_date,
                    camp_fee, site_fee, prepaid_applied, other_amount,
                    total, headcount, notes, arrival_date, departure_date,
                    site_type, tender_eftpos, tender_cash, tender_cheque, concession
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            
            $siteIdToStore = $data['site_id'] ?? null;
            if (!$siteIdToStore) {
                 $stmtAlloc = $db->prepare("SELECT site_id FROM site_allocations WHERE member_id = ? AND is_current = 1 LIMIT 1");
                 $stmtAlloc->execute([$data['member_id']]);
                 $alloc = $stmtAlloc->fetch(PDO::FETCH_ASSOC);
                 if ($alloc) {
                     $siteIdToStore = $alloc['site_id'];
                 }
            }

            $stmt->execute([
                $data['member_id'],
                $data['camp_id'] ?? null,
                $siteIdToStore,
                $clientDate ?? date('Y-m-d H:i:s'),
                $campFee,
                $siteFee,
                $prepaidApplied,
                $otherAmount,
                $totalCheck,
                $headcount,
                $finalNotes,
                $arrivalDate,
                $departureDate,
                $siteType,        
                $tenderEftpos,    
                $tenderCash,      
                $tenderCheque,
                $concession
            ]);

            $paymentId = $db->lastInsertId();

            // 2. Record Tenders (Detailed)
            if (isset($data['tenders']) && is_array($data['tenders'])) {
                $stmtTender = $db->prepare("INSERT INTO payment_tenders (payment_id, method, amount, reference) VALUES (?, ?, ?, ?)");
                foreach ($data['tenders'] as $tender) {
                    // Allow negative amounts for refunds
                    if (isset($tender['amount']) && $tender['amount'] != 0) {
                        $stmtTender->execute([
                            $paymentId,
                            $tender['method'],
                            $tender['amount'],
                            $tender['reference'] ?? ''
                        ]);
                    }
                }
            }

            // 3. Update Site Fee Account (Only if positive fee, handled above)
            if ($siteFee > 0 && $newPaidUntilISO !== null) {
                if ($accountRow) {
                    $stmtUpdate = $db->prepare("UPDATE site_fee_accounts SET paid_until = ?, status = 'Paid' WHERE id = ?");
                    $stmtUpdate->execute([$newPaidUntilISO, $accountRow['id']]);
                } else {
                    $stmtCreate = $db->prepare("INSERT INTO site_fee_accounts (member_id, paid_until, status) VALUES (?, ?, 'Paid')");
                    $stmtCreate->execute([$data['member_id'], $newPaidUntilISO]);
                }
                $stmtMem = $db->prepare("UPDATE members SET site_fee_status = 'Paid' WHERE id = ?");
                $stmtMem->execute([$data['member_id']]);
            }

            // 4. Update Prepayments (Only if positive usage)
            if ($prepaidApplied > 0 && isset($data['prepayment_ids']) && is_array($data['prepayment_ids'])) {
                $remaining = floatval($prepaidApplied);
                foreach ($data['prepayment_ids'] as $pid) {
                    if ($remaining <= 0) break;
                    $stmtPre = $db->prepare("SELECT id, amount FROM prepayments WHERE id = ? AND (status IS NULL OR status NOT IN ('Applied'))");
                    $stmtPre->execute([$pid]);
                    $pre = $stmtPre->fetch(PDO::FETCH_ASSOC);
                    if (!$pre) continue;
                    $preAmount = floatval($pre['amount']);
                    if ($preAmount <= 0) continue;
                    if ($preAmount > $remaining) {
                        $newAmount = $preAmount - $remaining;
                        $stmtUp = $db->prepare("UPDATE prepayments SET amount = ?, status = 'Partial' WHERE id = ?");
                        $stmtUp->execute([$newAmount, $pid]);
                        $remaining = 0;
                        break;
                    } else {
                        $remaining -= $preAmount;
                        $stmtUp = $db->prepare("UPDATE prepayments SET amount = 0, status = 'Applied' WHERE id = ?");
                        $stmtUp->execute([$pid]);
                    }
                }
            }

            $db->commit();
            echo json_encode(['success' => true, 'id' => $paymentId]);

        } catch (Exception $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function index() {
        $db = Database::connect();
        // Updated to select tender columns
        $sql = "
            SELECT 
                p.*, 
                m.first_name, 
                m.last_name,
                c.name as camp_name, 
                s.site_number
            FROM payments p
            LEFT JOIN members m ON p.member_id = m.id
            LEFT JOIN camps c ON p.camp_id = c.id
            LEFT JOIN sites s ON p.site_id = s.id
        ";

        $params = [];
        if (isset($_GET['search']) && !empty($_GET['search'])) {
            $term = $_GET['search'];
            $sql .= " WHERE COALESCE(m.first_name,'') LIKE ? OR COALESCE(m.last_name,'') LIKE ? OR COALESCE(p.notes,'') LIKE ?";
            $params[] = "%$term%";
            $params[] = "%$term%";
            $params[] = "%$term%";
        }

        $sql .= " ORDER BY p.payment_date DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll());
    }
    
    public function update($id) {
        $data = json_decode(file_get_contents('php://input'), true);
        $db = Database::connect();
        
        try {
            $stmt = $db->prepare("UPDATE payments SET 
                camp_fee = ?, 
                site_fee = ?, 
                total = ?, 
                notes = ?,
                payment_date = ?,
                arrival_date = ?,
                departure_date = ?
                WHERE id = ?");
            
            $stmt->execute([
                $data['camp_fee'],
                $data['site_fee'],
                $data['total'],
                $data['notes'],
                $data['payment_date'],
                $data['arrival_date'] ?? null,
                $data['departure_date'] ?? null,
                $id
            ]);
            
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function summary() {
        header('Content-Type: application/json');
        $db = Database::connect();
        $tz = new \DateTimeZone('Australia/Adelaide');
        $start = $_GET['start'] ?? null;
        $end   = $_GET['end'] ?? null;
        
        if ($start && $end) {
            $startDate = $start . ' 00:00:00';
            $endDate   = $end   . ' 23:59:59';
        } else {
            $today   = new \DateTime('now', $tz);
            $startDate = $today->format('Y-m-d') . ' 00:00:00';
            $endDate   = $today->format('Y-m-d') . ' 23:59:59';
        }

        // Aggregate totals for the selected date range
        $stmt = $db->prepare(
            "SELECT 
                SUM(pt.amount) AS total_revenue,
                SUM(CASE WHEN pt.method = 'EFTPOS' THEN pt.amount ELSE 0 END) AS eftpos,
                SUM(CASE WHEN pt.method = 'Cash' THEN pt.amount ELSE 0 END) AS cash,
                SUM(CASE WHEN pt.method = 'Cheque' THEN pt.amount ELSE 0 END) AS cheque,
                SUM(p.site_fee) AS site_contribution_total,
                SUM(p.camp_fee) AS camp_fee_total,
                COUNT(DISTINCT p.id) AS payment_count,
                SUM(p.headcount) AS headcount_total
            FROM payments p
            LEFT JOIN payment_tenders pt ON p.id = pt.payment_id
            WHERE p.payment_date BETWEEN ? AND ?"
        );
        $stmt->execute([$startDate, $endDate]);
        $stats = $stmt->fetch(\PDO::FETCH_ASSOC);

        echo json_encode([
            'total_revenue'          => $stats['total_revenue'] ?? 0,
            'eftpos'                => $stats['eftpos'] ?? 0,
            'cash'                  => $stats['cash'] ?? 0,
            'cheque'                => $stats['cheque'] ?? 0,
            'site_contribution_total' => $stats['site_contribution_total'] ?? 0,
            'camp_fee_total'        => $stats['camp_fee_total'] ?? 0,
            'payment_count'         => $stats['payment_count'] ?? 0,
            'headcount_total'       => $stats['headcount_total'] ?? 0
        ]);
    }

    public function dashboardStats() {
        header('Content-Type: application/json');
        $db = Database::connect();
        
        $campId = $_GET['camp_id'] ?? null;
        if (!$campId) {
            $camp = $db->query("SELECT id, start_date, end_date, name FROM camps WHERE status = 'Active' ORDER BY start_date DESC LIMIT 1")->fetch();
        } else {
            $stmt = $db->prepare("SELECT id, start_date, end_date, name FROM camps WHERE id = ?");
            $stmt->execute([$campId]);
            $camp = $stmt->fetch();
        }

        if (!$camp) {
            echo json_encode(['error' => 'No active camp found']);
            return;
        }

        $campStart = $camp['start_date'];
        $campEnd = $camp['end_date'];
        
        $sql = "SELECT p.headcount, p.arrival_date, p.departure_date, 
                       m.first_name, m.last_name, s.site_number
                FROM payments p
                JOIN members m ON p.member_id = m.id
                LEFT JOIN sites s ON p.site_id = s.id
                WHERE p.camp_id = ? 
                AND p.arrival_date IS NOT NULL 
                AND p.departure_date IS NOT NULL
                AND p.arrival_date != '0000-00-00'
                AND p.departure_date != '0000-00-00'";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$camp['id']]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $dailyStats = []; 
        $currentGuests = [];
        $tz = new \DateTimeZone('Australia/Adelaide');
        $todayStr = (new DateTime('now', $tz))->format('Y-m-d');

        try {
            $period = new DatePeriod(
                 new DateTime($campStart),
                 new DateInterval('P1D'),
                 (new DateTime($campEnd))->modify('+1 day')
            );

            foreach ($period as $dt) {
                $d = $dt->format('Y-m-d');
                $dailyStats[$d] = [
                    'date' => $d,
                    'headcount' => 0,
                    'site_ids' => [] 
                ];
            }
        } catch (Exception $e) { }

        foreach ($payments as $p) {
            $arr = $p['arrival_date'];
            $dep = $p['departure_date'];
            $hc = (int)$p['headcount'];
            $site = $p['site_number'] ?? 'Unassigned';

            if ($todayStr >= $arr && $todayStr < $dep) {
                $currentGuests[] = [
                    'name' => $p['first_name'] . ' ' . $p['last_name'],
                    'site' => $site,
                    'headcount' => $hc,
                    'until' => $dep
                ];
            }

            try {
                $stayStart = new DateTime($arr);
                $stayEnd = new DateTime($dep);
                
                $daysProcessed = 0;
                while ($stayStart < $stayEnd && $daysProcessed < 100) {
                    $ymd = $stayStart->format('Y-m-d');
                    if (isset($dailyStats[$ymd])) {
                        $dailyStats[$ymd]['headcount'] += $hc;
                        if ($site !== 'Unassigned' && !in_array($site, $dailyStats[$ymd]['site_ids'])) {
                            $dailyStats[$ymd]['site_ids'][] = $site;
                        }
                    }
                    $stayStart->modify('+1 day');
                    $daysProcessed++;
                }
            } catch (Exception $e) { continue; }
        }

        $labels = [];
        $dataHeadcount = [];
        $dataAvg = [];

        foreach ($dailyStats as $day) {
            $labels[] = date('d/m', strtotime($day['date']));
            $dataHeadcount[] = $day['headcount'];
            
            $siteCount = count($day['site_ids']);
            $avg = $siteCount > 0 ? round($day['headcount'] / $siteCount, 1) : 0;
            $dataAvg[] = $avg;
        }

        echo json_encode([
            'camp_name' => $camp['name'],
            'current_guests' => $currentGuests,
            'chart' => [
                'labels' => $labels,
                'headcount' => $dataHeadcount,
                'average' => $dataAvg
            ]
        ]);
    }

    public function delete($id) {
        $db = Database::connect();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("DELETE FROM payment_tenders WHERE payment_id = ?");
            $stmt->execute([$id]);
            
            $stmt = $db->prepare("DELETE FROM payments WHERE id = ?");
            $stmt->execute([$id]);
            
            $db->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}
