<?php

class SiteFeeService
{
    private PDO $db;
    private array $tableCache = [];
    private array $columnCache = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function tableExists(string $table): bool
    {
        $key = strtolower($table);
        if (array_key_exists($key, $this->tableCache)) {
            return $this->tableCache[$key];
        }

        $stmt = $this->db->prepare(
            "SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1"
        );
        $stmt->execute([$table]);
        $this->tableCache[$key] = (bool)$stmt->fetchColumn();
        return $this->tableCache[$key];
    }

    public function columnExists(string $table, string $column): bool
    {
        $key = strtolower($table . '.' . $column);
        if (array_key_exists($key, $this->columnCache)) {
            return $this->columnCache[$key];
        }

        $stmt = $this->db->prepare(
            "SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1"
        );
        $stmt->execute([$table, $column]);
        $this->columnCache[$key] = (bool)$stmt->fetchColumn();
        return $this->columnCache[$key];
    }

    public function ensureAuditTables(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS site_fee_audit_reviews (
                id INT AUTO_INCREMENT PRIMARY KEY,
                member_id INT NOT NULL,
                site_id INT NULL,
                discrepancy_signature VARCHAR(64) NOT NULL,
                review_status VARCHAR(20) NOT NULL DEFAULT 'reviewed',
                notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_site_fee_audit_signature (discrepancy_signature),
                KEY idx_site_fee_audit_member (member_id),
                CONSTRAINT fk_site_fee_audit_reviews_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
                CONSTRAINT fk_site_fee_audit_reviews_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function normalizeDate($value): ?string
    {
        $value = trim((string)$value);
        if ($value === '' || $value === '0000-00-00') {
            return null;
        }

        try {
            return (new DateTimeImmutable(substr($value, 0, 10), new DateTimeZone('Australia/Adelaide')))->format('Y-m-d');
        } catch (Throwable $e) {
            return null;
        }
    }

    public function monthStartForDate($value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            $value = 'now';
        }

        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('Australia/Adelaide'));
        } catch (Throwable $e) {
            $date = new DateTimeImmutable('now', new DateTimeZone('Australia/Adelaide'));
        }

