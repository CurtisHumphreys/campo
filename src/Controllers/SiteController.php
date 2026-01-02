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
            } catch (Throwable $eOcc) {
                $occBySite = [];
            }

            // 3) Merge
            foreach ($sites as &$s) {
                $sid = $s['id'] ?? null;
                $s['occupants_list'] = ($sid !== null && isset($occBySite[$sid])) ? $occBySite[$sid] : [];
                $names = array_map(function($o) { return $o['name']; }, $s['occupants_list']);
                $s['occupants'] = implode(', ', $names);
            }
            unset($s);

            echo json_encode($sites);
        } catch (Throwable $e) {
            http_response_code(200);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to load sites',
                'debug'   => $e->getMessage(),
            ]);
        }
    }

    public function store() {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        $db = Database::connect();
        $stmt = $db->prepare("INSERT INTO sites (site_number, section, site_type, status) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $data['site_number'] ?? '',
            $data['section'] ?? '',
            $data['site_type'] ?? '',
            $data['status'] ?? 'Available'
        ]);
        echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
    }

    public function update($id) {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        $db = Database::connect();

        if (array_key_exists('map_x', $data) || array_key_exists('map_y', $data)) {
             $stmt = $db->prepare("UPDATE sites SET map_x = ?, map_y = ? WHERE id = ?");
             $mapX = array_key_exists('map_x', $data) ? $data['map_x'] : null;
             $mapY = array_key_exists('map_y', $data) ? $data['map_y'] : null;
             $stmt->execute([$mapX, $mapY, $id]);
        } else {
            $stmt = $db->prepare("UPDATE sites SET site_number = ?, section = ?, site_type = ?, status = ? WHERE id = ?");
            $stmt->execute([
                $data['site_number'] ?? '',
                $data['section'] ?? '',
                $data['site_type'] ?? '',
                $data['status'] ?? 'Available',
                $id
            ]);
        }

        if (isset($data['member_id']) && !empty($data['member_id'])) {
            $memberId = $data['member_id'];
            try {
                $check = $db->prepare("SELECT id FROM site_allocations WHERE site_id = ? AND member_id = ? AND is_current = 1");
                $check->execute([$id, $memberId]);
                if (!$check->fetch()) {
                    $db->prepare("INSERT INTO site_allocations (site_id, member_id, start_date, is_current) VALUES (?, ?, CURDATE(), 1)")
                       ->execute([$id, $memberId]);
                }
                $db->prepare("UPDATE sites SET status = 'Allocated' WHERE id = ?")->execute([$id]);
            } catch (Throwable $eOcc) { }
        }

        echo json_encode(['success' => true]);
    }

    public function allocate() {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        $db = Database::connect();

        $siteId = $data['site_id'] ?? null;
        $memberId = $data['member_id'] ?? null;

        if (!$siteId || !$memberId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing ID']);
            return;
        }

        $check = $db->prepare("SELECT id FROM site_allocations WHERE site_id = ? AND member_id = ? AND is_current = 1");
        $check->execute([$siteId, $memberId]);
        if (!$check->fetch()) {
            $db->prepare("INSERT INTO site_allocations (site_id, member_id, start_date, is_current) VALUES (?, ?, CURDATE(), 1)")
               ->execute([$siteId, $memberId]);
        }
        $db->prepare("UPDATE sites SET status = 'Allocated' WHERE id = ?")->execute([$siteId]);
        
        echo json_encode(['success' => true]);
    }

    public function deallocate() {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        $db = Database::connect();

        $siteId = $data['site_id'] ?? null;
        $memberId = $data['member_id'] ?? null;

        if (!$siteId || !$memberId) {
             http_response_code(400);
             echo json_encode(['success' => false, 'message' => 'Missing ID']);
             return;
        }

        $db->prepare("UPDATE site_allocations SET is_current = 0, end_date = NOW() WHERE site_id = ? AND member_id = ? AND is_current = 1")
           ->execute([$siteId, $memberId]);
           
        $check = $db->prepare("SELECT count(*) FROM site_allocations WHERE site_id = ? AND is_current = 1");
        $check->execute([$siteId]);
        if ($check->fetchColumn() == 0) {
            $db->prepare("UPDATE sites SET status = 'Available' WHERE id = ?")->execute([$siteId]);
        }

        echo json_encode(['success' => true]);
    }

    // Waitlist Methods
    public function waitlist() {
        $db = Database::connect();
        $stmt = $db->query("SELECT * FROM waitlist ORDER BY created_at DESC");
        echo json_encode($stmt->fetchAll());
    }

    public function storeWaitlist() {
        $data = json_decode(file_get_contents('php://input'), true);
        $db = Database::connect();
        $stmt = $db->prepare("INSERT INTO waitlist (first_name, last_name, site_type, adults, kids, special_considerations, intended_days, home_assembly, overflow_willing, subscription_willing, additional_comments) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $data['first_name'],
            $data['last_name'],
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

    // New method to update priority
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
        if(!$id) return;
        $db = Database::connect();
        $db->prepare("DELETE FROM waitlist WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
    }
}