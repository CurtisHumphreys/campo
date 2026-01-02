<?php

require_once __DIR__ . '/../Database.php';

class MemberController {
    public function index() {
        $db = Database::connect();
        // Fetch members along with any site fee expiry date.  A member may have a single
        // record in site_fee_accounts which tracks their paid_until date.  Use a LEFT JOIN
        // so members without a fee record still appear.  If there are multiple records
        // (which should not happen), we take the record with the furthest paid_until.
        $sql = "SELECT m.*, sfa.paid_until AS site_fee_paid_until
                FROM members m
                LEFT JOIN (
                    SELECT member_id, MAX(paid_until) AS paid_until
                    FROM site_fee_accounts
                    GROUP BY member_id
                ) sfa ON m.id = sfa.member_id
                ORDER BY m.last_name, m.first_name";
        $stmt = $db->query($sql);
        echo json_encode($stmt->fetchAll());
    }

    public function store() {
        $data = json_decode(file_get_contents('php://input'), true);
        $db = Database::connect();
        // Only insert the columns that actually exist in the database. The original schema does not
        // include partner_id, so we omit it here. Concession values are normalised to Yes/No.
        $stmt = $db->prepare("INSERT INTO members (first_name, last_name, fellowship, concession, site_fee_status) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $data['first_name'],
            $data['last_name'],
            $data['fellowship'],
            (in_array(strtolower($data['concession']), ['yes', 'y', '1', 'true'])) ? 'Yes' : 'No',
            $data['site_fee_status'] ?? 'Unknown'
        ]);
        $newId = $db->lastInsertId();

        // Optional: create/update site fee paid-until date
        if (!empty($data['site_fee_paid_until'])) {
            $paidUntil = substr($data['site_fee_paid_until'], 0, 10);
            // Update an existing record if present, otherwise insert
            $check = $db->prepare("SELECT id FROM site_fee_accounts WHERE member_id = ? ORDER BY paid_until DESC LIMIT 1");
            $check->execute([$newId]);
            $existingId = $check->fetchColumn();
            if ($existingId) {
                $upd = $db->prepare("UPDATE site_fee_accounts SET paid_until = ? WHERE id = ?");
                $upd->execute([$paidUntil, $existingId]);
            } else {
                $ins = $db->prepare("INSERT INTO site_fee_accounts (member_id, paid_until, status) VALUES (?, ?, 'Paid')");
                $ins->execute([$newId, $paidUntil]);
            }
        }