        return $date->modify('first day of this month')->format('Y-m-d');
    }

    public function calculateNextDue(?string $currentDue, ?string $paymentDate, int $months): ?string
    {
        if ($months <= 0) {
            return $this->normalizeDate($currentDue);
        }

        $baseDate = $this->normalizeDate($currentDue) ?: $this->monthStartForDate((string)$paymentDate);
        $base = new DateTimeImmutable($baseDate, new DateTimeZone('Australia/Adelaide'));
        return $base->modify('+' . $months . ' month')->format('Y-m-d');
    }

    public function getCurrentDueDate(int $memberId): ?string
    {
        if (!$this->tableExists('site_fee_accounts')) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT MAX(paid_until) FROM site_fee_accounts WHERE member_id = ?");
        $stmt->execute([(int)$memberId]);
        return $this->normalizeDate($stmt->fetchColumn());
    }

    public function getAccountRows(int $memberId): array
    {
        if (!$this->tableExists('site_fee_accounts')) {
            return [];
        }

        $stmt = $this->db->prepare(
            "SELECT id, member_id, paid_until, status, created_at
             FROM site_fee_accounts
             WHERE member_id = ?
             ORDER BY paid_until DESC, id DESC"
        );
        $stmt->execute([(int)$memberId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getPaymentsTableColumns(): array
    {
        return [
            'site_fee_months' => $this->columnExists('payments', 'site_fee_months'),
            'site_fee_paid_until' => $this->columnExists('payments', 'site_fee_paid_until'),
            'concession' => $this->columnExists('payments', 'concession'),
        ];
    }

    public function inferMonthsFromPayment(array $payment): int
    {
        $explicitMonths = (int)($payment['site_fee_months'] ?? 0);
        if ($explicitMonths > 0) {
            return $explicitMonths;
        }

        $notes = (string)($payment['notes'] ?? '');
        if (preg_match('/Site contribution:\s*\+(\d+)\s+year/i', $notes, $match)) {
            return max(0, ((int)$match[1]) * 12);
        }

        if (preg_match('/Site fee\s*\((\d+)\s+months?\)/i', $notes, $match)) {
            return max(0, (int)$match[1]);
        }

        return $this->inferMonthsFromAmount(
            (float)($payment['site_fee'] ?? 0),
            (int)($payment['camp_id'] ?? 0),
            $this->paymentIsConcession($payment)
        );
    }

    public function inferMonthsFromContext(float $siteFee, int $campId, bool $concession): int
    {
        return $this->inferMonthsFromAmount($siteFee, $campId, $concession);
    }

    public function buildRecalculationPreview(int $memberId, ?int $ignorePaymentId = null): array
    {
        $payments = $this->getSiteFeePaymentsForMember($memberId, $ignorePaymentId);
        $expectedDue = null;
        $latestPayment = null;
        $undeterminedPaymentIds = [];

        foreach ($payments as &$payment) {
            $months = $this->inferMonthsFromPayment($payment);
            $payment['inferred_site_fee_months'] = $months;

            if ($months > 0) {
                $expectedDue = $this->calculateNextDue($expectedDue, $payment['payment_date'] ?? null, $months);
            } else {
                $recordedDue = $this->normalizeDate($payment['site_fee_paid_until'] ?? null);
                if ($recordedDue !== null) {
                    $expectedDue = $recordedDue;
                } else {
                    $undeterminedPaymentIds[] = (int)($payment['id'] ?? 0);
                }
            }

            if ($payment['site_fee_paid_until'] ?? null) {
                $payment['site_fee_paid_until'] = $this->normalizeDate($payment['site_fee_paid_until']);
            }
            $latestPayment = $payment;
        }
        unset($payment);

        return [
            'expected_due' => $expectedDue,
            'payments' => $payments,
            'latest_payment' => $latestPayment,
            'payment_count' => count($payments),
            'undetermined_payment_ids' => $undeterminedPaymentIds,
        ];
    }

    public function recalculateMemberAccountFromPayments(int $memberId): array
    {
        $preview = $this->buildRecalculationPreview($memberId);
        $storedRows = $this->getAccountRows($memberId);
        $previousDue = null;
        foreach ($storedRows as $row) {
            $rowDue = $this->normalizeDate($row['paid_until'] ?? null);
            if ($rowDue !== null) {
                $previousDue = $rowDue;
                break;
            }
        }

        $this->replaceAccountRows($memberId, $preview['expected_due']);

        return [
            'previous_due' => $previousDue,
            'stored_due' => $this->getCurrentDueDate($memberId),
            'expected_due' => $preview['expected_due'],
            'payment_count' => $preview['payment_count'],
            'undetermined_payment_ids' => $preview['undetermined_payment_ids'],
            'latest_payment' => $preview['latest_payment'],
        ];
    }

    public function setCustomDueDate(int $memberId, string $dueDate): array
    {
        $normalized = $this->normalizeDate($dueDate);
        if ($normalized === null) {
            throw new InvalidArgumentException('Please enter a valid due date.');
        }

        $previousDue = $this->getCurrentDueDate($memberId);
        $this->replaceAccountRows($memberId, $normalized);

        return [
            'previous_due' => $previousDue,
            'stored_due' => $this->getCurrentDueDate($memberId),
            'expected_due' => $normalized,
        ];
    }

    public function markAuditStatus(int $memberId, ?int $siteId, string $signature, string $status, ?string $notes = null): void
    {
        $this->ensureAuditTables();
        $status = strtolower(trim($status));
        if (!in_array($status, ['reviewed', 'ignored'], true)) {
            throw new InvalidArgumentException('Unknown audit status.');
        }

        $stmt = $this->db->prepare(
            "INSERT INTO site_fee_audit_reviews (member_id, site_id, discrepancy_signature, review_status, notes)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                member_id = VALUES(member_id),
                site_id = VALUES(site_id),
                review_status = VALUES(review_status),
                notes = VALUES(notes),
                updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([
            (int)$memberId,
            $siteId ? (int)$siteId : null,
            trim($signature),
            $status,
            $notes !== null ? trim((string)$notes) : null,
        ]);
    }

    public function getAuditRows(): array
    {
        $this->ensureAuditTables();

        $stmt = $this->db->query(
            "SELECT DISTINCT
                m.id AS member_id,
                m.first_name,
                m.last_name,
                m.site_fee_status,
                s.id AS site_id,
                s.site_number,
                s.site_type
             FROM members m
             LEFT JOIN site_allocations sa ON sa.member_id = m.id AND sa.is_current = 1
             LEFT JOIN sites s ON s.id = sa.site_id
             LEFT JOIN site_fee_accounts sfa ON sfa.member_id = m.id
             LEFT JOIN payments p ON p.member_id = m.id AND COALESCE(p.site_fee, 0) > 0
             WHERE sa.id IS NOT NULL OR sfa.id IS NOT NULL OR p.id IS NOT NULL
             ORDER BY m.last_name, m.first_name, m.id"
        );

        $members = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        $rows = [];

        foreach ($members as $member) {
            $memberId = (int)($member['member_id'] ?? 0);
            if ($memberId <= 0) {
                continue;
            }

            $status = strtolower(trim((string)($member['site_fee_status'] ?? '')));
            if ($status === 'exempt') {
                continue;
            }

            $accountRows = $this->getAccountRows($memberId);
            $storedDue = null;
            foreach ($accountRows as $accountRow) {
                $rowDue = $this->normalizeDate($accountRow['paid_until'] ?? null);
                if ($rowDue !== null) {
                    $storedDue = $rowDue;
                    break;
                }
            }

            $preview = $this->buildRecalculationPreview($memberId);
            $expectedDue = $preview['expected_due'];
            $latestPayment = $preview['latest_payment'];
            $latestPaymentStoredDue = $this->normalizeDate($latestPayment['site_fee_paid_until'] ?? null);

            $reasons = [];
            if (count($accountRows) > 1) {
                $reasons[] = 'Multiple site fee account rows exist for this member.';
            }
            if ($storedDue === null && $expectedDue !== null) {
                $reasons[] = 'No current due date is stored, but payment history can calculate one.';
            }
            if ($storedDue !== null && $expectedDue === null) {
                $reasons[] = 'A stored due date exists, but no calculable site-fee payments were found.';
            }
            if ($storedDue !== null && $expectedDue !== null && $storedDue !== $expectedDue) {
                $reasons[] = 'Stored due date does not match payment history.';
            }
            if ($latestPaymentStoredDue !== null && $expectedDue !== null && $latestPaymentStoredDue !== $expectedDue) {
                $reasons[] = 'Latest site-fee payment recorded a different expiry date.';
            }
            if ($preview['undetermined_payment_ids']) {
                $reasons[] = 'One or more site-fee payments could not be interpreted cleanly.';
            }

            if (!$reasons) {
                continue;
            }

            $signature = sha1(json_encode([
                'member_id' => $memberId,
                'site_id' => (int)($member['site_id'] ?? 0),
                'stored_due' => $storedDue,
                'expected_due' => $expectedDue,
                'latest_payment_id' => (int)($latestPayment['id'] ?? 0),
                'reasons' => $reasons,
            ]));
            $review = $this->loadAuditReview($signature);

            $rows[] = [
                'member_id' => $memberId,
                'member_name' => trim((string)($member['first_name'] ?? '') . ' ' . (string)($member['last_name'] ?? '')),
                'site_id' => !empty($member['site_id']) ? (int)$member['site_id'] : null,
                'site_number' => $member['site_number'] ?? null,
                'site_type' => $member['site_type'] ?? null,
                'stored_due_date' => $storedDue,
                'expected_due_date' => $expectedDue,
                'latest_payment_id' => (int)($latestPayment['id'] ?? 0),
                'latest_payment_date' => $latestPayment['payment_date'] ?? null,
                'latest_payment_camp_name' => $latestPayment['camp_name'] ?? null,
                'latest_payment_camp_year' => $latestPayment['camp_year'] ?? null,
                'latest_payment_site_fee' => (float)($latestPayment['site_fee'] ?? 0),
                'latest_payment_site_fee_paid_until' => $latestPaymentStoredDue,
                'latest_payment_inferred_months' => (int)($latestPayment['inferred_site_fee_months'] ?? 0),
                'payment_count' => (int)($preview['payment_count'] ?? 0),
                'discrepancy_reasons' => $reasons,
                'discrepancy_signature' => $signature,
                'review_status' => $review['review_status'] ?? null,
                'review_notes' => $review['notes'] ?? null,
                'reviewed_at' => $review['updated_at'] ?? null,
            ];
        }

        usort($rows, function (array $a, array $b) {
            $score = function (array $row): int {
                $reasons = $row['discrepancy_reasons'] ?? [];
                $points = 0;
                foreach ($reasons as $reason) {
                    if (stripos($reason, 'does not match') !== false) {
                        $points += 4;
                    } elseif (stripos($reason, 'Multiple') !== false) {
                        $points += 3;
                    } elseif (stripos($reason, 'No current due') !== false) {
                        $points += 2;
                    } else {
                        $points += 1;
                    }
                }
                return $points;
            };

            $scoreA = $score($a);
            $scoreB = $score($b);
            if ($scoreA !== $scoreB) {
                return $scoreB <=> $scoreA;
            }

            return strcmp(
                strtolower((string)($a['member_name'] ?? '')),
                strtolower((string)($b['member_name'] ?? ''))
            );
        });

        return $rows;
    }

    private function loadAuditReview(string $signature): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT review_status, notes, updated_at
             FROM site_fee_audit_reviews
             WHERE discrepancy_signature = ?
             LIMIT 1"
        );
        $stmt->execute([$signature]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function paymentIsConcession(array $payment): bool
    {
        $value = $payment['concession'] ?? 0;
        if (is_numeric($value)) {
            return (int)$value === 1;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'yes', 'true'], true);
    }

    private function inferMonthsFromAmount(float $siteFee, int $campId, bool $concession): int
    {
        if ($siteFee <= 0) {
            return 0;
        }

        $annualCandidates = $this->getAnnualRateCandidates($campId, $concession);
        foreach ($annualCandidates as $annual) {
            for ($months = 1; $months <= 24; $months++) {
                $roundedWhole = round(($annual / 12) * $months);
                $roundedMoney = round(($annual / 12) * $months, 2);
                if (abs($siteFee - $roundedWhole) < 0.01 || abs($siteFee - $roundedMoney) < 0.01) {
                    return $months;
                }
            }

            if (abs($siteFee - $annual) < 0.01) {
                return 12;
            }
        }

        return 0;
    }

    private function getAnnualRateCandidates(int $campId, bool $concession): array
    {
        $candidates = [];

        if ($this->tableExists('camp_rates')) {
            try {
                $stmt = $this->db->prepare(
                    "SELECT amount
                     FROM camp_rates
                     WHERE camp_id = ?
                       AND LOWER(COALESCE(category, '')) LIKE 'site fee%'
                       AND LOWER(COALESCE(user_type, '')) IN (?, ?, ?)"
                );
                $target = $concession ? 'concession' : 'standard';
                $stmt->execute([$campId, $target, 'site fee ' . $target, $target . ' site fee']);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $amount) {
                    $amount = round((float)$amount, 2);
                    if ($amount > 0) {
                        $candidates[] = $amount;
                    }
                }
            } catch (Throwable $e) {
                // Keep the known defaults below as the safe fallback.
            }
        }

        $candidates[] = $concession ? 320.0 : 400.0;
        return array_values(array_unique(array_map(static function ($value) {
            return number_format((float)$value, 2, '.', '');
        }, $candidates)));
    }

    private function getSiteFeePaymentsForMember(int $memberId, ?int $ignorePaymentId = null): array
    {
        $columns = $this->getPaymentsTableColumns();
        $sql = "
            SELECT
                p.id,
                p.member_id,
                p.camp_id,
                p.payment_date,
                p.site_fee,
                p.notes,
                " . ($columns['site_fee_months'] ? "p.site_fee_months" : "NULL AS site_fee_months") . ",
                " . ($columns['site_fee_paid_until'] ? "p.site_fee_paid_until" : "NULL AS site_fee_paid_until") . ",
                " . ($columns['concession'] ? "p.concession" : "0 AS concession") . ",
                c.name AS camp_name,
                c.year AS camp_year
            FROM payments p
            LEFT JOIN camps c ON c.id = p.camp_id
            WHERE p.member_id = ?
              AND COALESCE(p.site_fee, 0) > 0
        ";
        $params = [(int)$memberId];
        if ($ignorePaymentId !== null) {
            $sql .= " AND p.id <> ?";
            $params[] = (int)$ignorePaymentId;
        }
        $sql .= " ORDER BY p.payment_date ASC, p.id ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function replaceAccountRows(int $memberId, ?string $dueDate): void
    {
        $existingRows = $this->getAccountRows($memberId);
        $hadExempt = false;
        foreach ($existingRows as $row) {
            if (strcasecmp((string)($row['status'] ?? ''), 'Exempt') === 0) {
                $hadExempt = true;
                break;
            }
        }

        $this->db->prepare("DELETE FROM site_fee_accounts WHERE member_id = ?")->execute([(int)$memberId]);

        $normalizedDue = $this->normalizeDate($dueDate);
        if ($normalizedDue !== null) {
            $status = $this->statusForDueDate($normalizedDue);
            $stmt = $this->db->prepare(
                "INSERT INTO site_fee_accounts (member_id, paid_until, status) VALUES (?, ?, ?)"
            );
            $stmt->execute([(int)$memberId, $normalizedDue, $status]);
            if (!$hadExempt) {
                $this->db->prepare("UPDATE members SET site_fee_status = ? WHERE id = ?")->execute([$status, (int)$memberId]);
            }
            return;
        }

        if ($hadExempt) {
            $stmt = $this->db->prepare(
                "INSERT INTO site_fee_accounts (member_id, paid_until, status) VALUES (?, NULL, 'Exempt')"
            );
            $stmt->execute([(int)$memberId]);
            $this->db->prepare("UPDATE members SET site_fee_status = 'Exempt' WHERE id = ?")->execute([(int)$memberId]);
            return;
        }

        $this->db->prepare("
            UPDATE members
            SET site_fee_status = CASE
                WHEN site_fee_status = 'Exempt' THEN 'Exempt'
                ELSE 'Unknown'
            END
            WHERE id = ?
        ")->execute([(int)$memberId]);
    }

    private function statusForDueDate(string $dueDate): string
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('Australia/Adelaide'));
        $due = new DateTimeImmutable($dueDate, $today->getTimezone());
        return $due >= $today ? 'Paid' : 'Overdue';
    }
}
