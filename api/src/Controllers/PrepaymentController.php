<?php

require_once __DIR__ . '/../Database.php';

/**
 * Controller responsible for operations on the prepayments table that are not
 * directly related to importing. This controller provides endpoints for
 * performing bulk actions such as deleting all pre‑payment records for a
 * particular camp (or across the whole database) as well as simple listing
 * with optional filters. Separating these concerns keeps ImportController
 * focused on the CSV import logic.
 */
class PrepaymentController
{
    private function tableExists(PDO $db, $table)
    {
        $stmt = $db->query("SHOW TABLES LIKE " . $db->quote($table));
        return $stmt ? (bool)$stmt->fetch(PDO::FETCH_NUM) : false;
    }

    private function columnExists(PDO $db, $table, $column)
    {
        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND column_name = ?
            LIMIT 1
        ");
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function readInput()
    {
        if (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
            $body = file_get_contents('php://input');
            return json_decode($body, true) ?: [];
        }
        return $_POST;
    }

    /**
     * Delete all prepayment records. If a camp_id is provided via POST data
     * then only records for that camp will be removed; otherwise every
     * prepayment row in the database will be deleted. This operation is
     * irreversible and should be protected in the UI by a confirmation
     * prompt.
     */
    public function deleteAll()
    {
        $db = Database::connect();
        // Accept camp_id either via POST form or JSON body to support
        // different client implementations.
        $input = $this->readInput();
        $campId = $input['camp_id'] ?? null;
        try {
            if ($campId) {
                $stmt = $db->prepare("DELETE FROM prepayments WHERE camp_id = ?");
                $stmt->execute([$campId]);
            } else {
                // Remove all prepayments across all camps
                $db->exec("DELETE FROM prepayments");
            }
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * List prepayments with optional filtering. This mirrors the functionality
     * originally located in ImportController::listPrepayments but adds
     * optional search and status filtering. Parameters:
     *   camp_id (required) – the camp to fetch pre‑payments for.
     *   search (optional) – a search term that will match against
     *       first_name, last_name or transaction_id.
     *   status (optional) – when provided will restrict results to the
     *       specified status (e.g. Matched, Unmatched, Partial, Applied).
     */
    public function index()
    {
        $campId = $_GET['camp_id'] ?? null;
        if (!$campId) {
            echo json_encode([]);
            return;
        }
        $db = Database::connect();
        $search = isset($_GET['search']) ? trim($_GET['search']) : null;
        $status = isset($_GET['status']) ? trim($_GET['status']) : null;
        $householdsEnabled = $this->tableExists($db, 'member_household_members') && $this->tableExists($db, 'member_households');
        $allocationTrackingEnabled = $this->tableExists($db, 'payment_prepayment_allocations') && $this->tableExists($db, 'payments');

        $sql = "SELECT
                    p.*,
                    m.first_name AS member_first_name,
                    m.last_name AS member_last_name,
                    m.email AS member_email,
                    m.mobile AS member_mobile,
                    m.phone AS member_phone,
                    " . ($householdsEnabled ? "hhs.household_id, hhs.household_name, hhs.household_member_names" : "NULL AS household_id, NULL AS household_name, NULL AS household_member_names") . ",
                    " . ($allocationTrackingEnabled ? "COALESCE(pa.allocation_count, 0) AS allocation_count, pa.allocation_payment_ids" : "0 AS allocation_count, NULL AS allocation_payment_ids") . "
                FROM prepayments p
                LEFT JOIN members m ON p.matched_member_id = m.id";
        if ($householdsEnabled) {
            $sql .= "
                LEFT JOIN (
                    SELECT
                        pref.member_id,
                        hh.id AS household_id,
                        hh.display_name AS household_name,
                        GROUP_CONCAT(
                            DISTINCT CASE
                                WHEN hm2.id IS NULL OR hm2.id = pref.member_id THEN NULL
                                ELSE CONCAT(TRIM(COALESCE(hm2.first_name, '')), ' ', TRIM(COALESCE(hm2.last_name, '')))
                            END
                            ORDER BY hm2.last_name, hm2.first_name
                            SEPARATOR ' | '
                        ) AS household_member_names
                    FROM (
                        SELECT
                            mhm.member_id,
                            CAST(SUBSTRING_INDEX(
                                GROUP_CONCAT(
                                    mhm.household_id
                                    ORDER BY
                                        CASE
                                            WHEN mhm.source_system = 'manual' THEN 0
                                            WHEN mhm.source_system = 'churchsuite' THEN 1
                                            ELSE 2
                                        END,
                                        COALESCE(hc.member_count, 0) DESC,
                                        mhm.is_primary DESC,
                                        mhm.household_id ASC
                                ),
                                ',',
                                1
                            ) AS UNSIGNED) AS household_id
                        FROM member_household_members mhm
                        LEFT JOIN (
                            SELECT household_id, COUNT(*) AS member_count
                            FROM member_household_members
                            GROUP BY household_id
                        ) hc ON hc.household_id = mhm.household_id
                        GROUP BY mhm.member_id
                    ) pref
                    JOIN member_households hh ON hh.id = pref.household_id
                    LEFT JOIN member_household_members mhm2 ON mhm2.household_id = hh.id
                    LEFT JOIN members hm2 ON hm2.id = mhm2.member_id
                    GROUP BY pref.member_id, hh.id, hh.display_name
                ) hhs ON hhs.member_id = p.matched_member_id";
        }
        if ($allocationTrackingEnabled) {
            $sql .= "
                LEFT JOIN (
                    SELECT
                        a.prepayment_id,
                        COUNT(*) AS allocation_count,
                        GROUP_CONCAT(DISTINCT a.payment_id ORDER BY a.payment_id SEPARATOR ', ') AS allocation_payment_ids
                    FROM payment_prepayment_allocations a
                    INNER JOIN payments pay ON pay.id = a.payment_id
                    GROUP BY a.prepayment_id
                ) pa ON pa.prepayment_id = p.id";
        }
        $sql .= "
                WHERE p.camp_id = ?";
        $params = [$campId];
        if ($search) {
            $sql .= " AND (
                COALESCE(p.first_name,'') LIKE ?
                OR COALESCE(p.last_name,'') LIKE ?
                OR COALESCE(p.transaction_id,'') LIKE ?
                OR COALESCE(p.email,'') LIKE ?
                OR COALESCE(p.mobile,'') LIKE ?";
            if ($householdsEnabled) {
                $sql .= " OR COALESCE(hhs.household_member_names,'') LIKE ?";
            }
            $sql .= ")";
            $wild = '%' . $search . '%';
            $params[] = $wild;
            $params[] = $wild;
            $params[] = $wild;
            $params[] = $wild;
            $params[] = $wild;
            if ($householdsEnabled) {
                $params[] = $wild;
            }
        }
        if ($status) {
            $sql .= " AND p.status = ?";
            $params[] = $status;
        }
        $sql .= " ORDER BY p.id DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll());
    }

    public function reset()
    {
        $input = $this->readInput();
        $id = isset($input['id']) ? (int)$input['id'] : 0;

        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing pre-payment ID.']);
            return;
        }

        $db = Database::connect();
        try {
            $db->beginTransaction();

            $stmt = $db->prepare("SELECT * FROM prepayments WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $prepayment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$prepayment) {
                $db->rollBack();
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Pre-payment not found.']);
                return;
            }

            if ($this->tableExists($db, 'payment_prepayment_allocations') && $this->tableExists($db, 'payments')) {
                $cleanupStmt = $db->prepare("
                    DELETE a
                    FROM payment_prepayment_allocations a
                    LEFT JOIN payments p ON p.id = a.payment_id
                    WHERE a.prepayment_id = ?
                      AND p.id IS NULL
                ");
                $cleanupStmt->execute([$id]);

                $allocationStmt = $db->prepare("
                    SELECT
                        COUNT(*) AS allocation_count,
                        GROUP_CONCAT(DISTINCT a.payment_id ORDER BY a.payment_id SEPARATOR ', ') AS payment_ids
                    FROM payment_prepayment_allocations a
                    INNER JOIN payments p ON p.id = a.payment_id
                    WHERE a.prepayment_id = ?
                ");
                $allocationStmt->execute([$id]);
                $allocationInfo = $allocationStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $allocationCount = (int)($allocationInfo['allocation_count'] ?? 0);

                if ($allocationCount > 0) {
                    $db->rollBack();
                    http_response_code(409);
                    $paymentIds = trim((string)($allocationInfo['payment_ids'] ?? ''));
                    $paymentLabel = $paymentIds !== '' ? ' Linked payment IDs: ' . $paymentIds . '.' : '';
                    echo json_encode([
                        'success' => false,
                        'message' => 'This pre-payment is still linked to an existing payment record. Delete or correct that payment first, then reset the pre-payment.' . $paymentLabel
                    ]);
                    return;
                }
            }

            $sourceAmount = $this->columnExists($db, 'prepayments', 'source_amount')
                ? round((float)($prepayment['source_amount'] ?? 0), 2)
                : 0.0;
            $currentAmount = round((float)($prepayment['amount'] ?? 0), 2);
            $restoredAmount = max($sourceAmount, $currentAmount);

            if ($restoredAmount <= 0) {
                $db->rollBack();
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Campo could not determine an amount to restore for this pre-payment.'
                ]);
                return;
            }

            $currentStatus = trim((string)($prepayment['status'] ?? ''));
            $restoredStatus = !empty($prepayment['matched_member_id']) ? 'Matched' : 'Unmatched';
            if (empty($prepayment['matched_member_id']) && $currentStatus === 'Needs Review') {
                $restoredStatus = 'Needs Review';
            }

            $updateSql = "UPDATE prepayments SET amount = ?, status = ?";
            $params = [$restoredAmount, $restoredStatus];

            if ($this->columnExists($db, 'prepayments', 'sync_state')) {
                $updateSql .= ", sync_state = ?";
                $params[] = 'ok';
            }
            if ($this->columnExists($db, 'prepayments', 'sync_note')) {
                $updateSql .= ", sync_note = NULL";
            }

            $updateSql .= " WHERE id = ?";
            $params[] = $id;

            $updateStmt = $db->prepare($updateSql);
            $updateStmt->execute($params);

            $db->commit();
            echo json_encode([
                'success' => true,
                'message' => 'Pre-payment was reset. The next ChurchSuite sync will refresh this record.',
                'prepayment' => [
                    'id' => $id,
                    'amount' => $restoredAmount,
                    'status' => $restoredStatus
                ]
            ]);
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