        echo json_encode(['success' => true, 'id' => $newId]);
    }

    public function update($id) {
        $data = json_decode(file_get_contents('php://input'), true);
        $db = Database::connect();
        // Update only existing columns. Remove partner_id handling as it is not present in the schema.
        $stmt = $db->prepare("UPDATE members SET first_name=?, last_name=?, fellowship=?, concession=?, site_fee_status=? WHERE id=?");
        $stmt->execute([
            $data['first_name'],
            $data['last_name'],
            $data['fellowship'],
            (in_array(strtolower($data['concession']), ['yes', 'y', '1', 'true'])) ? 'Yes' : 'No',
            $data['site_fee_status'],
            $id
        ]);

        // Optional: update site fee paid-until if provided
        if (array_key_exists('site_fee_paid_until', $data)) {
            $paidUntil = trim((string)$data['site_fee_paid_until']);
            // Allow clearing the date
            if ($paidUntil === '') {
                $db->prepare("DELETE FROM site_fee_accounts WHERE member_id = ?")->execute([$id]);
            } else {
                $paidUntil = substr($paidUntil, 0, 10);
                $check = $db->prepare("SELECT id FROM site_fee_accounts WHERE member_id = ? ORDER BY paid_until DESC LIMIT 1");
                $check->execute([$id]);
                $existingId = $check->fetchColumn();
                if ($existingId) {
                    $upd = $db->prepare("UPDATE site_fee_accounts SET paid_until = ? WHERE id = ?");
                    $upd->execute([$paidUntil, $existingId]);
                } else {
                    $ins = $db->prepare("INSERT INTO site_fee_accounts (member_id, paid_until, status) VALUES (?, ?, 'Paid')");
                    $ins->execute([$id, $paidUntil]);
                }
            }
        }
        echo json_encode(['success' => true]);
    }

    public function history($id) {
        $db = Database::connect();

        // Fetch payments for the member with camp names
        $stmt = $db->prepare("SELECT p.*, c.name as camp_name FROM payments p LEFT JOIN camps c ON p.camp_id = c.id WHERE p.member_id = ? ORDER BY p.payment_date DESC");
        $stmt->execute([$id]);
        $payments = $stmt->fetchAll();

        // Fetch allocations for the member
        $stmt = $db->prepare("SELECT sa.*, s.site_number FROM site_allocations sa JOIN sites s ON sa.site_id = s.id WHERE sa.member_id = ? ORDER BY sa.start_date DESC");
        $stmt->execute([$id]);
        $allocations = $stmt->fetchAll();

        // Fetch prepayments matched to this member
        $stmt = $db->prepare("SELECT * FROM prepayments WHERE matched_member_id = ? ORDER BY id DESC");
        $stmt->execute([$id]);
        $prepayments = $stmt->fetchAll();

        // Fetch site fee account to provide paid_until information
        $stmt = $db->prepare("SELECT MAX(paid_until) AS paid_until FROM site_fee_accounts WHERE member_id = ?");
        $stmt->execute([$id]);
        $siteFee = $stmt->fetchColumn();

        // Return data including site fee expiry
        echo json_encode([
            'payments' => $payments,
            'allocations' => $allocations,
            'prepayments' => $prepayments,
            'site_fee_paid_until' => $siteFee
        ]);
    }

    /**
     * Delete a single member and all associated records.  
     *
     * This method removes the member, their site allocations, site fee accounts, payments,
     * payment tenders and unmatched any linked prepayments. It wraps all operations in a
     * transaction to ensure data integrity.
     */
    public function delete($id) {
        $db = Database::connect();
        $db->beginTransaction();
        try {
            // Remove site allocations for this member
            $stmt = $db->prepare("DELETE FROM site_allocations WHERE member_id = ?");
            $stmt->execute([$id]);

            // Remove site fee accounts for this member
            $stmt = $db->prepare("DELETE FROM site_fee_accounts WHERE member_id = ?");
            $stmt->execute([$id]);

            // Find all payment IDs for this member
            $stmt = $db->prepare("SELECT id FROM payments WHERE member_id = ?");
            $stmt->execute([$id]);
            $paymentIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // Delete payment tenders linked to these payments
            if ($paymentIds) {
                $placeholders = implode(',', array_fill(0, count($paymentIds), '?'));
                $deleteTenders = $db->prepare("DELETE FROM payment_tenders WHERE payment_id IN ($placeholders)");
                $deleteTenders->execute($paymentIds);
            }

            // Delete payments
            $stmt = $db->prepare("DELETE FROM payments WHERE member_id = ?");
            $stmt->execute([$id]);

            // Unmatch prepayments linked to this member
            $stmt = $db->prepare("UPDATE prepayments SET matched_member_id = NULL, status = 'Unmatched' WHERE matched_member_id = ?");
            $stmt->execute([$id]);

            // Finally, delete the member record
            $stmt = $db->prepare("DELETE FROM members WHERE id = ?");
            $stmt->execute([$id]);

            $db->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function deleteAll() {
        $db = Database::connect();
        $db->beginTransaction();
        try {
            // Delete dependencies first to satisfy Foreign Keys
            $db->exec("DELETE FROM site_allocations");
            $db->exec("DELETE FROM site_fee_accounts");
            
            // For payments, we need to delete tenders first if cascading isn't set
            // And payments might be linked to members.
            // Let's delete all payments for simplicity as this is a reset tool.
            $db->exec("DELETE FROM payment_tenders");
            $db->exec("DELETE FROM payments");
            
            // Prepayments - just unmatch? Or delete? 
            // If they are imported prepayments, maybe keep them but set matched_member_id to NULL
            $db->exec("UPDATE prepayments SET matched_member_id = NULL, status = 'Unmatched'");

            $db->exec("DELETE FROM members");
            
            $db->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
