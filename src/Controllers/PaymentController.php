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
            // $campFee etc. calculated above for check
            
            $headcount = isset($data['headcount']) ? $data['headcount'] : null;
            $notesIn = isset($data['notes']) ? trim($data['notes']) : '';

            // Determine payment date (client provided or now)
            $clientDate = (isset($data['payment_date']) && !empty($data['payment_date'])) ? $data['payment_date'] : null;
            
            // Stay dates (New)
            $arrivalDate = (isset($data['arrival_date']) && !empty($data['arrival_date'])) ? $data['arrival_date'] : null;
            $departureDate = (isset($data['departure_date']) && !empty($data['departure_date'])) ? $data['departure_date'] : null;

            // Compute new site fee expiry and audit note if a site contribution is being made
            $newPaidUntilISO = null;
            $auditNote = '';
            if ($siteFee > 0) {
                // Determine base date using Adelaide timezone
                $tz = new \DateTimeZone('Australia/Adelaide');
                $today = new \DateTime('now', $tz);
                // Fetch current site fee account to get existing expiry
                $stmtCheck = $db->prepare("SELECT id, paid_until FROM site_fee_accounts WHERE member_id = ?");
                $stmtCheck->execute([$data['member_id']]);
                $accountRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                // Set base date as later of today or existing paid_until
                $baseDate = clone $today;
                if ($accountRow && !empty($accountRow['paid_until'])) {
                    $existing = new \DateTime($accountRow['paid_until'], $tz);
                    if ($existing > $today) {
                        $baseDate = $existing;
                    }
                }
                // Always add one year for each site contribution purchase
                $yearsToAdd = 1;
                $newDate = clone $baseDate;
                // Add years safely; using modify to handle leap years
                $newDate->modify('+' . $yearsToAdd . ' year');
                $newPaidUntilISO = $newDate->format('Y-m-d');
                $newPaidUntilDisplay = $newDate->format('d/m/Y');

                // Format audit note; include currency symbol and amount
                $auditNote = sprintf('Site contribution: +%d year (%s%.2f). New paid until: %s',
                    $yearsToAdd,
                    '$',
                    $siteFee,
                    $newPaidUntilDisplay
                );
            }

            // Combine user notes and audit note
            $finalNotes = $notesIn;
            if ($auditNote !== '') {
                $finalNotes .= ($finalNotes !== '' ? "\n" : '') . $auditNote;
            }

            // 1. Create Payment Record (Updated with arrival/departure)
            $stmt = $db->prepare(
                "INSERT INTO payments (
                    member_id, camp_id, site_id, payment_date,
                    camp_fee, site_fee, prepaid_applied, other_amount,
                    total, headcount, notes, arrival_date, departure_date
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            
            // Try to find site_id from allocation if not provided
            // This ensures we link the payment to a site if the member is allocated
            $siteIdToStore = $data['site_id'] ?? null;
            if (!$siteIdToStore) {
                // Look up current allocation
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
                $totalCheck, // Use calculated total
                $headcount,
                $finalNotes,
                $arrivalDate,
                $departureDate
            ]);

            $paymentId = $db->lastInsertId();

            // 2. Record Tenders
            if (isset($data['tenders']) && is_array($data['tenders'])) {
                $stmtTender = $db->prepare("INSERT INTO payment_tenders (payment_id, method, amount, reference) VALUES (?, ?, ?, ?)");
                foreach ($data['tenders'] as $tender) {
                    if (isset($tender['amount']) && $tender['amount'] > 0) {
                        $stmtTender->execute([
                            $paymentId,
                            $tender['method'],
                            $tender['amount'],
                            $tender['reference'] ?? ''
                        ]);
                    }
                }
            }

            // 3. Update Site Fee Account if a site contribution was included
            if ($siteFee > 0 && $newPaidUntilISO !== null) {
                // Check if site fee account already exists
                if ($accountRow) {
                    $stmtUpdate = $db->prepare("UPDATE site_fee_accounts SET paid_until = ?, status = 'Paid' WHERE id = ?");
                    $stmtUpdate->execute([$newPaidUntilISO, $accountRow['id']]);
                } else {
                    $stmtCreate = $db->prepare("INSERT INTO site_fee_accounts (member_id, paid_until, status) VALUES (?, ?, 'Paid')");
                    $stmtCreate->execute([$data['member_id'], $newPaidUntilISO]);
                }
                // Update member site_fee_status to Paid
                $stmtMem = $db->prepare("UPDATE members SET site_fee_status = 'Paid' WHERE id = ?");
                $stmtMem->execute([$data['member_id']]);
            }

            // 4. Update Prepayments if used
            if ($prepaidApplied > 0 && isset($data['prepayment_ids']) && is_array($data['prepayment_ids'])) {
                $remaining = floatval($prepaidApplied);
                foreach ($data['prepayment_ids'] as $pid) {
                    if ($remaining <= 0) break;
                    // Fetch current prepayment amount
                    $stmtPre = $db->prepare("SELECT id, amount FROM prepayments WHERE id = ? AND (status IS NULL OR status NOT IN ('Applied'))");
                    $stmtPre->execute([$pid]);
                    $pre = $stmtPre->fetch(PDO::FETCH_ASSOC);
                    if (!$pre) continue;
                    $preAmount = floatval($pre['amount']);
                    if ($preAmount <= 0) continue;
                    if ($preAmount > $remaining) {
                        // Partial usage: deduct remainder from this prepay and mark partial
                        $newAmount = $preAmount - $remaining;
                        $stmtUp = $db->prepare("UPDATE prepayments SET amount = ?, status = 'Partial' WHERE id = ?");
                        $stmtUp->execute([$newAmount, $pid]);
                        $remaining = 0;
                        break;
                    } else {
                        // Full usage: set amount to zero and mark applied
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
            // Always return JSON even on error
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
    public function index() {
        $db = Database::connect();
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

    /**
     * Legacy summary for reconciliation totals (cash/eftpos/etc)
     */
    public function summary() {
        header('Content-Type: application/json');
        $db = Database::connect();
        $tz = new \DateTimeZone('Australia/Adelaide');
        $start = $_GET['start'] ?? null;
        $end   = $_GET['end'] ?? null;
        
        // Default to today if no date provided
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

    /**
     * Detailed dashboard stats: Headcount over time & In-Camp-Now list.
     * Calculated based on payments' arrival/departure dates.
     * Updated to include ALL payments regardless of site allocation.
     */
    public function dashboardStats() {
        header('Content-Type: application/json');
        $db = Database::connect();
        
        // 1. Determine Date Range (Active Camp)
        // If camp_id passed, use it. Else find first active camp.
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
        
        // 2. Fetch Payments with Dates
        // Updated Query: LEFT JOIN sites to ensure unallocated payments are included.
        // We use the site_id stored in payments (which store() now tries to populate) 
        // OR fallback to site allocations if needed, but for simplicity relying on payments.
        
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

        // 3. Process Data
        $dailyStats = []; // 'YYYY-MM-DD' => ['headcount' => 0, 'site_ids' => []]
        $currentGuests = [];
        $tz = new \DateTimeZone('Australia/Adelaide');
        $todayStr = (new DateTime('now', $tz))->format('Y-m-d');

        // Init array for every day of camp
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
                    'site_ids' => [] // To count unique occupied sites
                ];
            }
        } catch (Exception $e) {
            // Handle invalid dates in camp definition
        }

        foreach ($payments as $p) {
            $arr = $p['arrival_date'];
            $dep = $p['departure_date'];
            $hc = (int)$p['headcount'];
            $site = $p['site_number'] ?? 'Unassigned';

            // Check if currently in camp (today is between arr (inclusive) and dep (exclusive))
            // Using >= arr and < dep logic (checkout day doesn't count as "in camp")
            if ($todayStr >= $arr && $todayStr < $dep) {
                $currentGuests[] = [
                    'name' => $p['first_name'] . ' ' . $p['last_name'],
                    'site' => $site,
                    'headcount' => $hc,
                    'until' => $dep
                ];
            }

            // Fill daily stats
            try {
                $stayStart = new DateTime($arr);
                $stayEnd = new DateTime($dep);
                
                // Limit loop to prevent infinite loops on bad data
                $daysProcessed = 0;
                while ($stayStart < $stayEnd && $daysProcessed < 100) {
                    $ymd = $stayStart->format('Y-m-d');
                    if (isset($dailyStats[$ymd])) {
                        $dailyStats[$ymd]['headcount'] += $hc;
                        // Track unique sites to calculate average density
                        // Only count actual sites, not unassigned
                        if ($site !== 'Unassigned' && !in_array($site, $dailyStats[$ymd]['site_ids'])) {
                            $dailyStats[$ymd]['site_ids'][] = $site;
                        }
                    }
                    $stayStart->modify('+1 day');
                    $daysProcessed++;
                }
            } catch (Exception $e) { continue; }
        }

        // Prepare Chart Data
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
            // Delete tenders
            $stmt = $db->prepare("DELETE FROM payment_tenders WHERE payment_id = ?");
            $stmt->execute([$id]);
            
            // Delete payment
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