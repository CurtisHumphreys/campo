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
        $input = [];
        if (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
            $body = file_get_contents('php://input');
            $input = json_decode($body, true) ?: [];
        } else {
            $input = $_POST;
        }
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
        $sql = "SELECT p.*, m.first_name AS member_first_name, m.last_name AS member_last_name
                FROM prepayments p
                LEFT JOIN members m ON p.matched_member_id = m.id
                WHERE p.camp_id = ?";
        $params = [$campId];
        if ($search) {
            $sql .= " AND (COALESCE(p.first_name,'') LIKE ? OR COALESCE(p.last_name,'') LIKE ? OR COALESCE(p.transaction_id,'') LIKE ?)";
            $wild = '%' . $search . '%';
            $params[] = $wild;
            $params[] = $wild;
            $params[] = $wild;
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
}