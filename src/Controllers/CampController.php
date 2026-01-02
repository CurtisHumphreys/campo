<?php

require_once __DIR__ . '/../Database.php';

class CampController {
    public function index() {
        $db = Database::connect();
        $stmt = $db->query("SELECT * FROM camps ORDER BY start_date DESC");
        echo json_encode($stmt->fetchAll());
    }

    public function active() {
        $db = Database::connect();
        $stmt = $db->query("SELECT * FROM camps WHERE status = 'Active' ORDER BY start_date DESC");
        echo json_encode($stmt->fetchAll());
    }

    public function store() {
        $data = json_decode(file_get_contents('php://input'), true);
        $db = Database::connect();
        
        $stmt = $db->prepare("INSERT INTO camps (name, year, start_date, end_date, on_peak_start, on_peak_end, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $data['name'],
            $data['year'],
            $data['start_date'],
            $data['end_date'],
            $data['on_peak_start'],
            $data['on_peak_end'],
            $data['status']
        ]);
        
        $campId = $db->lastInsertId();

        // Add Rates if provided
        if (isset($data['rates']) && is_array($data['rates'])) {
            $stmtRate = $db->prepare("INSERT INTO camp_rates (camp_id, rate_type, amount) VALUES (?, ?, ?)");
            foreach ($data['rates'] as $rate) {
                $stmtRate->execute([$campId, $rate['type'], $rate['amount']]);
            }
        }

        echo json_encode(['success' => true, 'id' => $campId]);
    }
    
    public function rates($id) {
        $db = Database::connect();
        $stmt = $db->prepare("SELECT * FROM camp_rates WHERE camp_id = ?");
        $stmt->execute([$id]);
        echo json_encode($stmt->fetchAll());
    }

    public function update($id) {
        $data = json_decode(file_get_contents('php://input'), true);
        $db = Database::connect();
        
        $stmt = $db->prepare("UPDATE camps SET name = ?, year = ?, start_date = ?, end_date = ?, on_peak_start = ?, on_peak_end = ?, status = ? WHERE id = ?");
        $stmt->execute([
            $data['name'],
            $data['year'],
            $data['start_date'],
            $data['end_date'],
            $data['on_peak_start'],
            $data['on_peak_end'],
            $data['status'],
            $id
        ]);
        
        echo json_encode(['success' => true]);
    }

    public function delete($id) {
        $db = Database::connect();
        // Maybe check for dependencies (payments etc) first?
        // strict deletion for now as requested
        $stmt = $db->prepare("DELETE FROM camps WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
    }
}
