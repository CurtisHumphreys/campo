<?php

require_once __DIR__ . '/../Database.php';

class SiteController {
    public function index() {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }

        try {
            $db = Database::connect();

            // 1) Fetch sites
            $sitesStmt = $db->query("SELECT * FROM sites");
            $sites = $sitesStmt ? $sitesStmt->fetchAll() : [];

            // 2) Fetch occupants
            $occBySite = [];
            try {
                $occStmt = $db->query(
                    "SELECT sa.site_id, sa.member_id, m.first_name, m.last_name
                     FROM site_allocations sa
                     JOIN members m ON m.id = sa.member_id
                     WHERE sa.is_current = 1
                     ORDER BY m.last_name, m.first_name"
                );

                if ($occStmt) {
                    $rows = $occStmt->fetchAll();
                    foreach ($rows as $r) {
                        $sid = $r['site_id'];
                        $occObj = [
                            'id' => $r['member_id'],
                            'name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))
                        ];
                        if (!isset($occBySite[$sid])) $occBySite[$sid] = [];
                        $occBySite[$sid][] = $occObj;
                    }
                }
            } catch (Exception $e) {
                // table might not exist yet
            }

            // 3) Merge
            foreach ($sites as &$site) {
                $sid = $site['id'];
                $site['occupants'] = $occBySite[$sid] ?? [];
                
                // Helper strings for UI
                $names = array_map(function($o) { return $o['name']; }, $site['occupants']);
                $site['occupant_name'] = implode(', ', $names);
                $site['occupant_id'] = count($site['occupants']) > 0 ? $site['occupants'][0]['id'] : null;
            }

            echo json_encode($sites);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    // --- NEW METHOD FOR PUBLIC MAP ---
    public function publicMap() {
        $db = Database::connect();
        
        // Return simplified data for public view
        $sql = "SELECT s.id, s.site_number, s.map_x, s.map_y, s.type,
                       (CASE 
                           WHEN sa.id IS NOT NULL THEN 'Occupied' 
                           ELSE 'Available' 
                        END) as status,
                       CONCAT(m.first_name, ' ', m.last_name) as occupant_name
                FROM sites s
                LEFT JOIN site_allocations sa ON s.id = sa.site_id AND sa.is_current = 1
                LEFT JOIN members m ON sa.member_id = m.id";
                
        $stmt = $db->query($sql);
        $data = $stmt->fetchAll();
        
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode($data);
    }

    public function store() {
        // ... existing store code ...
        $data = json_decode(file_get_contents('php://input'), true);
        $db = Database::connect();
        
        $stmt = $db->prepare("INSERT INTO sites (site_number, type, power, water, sewer, map_x, map_y) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $data['site_number'],
            $data['type'],
            $data['power'] ?? 'No',
            $data['water'] ?? 'No',
            $data['sewer'] ?? 'No',
            $data['map_x'] ?? null,
            $data['map_y'] ?? null
        ]);
        
        echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
    }

    public function updateMapCoords($id) {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($data['map_x']) || !isset($data['map_y'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing coords']);
            return;
        }

        $db = Database::connect();
        $stmt = $db->prepare("UPDATE sites SET map_x = ?, map_y = ? WHERE id = ?");
        $stmt->execute([$data['map_x'], $data['map_y'], $id]);
        
        echo json_encode(['success' => true]);
    }

    // ... existing waitlist methods ...
    public function submitWaitlist() {
        $data = json_decode(file_get_contents('php://input'), true);
        $db = Database::connect();
        $stmt = $db->prepare("INSERT INTO waitlist (first_name, last_name, phone, site_type, adults, kids, special_considerations, intended_days, home_assembly, overflow_willing, subscription_willing, additional_comments) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $data['first_name'],
            $data['last_name'],
            $data['phone'] ?? '',
            $data['site_type'],
            $data['adults'],
            $data['kids'],
            $data['special_considerations'],
            $data['intended_days'],
            $data['home_assembly'],
            $data['overflow_willing'],
            $data['subscription_willing'],
            $data['additional_comments']
        ]);
        echo json_encode(['success' => true]);
    }

    public function updateWaitlist() {
        $data = json_decode(file_get_contents('php://input'), true);
        $id = $_GET['id'] ?? null;
        
        if (!$id || !isset($data['priority'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing ID or Priority']);
            return;
        }

        $db = Database::connect();
        $stmt = $db->prepare("UPDATE waitlist SET priority = ? WHERE id = ?");
        $stmt->execute([$data['priority'], $id]);
        
        echo json_encode(['success' => true]);
    }

    public function deleteWaitlist() {
        $id = $_GET['id'] ?? null;
        if (!$id) return;
        $db = Database::connect();
        $stmt = $db->prepare("DELETE FROM waitlist WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
    }
}
