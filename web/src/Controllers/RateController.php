<?php

require_once __DIR__ . '/../Database.php';

class RateController {
    public function index($campId) {
        $db = Database::connect();
        $stmt = $db->prepare("SELECT * FROM camp_rates WHERE camp_id = ? ORDER BY category, item, user_type");
        $stmt->execute([$campId]);
        echo json_encode($stmt->fetchAll());
    }

    public function store() {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!isset($data['camp_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Camp ID required']);
            return;
        }

        $db = Database::connect();
        $stmt = $db->prepare("INSERT INTO camp_rates (camp_id, category, item, user_type, amount) VALUES (?, ?, ?, ?, ?)");
        
        try {
            $stmt->execute([
                $data['camp_id'],
                $data['category'],
                $data['item'],
                $data['user_type'],
                $data['amount']
            ]);
            echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function update($id) {
        $data = json_decode(file_get_contents('php://input'), true);
        $db = Database::connect();
        $stmt = $db->prepare("UPDATE camp_rates SET category=?, item=?, user_type=?, amount=? WHERE id=?");
        
        try {
            $stmt->execute([
                $data['category'],
                $data['item'],
                $data['user_type'],
                $data['amount'],
                $id
            ]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function delete($id) {
        $db = Database::connect();
        $stmt = $db->prepare("DELETE FROM camp_rates WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
    }

    // Copy rates from one camp to another (optional helper)
    public function cloneRates() {
        $data = json_decode(file_get_contents('php://input'), true);
        $fromId = $data['from_camp_id'];
        $toId = $data['to_camp_id'];

        $db = Database::connect();
        $rates = $db->prepare("SELECT * FROM camp_rates WHERE camp_id = ?");
        $rates->execute([$fromId]);

        $insert = $db->prepare("INSERT INTO camp_rates (camp_id, category, item, user_type, amount) VALUES (?, ?, ?, ?, ?)");
        
        foreach ($rates->fetchAll() as $rate) {
            $insert->execute([
                $toId,
                $rate['category'],
                $rate['item'],
                $rate['user_type'],
                $rate['amount']
            ]);
        }
        echo json_encode(['success' => true]);
    }
}
