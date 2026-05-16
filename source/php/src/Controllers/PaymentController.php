<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../MemberHouseholdService.php';
require_once __DIR__ . '/../SiteFeeService.php';

class PaymentController {
    // ... (store, index, update, delete, dashboardStats methods remain unchanged) ...

    private function findDashboardCamp(PDO $db, $campId = null) {
        if ($campId) {
            $stmt = $db->prepare("
                SELECT id, name, year, status, start_date, end_date,
                       churchsuite_event_id, churchsuite_event_identifier, churchsuite_event_name,
                       churchsuite_last_sync_at, churchsuite_last_sync_status, churchsuite_last_sync_message
                FROM camps
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([(int)$campId]);
            $camp = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($camp) {
                return $camp;
            }
        }

        $stmt = $db->query("
            SELECT id, name, year, status, start_date, end_date,
                   churchsuite_event_id, churchsuite_event_identifier, churchsuite_event_name,
                   churchsuite_last_sync_at, churchsuite_last_sync_status, churchsuite_last_sync_message
            FROM camps
            ORDER BY (LOWER(COALESCE(status, '')) = 'active') DESC, start_date DESC, id DESC
            LIMIT 1
        ");
        return $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: null) : null;
    }

    private function tableExists(PDO $db, $table) {
        static $cache = [];
        $key = strtolower((string)$table);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $stmt = $db->query("SHOW TABLES LIKE " . $db->quote($table));
        $cache[$key] = (bool)$stmt->fetch(PDO::FETCH_NUM);
        return $cache[$key];
    }

    private function columnExists(PDO $db, $table, $column) {
        static $cache = [];
        $key = strtolower($table . '.' . $column);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $stmt = $db->query("SHOW COLUMNS FROM `" . str_replace('`', '``', $table) . "` LIKE " . $db->quote($column));
        $cache[$key] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        return $cache[$key];
    }

    private function scalarCount(PDO $db, $sql, array $params = []) {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    private function fetchAvailablePrepaymentsForPayment(PDO $db, $memberId, $campId, array $prepaymentIds = []) {
        if ($this->tableExists($db, 'member_household_members') && $this->tableExists($db, 'member_households')) {
            $service = new MemberHouseholdService($db);
            $rows = $service->getHouseholdAvailablePrepayments((int)$memberId, $campId ? (int)$campId : null);
        } else {
            $sql = "
                SELECT
                    p.*,
                    owner.first_name AS owner_first_name,
                    owner.last_name AS owner_last_name
                FROM prepayments p
                LEFT JOIN members owner ON owner.id = p.matched_member_id
                WHERE p.matched_member_id = ?
                  AND COALESCE(p.amount, 0) > 0
            ";
            $params = [(int)$memberId];
            if ($campId) {
                $sql .= " AND p.camp_id = ?";
                $params[] = (int)$campId;
            }
            $sql .= " ORDER BY p.id ASC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($prepaymentIds) {
            $allowed = array_fill_keys(array_map('intval', $prepaymentIds), true);
            $rows = array_values(array_filter($rows, function ($row) use ($allowed) {
                return isset($allowed[(int)($row['id'] ?? 0)]);
            }));
        }

        usort($rows, function ($a, $b) use ($memberId) {
            $ownerA = (int)($a['matched_member_id'] ?? 0);
            $ownerB = (int)($b['matched_member_id'] ?? 0);
            $rankA = $ownerA === (int)$memberId ? 0 : 1;
            $rankB = $ownerB === (int)$memberId ? 0 : 1;
            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }
            return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
        });

        return $rows;
    }

    private function appendPaymentNotes(PDO $db, $paymentId, array $notes) {
        $notes = array_values(array_filter(array_map('trim', $notes)));
        if (!$notes) {
            return;
        }

        $stmt = $db->prepare("SELECT notes FROM payments WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$paymentId]);
        $existingNotes = trim((string)$stmt->fetchColumn());
        $combined = $existingNotes;
        foreach ($notes as $note) {
            if ($note === '') {
                continue;
            }
            if ($combined !== '' && strpos($combined, $note) !== false) {
                continue;
            }
            $combined .= ($combined !== '' ? "\n" : '') . $note;
        }

        $update = $db->prepare("UPDATE payments SET notes = ? WHERE id = ?");
        $update->execute([$combined, (int)$paymentId]);
    }

    private function siteFeeService(PDO $db): SiteFeeService
    {
        return new SiteFeeService($db);
    }

    private function resolveSiteFeeMonths(SiteFeeService $siteFeeService, array $data, float $siteFee, bool $concession): int
    {
        $months = isset($data['site_fee_months']) ? (int)$data['site_fee_months'] : 0;
        if ($months > 0) {
            return $months;
        }

        if ($siteFee <= 0) {
            return 0;
        }

        return $siteFeeService->inferMonthsFromContext(
            $siteFee,
            isset($data['camp_id']) ? (int)$data['camp_id'] : 0,
            $concession
        );
    }

    private function buildSiteFeeAuditNote(float $siteFee, int $months, ?string $newPaidUntilISO): string
    {
        if ($siteFee <= 0 || $months <= 0 || $newPaidUntilISO === null) {
            return '';
        }

        try {
            $displayDate = (new DateTimeImmutable($newPaidUntilISO, new DateTimeZone('Australia/Adelaide')))->format('d/m/Y');
        } catch (Throwable $e) {
            $displayDate = $newPaidUntilISO;
        }

        $monthLabel = $months === 1 ? 'month' : 'months';
        return sprintf(
            'Site contribution: +%d %s ($%.2f). New paid until: %s',
            $months,
            $monthLabel,
            $siteFee,
            $displayDate
        );
    }

    private function buildPaymentAllocationSummary(array $rows, $includeDaily = true) {
        $totals = [
            'revenue' => 0.0,
            'eftpos' => 0.0,
            'cash' => 0.0,
            'cheque' => 0.0,
            'prepaid' => 0.0,
            'count' => count($rows)
        ];

        $camp = [
            'total' => 0.0,
            'eftpos' => 0.0,
            'cash' => 0.0,
            'cheque' => 0.0,
            'prepaid' => 0.0
        ];

        $site = [
            'total' => 0.0,
            'eftpos' => 0.0,
            'cash' => 0.0,
            'cheque' => 0.0,
            'prepaid' => 0.0
        ];

        $daily = [];

        foreach ($rows as $row) {
            $date = substr((string)($row['payment_date'] ?? ''), 0, 10);
            if ($includeDaily && $date !== '' && !isset($daily[$date])) {
                $daily[$date] = [
                    'total' => ['revenue' => 0.0, 'eftpos' => 0.0, 'cash' => 0.0, 'cheque' => 0.0, 'prepaid' => 0.0],
                    'camp' => ['total' => 0.0, 'eftpos' => 0.0, 'cash' => 0.0, 'cheque' => 0.0, 'prepaid' => 0.0],
                    'site' => ['total' => 0.0, 'eftpos' => 0.0, 'cash' => 0.0, 'cheque' => 0.0, 'prepaid' => 0.0]
                ];
            }

            $tCash = (float)($row['tender_cash'] ?? 0);
            $tEft = (float)($row['tender_eftpos'] ?? 0);
            $tChq = (float)($row['tender_cheque'] ?? 0);
            $tPre = (float)($row['prepaid_applied'] ?? 0);
            $cFee = (float)($row['camp_fee'] ?? 0);
            $sFee = (float)($row['site_fee'] ?? 0);
            $totalRow = (float)($row['total'] ?? 0);

            $totals['revenue'] += $totalRow;
            $totals['eftpos'] += $tEft;
            $totals['cash'] += $tCash;
            $totals['cheque'] += $tChq;
            $totals['prepaid'] += $tPre;

            if ($includeDaily && $date !== '') {
                $daily[$date]['total']['revenue'] += $totalRow;
                $daily[$date]['total']['eftpos'] += $tEft;
                $daily[$date]['total']['cash'] += $tCash;
                $daily[$date]['total']['cheque'] += $tChq;
                $daily[$date]['total']['prepaid'] += $tPre;
            }

            $camp['total'] += $cFee;
            if ($includeDaily && $date !== '') {
                $daily[$date]['camp']['total'] += $cFee;
            }

            $tempCFee = $cFee;
            $tempSFee = $sFee;

            $preToCamp = min($tempCFee, $tPre);
            $camp['prepaid'] += $preToCamp;
            if ($includeDaily && $date !== '') {
                $daily[$date]['camp']['prepaid'] += $preToCamp;
            }
            $tempCFee -= $preToCamp;
            $tPre -= $preToCamp;

            $preToSite = min($tempSFee, $tPre);
            $tempSFee -= $preToSite;
            $tPre -= $preToSite;

            $cashToCamp = min($tempCFee, $tCash);
            $camp['cash'] += $cashToCamp;
            if ($includeDaily && $date !== '') {
                $daily[$date]['camp']['cash'] += $cashToCamp;
            }
            $tempCFee -= $cashToCamp;
            $tCash -= $cashToCamp;

            $cashToSite = min($tempSFee, $tCash);
            $site['cash'] += $cashToSite;
            $site['total'] += $cashToSite;
            if ($includeDaily && $date !== '') {
                $daily[$date]['site']['cash'] += $cashToSite;
                $daily[$date]['site']['total'] += $cashToSite;
            }
            $tempSFee -= $cashToSite;
            $tCash -= $cashToSite;

            $eftToCamp = min($tempCFee, $tEft);
            $camp['eftpos'] += $eftToCamp;
            if ($includeDaily && $date !== '') {
                $daily[$date]['camp']['eftpos'] += $eftToCamp;
            }
            $tempCFee -= $eftToCamp;
            $tEft -= $eftToCamp;

            $eftToSite = min($tempSFee, $tEft);
            $site['eftpos'] += $eftToSite;
            $site['total'] += $eftToSite;
            if ($includeDaily && $date !== '') {
                $daily[$date]['site']['eftpos'] += $eftToSite;
                $daily[$date]['site']['total'] += $eftToSite;
            }
            $tempSFee -= $eftToSite;
            $tEft -= $eftToSite;

            $chqToCamp = min($tempCFee, $tChq);
            $camp['cheque'] += $chqToCamp;
            if ($includeDaily && $date !== '') {
                $daily[$date]['camp']['cheque'] += $chqToCamp;
            }
            $tempCFee -= $chqToCamp;
            $tChq -= $chqToCamp;

            $chqToSite = min($tempSFee, $tChq);
            $tempSFee -= $chqToSite;
        }

        if ($includeDaily) {
            ksort($daily);
        }

        return [
            'total' => $totals,
            'camp' => $camp,
            'site' => $site,
            'daily' => $daily
        ];
    }

    private function projectionRowScore(array $row) {
        $score = 0.0;
        foreach (['source_arrival_date', 'source_departure_date', 'source_party_size', 'source_day_trip'] as $field) {
            if (!empty($row[$field]) && (string)$row[$field] !== '0') {
                $score += 10;
            }
        }
        $score += (float)($row['effective_amount'] ?? 0);
        return $score;
    }

    private function buildProjectedPrepaymentStats(PDO $db, array $camp) {
        $projection = [
            'labels' => [],
            'headcount' => [],
            'dated_booking_count' => 0,
            'total_booking_count' => 0,
            'missing_dates_count' => 0,
            'defaulted_party_count' => 0,
            'peak_headcount' => 0,
            'peak_date' => null
        ];

        $campStart = $camp['start_date'] ?? null;
        $campEnd = $camp['end_date'] ?? null;
        if (!$campStart || !$campEnd || !$this->tableExists($db, 'prepayments')) {
            return $projection;
        }

        $requiredColumns = ['source_system', 'source_arrival_date', 'source_departure_date'];
        foreach ($requiredColumns as $column) {
            if (!$this->columnExists($db, 'prepayments', $column)) {
                return $projection;
            }
        }

        $hasSourceAmount = $this->columnExists($db, 'prepayments', 'source_amount');
        $hasPartySize = $this->columnExists($db, 'prepayments', 'source_party_size');
        $hasDayTrip = $this->columnExists($db, 'prepayments', 'source_day_trip');
        $hasSourceRecordId = $this->columnExists($db, 'prepayments', 'source_record_id');
        $hasTransactionId = $this->columnExists($db, 'prepayments', 'transaction_id');

        $dailyStats = [];
        try {
            $period = new \DatePeriod(
                new \DateTime($campStart),
                new \DateInterval('P1D'),
                (new \DateTime($campEnd))->modify('+1 day')
            );

            foreach ($period as $dt) {
                $d = $dt->format('Y-m-d');
                $dailyStats[$d] = 0;
            }
        } catch (\Exception $e) {
            return $projection;
        }

        $effectiveAmountExpr = $hasSourceAmount
            ? "CASE WHEN COALESCE(source_amount, 0) > 0 THEN source_amount ELSE COALESCE(amount, 0) END"
            : "COALESCE(amount, 0)";

        $sql = "
            SELECT
                id,
                " . ($hasSourceRecordId ? "source_record_id" : "NULL AS source_record_id") . ",
                " . ($hasTransactionId ? "transaction_id" : "NULL AS transaction_id") . ",
                source_arrival_date,
                source_departure_date,
                " . ($hasPartySize ? "source_party_size" : "NULL AS source_party_size") . ",
                " . ($hasDayTrip ? "source_day_trip" : "0 AS source_day_trip") . ",
                {$effectiveAmountExpr} AS effective_amount
            FROM prepayments
            WHERE camp_id = ?
              AND COALESCE(source_system, '') = 'churchsuite'
              AND {$effectiveAmountExpr} > 0
            ORDER BY id DESC
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([(int)$camp['id']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $deduped = [];
        foreach ($rows as $row) {
            $sourceRecordId = trim((string)($row['source_record_id'] ?? ''));
            $transactionId = trim((string)($row['transaction_id'] ?? ''));
            if ($sourceRecordId !== '') {
                $key = 'source:' . $sourceRecordId;
            } elseif ($transactionId !== '') {
                $key = 'txn:' . $transactionId;
            } else {
                $key = 'row:' . (int)($row['id'] ?? 0);
            }

            if (!isset($deduped[$key]) || $this->projectionRowScore($row) > $this->projectionRowScore($deduped[$key])) {
                $deduped[$key] = $row;
            }
        }

        $projection['total_booking_count'] = count($deduped);

        foreach ($deduped as $row) {
            $arrival = substr(trim((string)($row['source_arrival_date'] ?? '')), 0, 10);
            $departure = substr(trim((string)($row['source_departure_date'] ?? '')), 0, 10);

            if ($arrival === '' || $departure === '' || $arrival === '0000-00-00' || $departure === '0000-00-00') {
                $projection['missing_dates_count']++;
                continue;
            }

            try {
                $stayStart = new \DateTime($arrival);
                $stayEnd = new \DateTime($departure);
            } catch (\Exception $e) {
                $projection['missing_dates_count']++;
                continue;
            }

            $projection['dated_booking_count']++;

            $partySize = (int)($row['source_party_size'] ?? 0);
            if ($partySize <= 0) {
                $partySize = 1;
                $projection['defaulted_party_count']++;
            }

            $isDayTrip = !empty($row['source_day_trip']) && (int)$row['source_day_trip'] === 1;
            if ($stayEnd <= $stayStart || $isDayTrip) {
                $stayEnd = (clone $stayStart)->modify('+1 day');
            }

            $daysProcessed = 0;
            while ($stayStart < $stayEnd && $daysProcessed < 100) {
                $ymd = $stayStart->format('Y-m-d');
                if (array_key_exists($ymd, $dailyStats)) {
                    $dailyStats[$ymd] += $partySize;
                }
                $stayStart->modify('+1 day');
                $daysProcessed++;
            }
        }

        foreach ($dailyStats as $date => $headcount) {
            $projection['labels'][] = date('d/m', strtotime($date));
            $projection['headcount'][] = $headcount;
            if ($headcount > $projection['peak_headcount']) {
                $projection['peak_headcount'] = $headcount;
                $projection['peak_date'] = $date;
            }
        }

        return $projection;
    }

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
            $sourceCheckinId = isset($data['source_checkin_id']) && (int)$data['source_checkin_id'] > 0
                ? (int)$data['source_checkin_id']
                : null;
            
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

            $siteFeeService = $this->siteFeeService($db);
            $paymentColumnSupport = $siteFeeService->getPaymentsTableColumns();

            // Compute the next site-fee due date from the current stored due date, not from the
            // literal payment day. This keeps fee periods anchored cleanly for overdue cases.
            $siteFeeMonths = $this->resolveSiteFeeMonths($siteFeeService, $data, $siteFee, $concession === 1);
            $newPaidUntilISO = null;
            if ($siteFee > 0 && $siteFeeMonths > 0) {
                $newPaidUntilISO = $siteFeeService->calculateNextDue(
                    $siteFeeService->getCurrentDueDate((int)$data['member_id']),
                    $clientDate ?? date('Y-m-d H:i:s'),
                    $siteFeeMonths
                );
            } elseif ($siteFee > 0 && !empty($data['site_fee_paid_until'])) {
                $newPaidUntilISO = $siteFeeService->normalizeDate($data['site_fee_paid_until']);
            }

            $auditNote = $this->buildSiteFeeAuditNote($siteFee, $siteFeeMonths, $newPaidUntilISO);

            $finalNotes = $notesIn;
            if ($auditNote !== '') {
                $finalNotes .= ($finalNotes !== '' ? "\n" : '') . $auditNote;
            }

            // 1. Create Payment Record with Tenders and Site Type and Concession
            $siteIdToStore = $data['site_id'] ?? null;
            if (!$siteIdToStore) {
                 $stmtAlloc = $db->prepare("SELECT site_id FROM site_allocations WHERE member_id = ? AND is_current = 1 LIMIT 1");
                 $stmtAlloc->execute([$data['member_id']]);
                 $alloc = $stmtAlloc->fetch(PDO::FETCH_ASSOC);
                 if ($alloc) {
                     $siteIdToStore = $alloc['site_id'];
                 }
            }

            $paymentColumns = [
                'member_id', 'camp_id', 'site_id', 'payment_date',
                'camp_fee', 'site_fee', 'prepaid_applied', 'other_amount',
                'total', 'headcount', 'notes', 'arrival_date', 'departure_date',
                'site_type', 'tender_eftpos', 'tender_cash', 'tender_cheque', 'concession'
            ];
            $paymentParams = [
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
            ];

            if ($paymentColumnSupport['site_fee_months']) {
                $paymentColumns[] = 'site_fee_months';
                $paymentParams[] = $siteFeeMonths > 0 ? $siteFeeMonths : null;
            }

            if ($paymentColumnSupport['site_fee_paid_until']) {
                $paymentColumns[] = 'site_fee_paid_until';
                $paymentParams[] = $newPaidUntilISO;
            }

            if ($sourceCheckinId !== null && $this->columnExists($db, 'payments', 'source_checkin_id')) {
                $paymentColumns[] = 'source_checkin_id';
                $paymentParams[] = $sourceCheckinId;
            }

            $placeholders = implode(', ', array_fill(0, count($paymentColumns), '?'));
            $stmt = $db->prepare(
                "INSERT INTO payments (" . implode(', ', $paymentColumns) . ") VALUES ($placeholders)"
            );
            $stmt->execute($paymentParams);

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

            // 3. Update Prepayments (Only if positive usage)
            if ($prepaidApplied > 0 && isset($data['prepayment_ids']) && is_array($data['prepayment_ids'])) {
                $remaining = floatval($prepaidApplied);
                $availablePrepayments = $this->fetchAvailablePrepaymentsForPayment(
                    $db,
                    (int)$data['member_id'],
                    $data['camp_id'] ?? null,
                    $data['prepayment_ids']
                );
                $allocationNotes = [];
                $allocationStmt = null;
                if ($this->tableExists($db, 'payment_prepayment_allocations')) {
                    $allocationStmt = $db->prepare("
                        INSERT INTO payment_prepayment_allocations (
                            payment_id,
                            prepayment_id,
                            source_member_id,
                            applied_to_member_id,
                            amount_applied,
                            notes
                        ) VALUES (?, ?, ?, ?, ?, ?)
                    ");
                }

                foreach ($availablePrepayments as $pre) {
                    if ($remaining <= 0) {
                        break;
                    }

                    $preAmount = floatval($pre['amount'] ?? 0);
                    if ($preAmount <= 0) {
                        continue;
                    }

                    $applyAmount = min($preAmount, $remaining);
                    $newAmount = round($preAmount - $applyAmount, 2);
                    $newStatus = $newAmount <= 0 ? 'Applied' : 'Partial';

                    $stmtUp = $db->prepare("UPDATE prepayments SET amount = ?, status = ? WHERE id = ?");
                    $stmtUp->execute([$newAmount, $newStatus, (int)$pre['id']]);

                    $note = null;
                    $sourceMemberId = !empty($pre['matched_member_id']) ? (int)$pre['matched_member_id'] : null;
                    if ($sourceMemberId && $sourceMemberId !== (int)$data['member_id']) {
                        $sourceName = trim((string)($pre['owner_first_name'] ?? '') . ' ' . (string)($pre['owner_last_name'] ?? ''));
                        $targetName = trim((string)($data['member_name'] ?? ''));
                        if ($targetName === '') {
                            $targetName = trim((string)($data['member_first_name'] ?? '') . ' ' . (string)($data['member_last_name'] ?? ''));
                        }
                        $note = 'Household credit applied from ' . ($sourceName !== '' ? $sourceName : ('member ID ' . $sourceMemberId)) . '.';
                        $allocationNotes[] = $note;
                    }

                    if ($allocationStmt) {
                        $allocationStmt->execute([
                            (int)$paymentId,
                            (int)$pre['id'],
                            $sourceMemberId ?: null,
                            (int)$data['member_id'],
                            $applyAmount,
                            $note
                        ]);
                    }

                    $remaining = round($remaining - $applyAmount, 2);
                }

                if ($allocationNotes) {
                    $this->appendPaymentNotes($db, (int)$paymentId, $allocationNotes);
                }
            }

            if ($sourceCheckinId !== null && $this->tableExists($db, 'camp_intranet_checkins')) {
                $stmtCheckIn = $db->prepare("
                    UPDATE camp_intranet_checkins
                    SET status = 'resolved',
                        applied_payment_id = ?,
                        applied_member_id = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmtCheckIn->execute([(int)$paymentId, (int)$data['member_id'], $sourceCheckinId]);
            }

            if ($siteFee > 0) {
                $siteFeeService->recalculateMemberAccountFromPayments((int)$data['member_id']);
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
                c.year as camp_year,
                s.site_number
            FROM payments p
            LEFT JOIN members m ON p.member_id = m.id
            LEFT JOIN camps c ON p.camp_id = c.id
            LEFT JOIN sites s ON p.site_id = s.id
        ";

        $params = [];
        $conditions = [];

        if (isset($_GET['camp_id']) && $_GET['camp_id'] !== '') {
            $conditions[] = "p.camp_id = ?";
            $params[] = (int)$_GET['camp_id'];
        }

        if (isset($_GET['search']) && !empty($_GET['search'])) {
            $term = $_GET['search'];
            $conditions[] = "(COALESCE(m.first_name,'') LIKE ? OR COALESCE(m.last_name,'') LIKE ? OR COALESCE(m.email,'') LIKE ? OR COALESCE(m.mobile,'') LIKE ? OR COALESCE(m.phone,'') LIKE ? OR COALESCE(p.notes,'') LIKE ?)";
            $params[] = "%$term%";
            $params[] = "%$term%";
            $params[] = "%$term%";
            $params[] = "%$term%";
            $params[] = "%$term%";
            $params[] = "%$term%";
        }

        if ($conditions) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }

        $sql .= " ORDER BY p.payment_date DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll());
    }

    public function checkInRecords() {
        header('Content-Type: application/json');
        try {
            $db = Database::connect();

            if (!$this->tableExists($db, 'camp_intranet_checkins')) {
                echo json_encode([]);
                return;
            }

            $householdSelect = '';
            $householdJoin = '';
            $householdSearchColumn = '';
            if ($this->tableExists($db, 'member_households')) {
                $householdColumn = null;
                if ($this->columnExists($db, 'member_households', 'display_name')) {
                    $householdColumn = 'display_name';
                } elseif ($this->columnExists($db, 'member_households', 'household_name')) {
                    $householdColumn = 'household_name';
                }

                if ($householdColumn !== null) {
                    $householdSelect = ", hh.{$householdColumn} AS matched_household_name";
                    $householdJoin = " LEFT JOIN member_households hh ON hh.id = ci.matched_household_id";
                    $householdSearchColumn = "COALESCE(hh.{$householdColumn}, '') LIKE ?";
                }
            }

            $sql = "
                SELECT
                    ci.*,
                    c.name AS camp_name,
                    c.year AS camp_year,
                    mm.first_name AS matched_member_first_name,
                    mm.last_name AS matched_member_last_name,
                    am.first_name AS applied_member_first_name,
                    am.last_name AS applied_member_last_name
                    {$householdSelect}
                FROM camp_intranet_checkins ci
                LEFT JOIN camps c ON c.id = ci.camp_id
                LEFT JOIN members mm ON mm.id = ci.matched_member_id
                LEFT JOIN members am ON am.id = ci.applied_member_id
                {$householdJoin}
            ";

            $params = [];
            $conditions = [];

            if (isset($_GET['camp_id']) && $_GET['camp_id'] !== '') {
                $conditions[] = "ci.camp_id = ?";
                $params[] = (int)$_GET['camp_id'];
            }

            if (isset($_GET['search']) && trim((string)$_GET['search']) !== '') {
                $term = '%' . trim((string)$_GET['search']) . '%';
                $searchConditions = [
                    "COALESCE(ci.submitter_name, '') LIKE ?",
                    "COALESCE(ci.site_number, '') LIKE ?",
                    "COALESCE(ci.phone_number, '') LIKE ?",
                    "COALESCE(ci.email, '') LIKE ?",
                    "COALESCE(ci.site_type, '') LIKE ?",
                    "COALESCE(ci.verification_note, '') LIKE ?",
                    "COALESCE(mm.first_name, '') LIKE ?",
                    "COALESCE(mm.last_name, '') LIKE ?",
                    "COALESCE(am.first_name, '') LIKE ?",
                    "COALESCE(am.last_name, '') LIKE ?"
                ];

                if ($householdSearchColumn !== '') {
                    $searchConditions[] = $householdSearchColumn;
                }

                $conditions[] = '(' . implode(' OR ', $searchConditions) . ')';
                $searchParamCount = count($searchConditions);
                for ($i = 0; $i < $searchParamCount; $i++) {
                    $params[] = $term;
                }
            }

            if ($conditions) {
                $sql .= ' WHERE ' . implode(' AND ', $conditions);
            }

            $sql .= "
                ORDER BY
                    FIELD(ci.status, 'new', 'in_progress', 'resolved', 'archived'),
                    ci.created_at DESC,
                    ci.id DESC
            ";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to load check-in records.',
                'error' => $e->getMessage()
            ]);
        }
    }

    public function siteFeeAudit()
    {
        header('Content-Type: application/json');
        try {
            $db = Database::connect();
            $rows = $this->siteFeeService($db)->getAuditRows();
            echo json_encode($rows);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to load site fee audit.',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function siteFeeAuditRecalculate($memberId)
    {
        header('Content-Type: application/json');
        $db = Database::connect();
        $db->beginTransaction();

        try {
            $result = $this->siteFeeService($db)->recalculateMemberAccountFromPayments((int)$memberId);
            $db->commit();
            echo json_encode(['success' => true, 'result' => $result]);
        } catch (Throwable $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function siteFeeAuditApplyExpected($memberId)
    {
        header('Content-Type: application/json');
        $db = Database::connect();
        $db->beginTransaction();

        try {
            $service = $this->siteFeeService($db);
            $preview = $service->buildRecalculationPreview((int)$memberId);
            if (empty($preview['expected_due'])) {
                throw new RuntimeException('No expected due date could be calculated from payments.');
            }

            $result = $service->setCustomDueDate((int)$memberId, (string)$preview['expected_due']);
            $db->commit();
            echo json_encode(['success' => true, 'result' => $result]);
        } catch (Throwable $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function siteFeeAuditSetCustom($memberId)
    {
        header('Content-Type: application/json');
        $db = Database::connect();
        $db->beginTransaction();
        $data = json_decode(file_get_contents('php://input'), true) ?: [];

        try {
            $dueDate = trim((string)($data['due_date'] ?? ''));
            $result = $this->siteFeeService($db)->setCustomDueDate((int)$memberId, $dueDate);
            $db->commit();
            echo json_encode(['success' => true, 'result' => $result]);
        } catch (Throwable $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function siteFeeAuditReview($memberId)
    {
        header('Content-Type: application/json');
        $db = Database::connect();
        $data = json_decode(file_get_contents('php://input'), true) ?: [];

        try {
            $signature = trim((string)($data['signature'] ?? ''));
            $reviewStatus = trim((string)($data['review_status'] ?? ''));
            if ($signature === '' || $reviewStatus === '') {
                throw new InvalidArgumentException('Missing review details.');
            }

            $this->siteFeeService($db)->markAuditStatus(
                (int)$memberId,
                !empty($data['site_id']) ? (int)$data['site_id'] : null,
                $signature,
                $reviewStatus,
                $data['notes'] ?? null
            );

            echo json_encode(['success' => true]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
    
    public function update($id) {
        $data = json_decode(file_get_contents('php://input'), true);
        $db = Database::connect();
        
        try {
            $db->beginTransaction();
            $paymentStmt = $db->prepare("SELECT * FROM payments WHERE id = ? LIMIT 1");
            $paymentStmt->execute([(int)$id]);
            $existing = $paymentStmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                throw new RuntimeException('Payment record not found.');
            }

            $service = $this->siteFeeService($db);
            $columns = $service->getPaymentsTableColumns();

            $fields = [
                'camp_fee = ?',
                'site_fee = ?',
                'total = ?',
                'notes = ?',
                'payment_date = ?',
                'arrival_date = ?',
                'departure_date = ?'
            ];
            $params = [
                $data['camp_fee'],
                $data['site_fee'],
                $data['total'],
                $data['notes'],
                $data['payment_date'],
                $data['arrival_date'] ?? null,
                $data['departure_date'] ?? null,
            ];

            if ($columns['site_fee_months']) {
                $fields[] = 'site_fee_months = ?';
                $params[] = isset($data['site_fee_months']) && (int)$data['site_fee_months'] > 0
                    ? (int)$data['site_fee_months']
                    : null;
            }

            if ($columns['site_fee_paid_until']) {
                $fields[] = 'site_fee_paid_until = ?';
                $params[] = !empty($data['site_fee_paid_until'])
                    ? $service->normalizeDate($data['site_fee_paid_until'])
                    : null;
            }

            $params[] = (int)$id;
            $stmt = $db->prepare("UPDATE payments SET " . implode(', ', $fields) . " WHERE id = ?");
            $stmt->execute($params);

            if ((float)($existing['site_fee'] ?? 0) > 0 || (float)($data['site_fee'] ?? 0) > 0) {
                $service->recalculateMemberAccountFromPayments((int)$existing['member_id']);
            }
            
            $db->commit();
            
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
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
        $campId = isset($_GET['camp_id']) && $_GET['camp_id'] !== '' ? (int)$_GET['camp_id'] : null;

        if ($campId && (!$start || !$end)) {
            $campStmt = $db->prepare("SELECT start_date, end_date, name FROM camps WHERE id = ? LIMIT 1");
            $campStmt->execute([$campId]);
            $camp = $campStmt->fetch(PDO::FETCH_ASSOC);
            if ($camp) {
                $start = $start ?: ($camp['start_date'] ?? null);
                $end = $end ?: ($camp['end_date'] ?? null);
            }
        }
        
        if ($start && $end) {
            $startDate = $start . ' 00:00:00';
            $endDate   = $end   . ' 23:59:59';
        } else {
            $today   = new \DateTime('now', $tz);
            $startDate = $today->format('Y-m-d') . ' 00:00:00';
            $endDate   = $today->format('Y-m-d') . ' 23:59:59';
        }

        // Fetch Individual Transaction Rows to apply "Cash First" logic
        // Include payment_date to grouping
        // ADDED prepaid_applied to query
        $summarySql =
            "SELECT 
                tender_eftpos, tender_cash, tender_cheque,
                camp_fee, site_fee, other_amount, prepaid_applied, total, id, payment_date
            FROM payments
            WHERE payment_date BETWEEN ? AND ?";

        $summaryParams = [$startDate, $endDate];
        if ($campId) {
            $summarySql .= " AND camp_id = ?";
            $summaryParams[] = $campId;
        }

        $stmt = $db->prepare($summarySql);
        $stmt->execute($summaryParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $summary = $this->buildPaymentAllocationSummary($rows, true);

        echo json_encode([
            'total' => $summary['total'],
            'camp_fees' => $summary['camp'],
            'site_fees' => $summary['site'],
            'daily' => $summary['daily']
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

        // 2. Financial Chart Data (Whole Camp)
        $chartRowsStmt = $db->prepare("
            SELECT
                tender_eftpos,
                tender_cash,
                tender_cheque,
                camp_fee,
                site_fee,
                prepaid_applied,
                total,
                payment_date
            FROM payments
            WHERE camp_id = ?
            ORDER BY payment_date ASC, id ASC
        ");
        $chartRowsStmt->execute([$camp['id']]);
        $chartSummary = $this->buildPaymentAllocationSummary($chartRowsStmt->fetchAll(PDO::FETCH_ASSOC), true);
         
        $finLabels = [];
        $finTotal = [];
        $finCamp = [];
        $finSite = [];
         
        foreach ($chartSummary['daily'] as $date => $stats) {
            if (!$date) continue;
            $finLabels[] = date('d/m', strtotime($date));
            $finTotal[] = (float)($stats['total']['revenue'] ?? 0);
            $finCamp[] = (float)($stats['camp']['total'] ?? 0);
            $finSite[] = (float)($stats['site']['total'] ?? 0);
        }

        echo json_encode([
            'camp_name' => $camp['name'],
            'current_guests' => $currentGuests,
            'chart' => [
                'labels' => $labels,
                'headcount' => $dataHeadcount,
                'average' => $dataAvg
            ],
            'financial_chart' => [
                'labels' => $finLabels,
                'total' => $finTotal,
                'camp' => $finCamp,
                'site' => $finSite
            ]
        ]);
    }

    public function dashboardOverview() {
        header('Content-Type: application/json');
        $db = Database::connect();

        $campId = isset($_GET['camp_id']) && $_GET['camp_id'] !== '' ? (int)$_GET['camp_id'] : null;
        $camp = $this->findDashboardCamp($db, $campId);

        if (!$camp) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'No camps found.']);
            return;
        }

        $paymentStmt = $db->prepare("
            SELECT
                COUNT(*) AS payment_count,
                COALESCE(SUM(total), 0) AS total_taken,
                COALESCE(SUM(camp_fee), 0) AS camp_fees,
                COALESCE(SUM(site_fee), 0) AS site_fees,
                COALESCE(SUM(prepaid_applied), 0) AS prepaid_applied
            FROM payments
            WHERE camp_id = ?
        ");
        $paymentStmt->execute([(int)$camp['id']]);
        $paymentTotals = $paymentStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $paymentRowsStmt = $db->prepare("
            SELECT
                tender_eftpos,
                tender_cash,
                tender_cheque,
                camp_fee,
                site_fee,
                prepaid_applied,
                total,
                payment_date
            FROM payments
            WHERE camp_id = ?
        ");
        $paymentRowsStmt->execute([(int)$camp['id']]);
        $paymentAllocation = $this->buildPaymentAllocationSummary($paymentRowsStmt->fetchAll(PDO::FETCH_ASSOC), false);

        $hasPrepaymentTable = $this->tableExists($db, 'prepayments');
        $hasSourceAmount = $hasPrepaymentTable && $this->columnExists($db, 'prepayments', 'source_amount');
        $hasSyncState = $hasPrepaymentTable && $this->columnExists($db, 'prepayments', 'sync_state');

        $prepaymentTotals = [
            'total_prepaid' => 0.0,
            'remaining_balance' => 0.0,
            'total_count' => 0,
            'matched_count' => 0,
            'unmatched_count' => 0,
            'needs_review_count' => 0,
            'warning_count' => 0
        ];
        $checkInTotals = [
            'submission_count' => 0,
            'people_count' => 0,
            'adults_count' => 0,
            'kids_count' => 0
        ];

        if ($hasPrepaymentTable) {
            $sourceExpr = $hasSourceAmount
                ? "CASE WHEN COALESCE(source_amount, 0) > 0 THEN source_amount ELSE COALESCE(amount, 0) END"
                : "COALESCE(amount, 0)";
            $warningExpr = $hasSyncState
                ? "SUM(CASE WHEN sync_state = 'warning' THEN 1 ELSE 0 END)"
                : "0";

            $prepaymentSql = "
                SELECT
                    COALESCE(SUM($sourceExpr), 0) AS total_prepaid,
                    COALESCE(SUM(COALESCE(amount, 0)), 0) AS remaining_balance,
                    COUNT(*) AS total_count,
                    SUM(CASE WHEN status = 'Needs Review' THEN 1 ELSE 0 END) AS needs_review_count,
                    SUM(CASE WHEN status = 'Unmatched' THEN 1 ELSE 0 END) AS unmatched_count,
                    SUM(CASE WHEN matched_member_id IS NOT NULL OR status IN ('Matched', 'Partial', 'Applied') THEN 1 ELSE 0 END) AS matched_count,
                    $warningExpr AS warning_count
                FROM prepayments
                WHERE camp_id = ?
            ";
            $prepaymentStmt = $db->prepare($prepaymentSql);
            $prepaymentStmt->execute([(int)$camp['id']]);
            $prepaymentTotals = array_merge($prepaymentTotals, $prepaymentStmt->fetch(PDO::FETCH_ASSOC) ?: []);
        }

        if ($this->tableExists($db, 'camp_intranet_checkins')) {
            $checkInStmt = $db->prepare("
                SELECT
                    COUNT(*) AS submission_count,
                    COALESCE(SUM(COALESCE(adults_count, 0) + COALESCE(kids_count, 0)), 0) AS people_count,
                    COALESCE(SUM(COALESCE(adults_count, 0)), 0) AS adults_count,
                    COALESCE(SUM(COALESCE(kids_count, 0)), 0) AS kids_count
                FROM camp_intranet_checkins
                WHERE camp_id = ?
                  AND status IN ('new', 'in_progress', 'resolved')
            ");
            $checkInStmt->execute([(int)$camp['id']]);
            $checkInTotals = array_merge($checkInTotals, $checkInStmt->fetch(PDO::FETCH_ASSOC) ?: []);
        }

        echo json_encode([
            'success' => true,
            'camp' => [
                'id' => (int)$camp['id'],
                'name' => $camp['name'],
                'year' => $camp['year'],
                'status' => $camp['status'],
                'start_date' => $camp['start_date'],
                'end_date' => $camp['end_date'],
                'churchsuite_event_id' => $camp['churchsuite_event_id'],
                'churchsuite_event_identifier' => $camp['churchsuite_event_identifier'],
                'churchsuite_event_name' => $camp['churchsuite_event_name'],
                'churchsuite_last_sync_at' => $camp['churchsuite_last_sync_at'],
                'churchsuite_last_sync_status' => $camp['churchsuite_last_sync_status'],
                'churchsuite_last_sync_message' => $camp['churchsuite_last_sync_message']
            ],
            'finance' => [
                'payment_count' => (int)($paymentTotals['payment_count'] ?? 0),
                'total_taken' => (float)($paymentTotals['total_taken'] ?? 0),
                'camp_fees' => (float)($paymentTotals['camp_fees'] ?? 0),
                'site_fees' => (float)($paymentAllocation['site']['total'] ?? 0),
            ],
            'prepayments' => [
                'total_prepaid' => (float)($prepaymentTotals['total_prepaid'] ?? 0),
                'applied_to_payments' => (float)($paymentTotals['prepaid_applied'] ?? 0),
                'remaining_balance' => (float)($prepaymentTotals['remaining_balance'] ?? 0),
                'matched_count' => (int)($prepaymentTotals['matched_count'] ?? 0),
                'unmatched_count' => (int)($prepaymentTotals['unmatched_count'] ?? 0),
                'needs_review_count' => (int)($prepaymentTotals['needs_review_count'] ?? 0),
                'warning_count' => (int)($prepaymentTotals['warning_count'] ?? 0),
                'total_count' => (int)($prepaymentTotals['total_count'] ?? 0),
                'is_churchsuite_linked' => !empty($camp['churchsuite_event_id']) || !empty($camp['churchsuite_event_identifier']),
                'last_sync_at' => $camp['churchsuite_last_sync_at'],
                'last_sync_message' => $camp['churchsuite_last_sync_message']
            ],
            'checkins' => [
                'submission_count' => (int)($checkInTotals['submission_count'] ?? 0),
                'people_count' => (int)($checkInTotals['people_count'] ?? 0),
                'adults_count' => (int)($checkInTotals['adults_count'] ?? 0),
                'kids_count' => (int)($checkInTotals['kids_count'] ?? 0)
            ]
        ]);
    }

    public function delete($id) {
        $db = Database::connect();
        $db->beginTransaction();
        try {
            $paymentId = (int)$id;
            $paymentStmt = $db->prepare("SELECT * FROM payments WHERE id = ? LIMIT 1");
            $paymentStmt->execute([$paymentId]);
            $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
            if (!$payment) {
                throw new Exception('Payment record not found.');
            }

            if ($this->tableExists($db, 'payment_prepayment_allocations')) {
                $allocStmt = $db->prepare("
                    SELECT prepayment_id, amount_applied
                    FROM payment_prepayment_allocations
                    WHERE payment_id = ?
                ");
                $allocStmt->execute([$paymentId]);
                $allocations = $allocStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                foreach ($allocations as $allocation) {
                    $prepaymentId = (int)($allocation['prepayment_id'] ?? 0);
                    $amountApplied = (float)($allocation['amount_applied'] ?? 0);
                    if ($prepaymentId <= 0 || $amountApplied <= 0) {
                        continue;
                    }

                    $preStmt = $db->prepare("
                        SELECT id, amount, matched_member_id, status
                        FROM prepayments
                        WHERE id = ?
                        LIMIT 1
                    ");
                    $preStmt->execute([$prepaymentId]);
                    $prepayment = $preStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$prepayment) {
                        continue;
                    }

                    $restoredAmount = round((float)($prepayment['amount'] ?? 0) + $amountApplied, 2);

                    $otherAllocStmt = $db->prepare("
                        SELECT COUNT(*)
                        FROM payment_prepayment_allocations
                        WHERE prepayment_id = ?
                          AND payment_id <> ?
                    ");
                    $otherAllocStmt->execute([$prepaymentId, $paymentId]);
                    $hasOtherAllocations = (int)$otherAllocStmt->fetchColumn() > 0;

                    $restoredStatus = !empty($prepayment['matched_member_id']) ? 'Matched' : 'Unmatched';
                    if ($hasOtherAllocations) {
                        $restoredStatus = 'Partial';
                    }

                    $restoreStmt = $db->prepare("UPDATE prepayments SET amount = ?, status = ? WHERE id = ?");
                    $restoreStmt->execute([$restoredAmount, $restoredStatus, $prepaymentId]);
                }

                $deleteAllocStmt = $db->prepare("DELETE FROM payment_prepayment_allocations WHERE payment_id = ?");
                $deleteAllocStmt->execute([$paymentId]);
            }

            if ($this->tableExists($db, 'camp_intranet_checkins')) {
                $resetCheckInStmt = $db->prepare("
                    UPDATE camp_intranet_checkins
                    SET status = 'new',
                        applied_payment_id = NULL,
                        applied_member_id = NULL,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE applied_payment_id = ?
                ");
                $resetCheckInStmt->execute([$paymentId]);
            }

            $stmt = $db->prepare("DELETE FROM payment_tenders WHERE payment_id = ?");
            $stmt->execute([$paymentId]);
            
            $stmt = $db->prepare("DELETE FROM payments WHERE id = ?");
            $stmt->execute([$paymentId]);

            if ((float)($payment['site_fee'] ?? 0) > 0) {
                $this->siteFeeService($db)->recalculateMemberAccountFromPayments((int)$payment['member_id']);
            }
            
            $db->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}
