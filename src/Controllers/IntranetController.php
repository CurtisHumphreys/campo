<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Auth.php';

class IntranetController {

    /**
     * Public endpoint: returns intranet content for the currently active camp.
     * No login required.
     */
    public function publicActive() {
        if (!headers_sent()) header('Content-Type: application/json');

        try {
            $db = Database::connect();

            $campStmt = $db->query("SELECT * FROM camps WHERE status='Active' ORDER BY start_date DESC LIMIT 1");
            $camp = $campStmt ? $campStmt->fetch() : null;

            if (!$camp) {
                echo json_encode([
                    'camp' => null,
                    'content' => [
                        'program' => '',
                        'notifications' => '',
                        'events' => ''
                    ]
                ]);
                return;
            }

            $stmt = $db->prepare("SELECT program, notifications, events, updated_at FROM camp_intranet_content WHERE camp_id = ? LIMIT 1");
            $stmt->execute([$camp['id']]);
            $content = $stmt->fetch();

            echo json_encode([
                'camp' => [
                    'id' => $camp['id'],
                    'name' => $camp['name'],
                    'year' => $camp['year'],
                    'start_date' => $camp['start_date'],
                    'end_date' => $camp['end_date'],
                ],
                'content' => [
                    'program' => $content['program'] ?? '',
                    'notifications' => $content['notifications'] ?? '',
                    'events' => $content['events'] ?? '',
                    'updated_at' => $content['updated_at'] ?? null
                ]
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Server error', 'error' => $e->getMessage()]);
        }
    }

    /**
     * Public endpoint: minimal site map data for displaying pins.
     * No login required.
     */
    public function publicSitesMap() {
        if (!headers_sent()) header('Content-Type: application/json');

        try {
            $db = Database::connect();

            // Occupants are returned because the public map currently displays them.
            // If you later decide this should be anonymised, we can switch this to counts only.
            $sitesStmt = $db->query("SELECT id, site_number, site_type, status, map_x, map_y FROM sites");
            $sites = $sitesStmt ? $sitesStmt->fetchAll() : [];

            // Basic occupants string, consistent with internal sites endpoint behaviour
            // (occupants are derived from allocations and member names)
            $occBySite = [];
            try {
                $occStmt = $db->query("
                    SELECT a.site_id, GROUP_CONCAT(CONCAT(m.first_name, ' ', m.last_name) SEPARATOR ', ') AS occupants
                    FROM allocations a
                    LEFT JOIN members m ON m.id = a.member_id
                    WHERE a.site_id IS NOT NULL
                    GROUP BY a.site_id
                ");
                $rows = $occStmt ? $occStmt->fetchAll() : [];
                foreach ($rows as $r) {
                    $occBySite[$r['site_id']] = $r['occupants'];
                }
            } catch (Exception $e) {
                // Occupants are optional; continue.
            }

            foreach ($sites as &$s) {
                $s['occupants'] = $occBySite[$s['id']] ?? '';
            }

            echo json_encode($sites);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Server error', 'error' => $e->getMessage()]);
        }
    }

    /**
     * Admin endpoint: get intranet content for active camp.
     */
    public function adminGet() {
        if (!headers_sent()) header('Content-Type: application/json');

        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorised']);
            return;
        }

        try {
            $db = Database::connect();

            $campStmt = $db->query("SELECT * FROM camps WHERE status='Active' ORDER BY start_date DESC LIMIT 1");
            $camp = $campStmt ? $campStmt->fetch() : null;

            if (!$camp) {
                echo json_encode([
                    'camp' => null,
                    'content' => [
                        'program' => '',
                        'notifications' => '',
                        'events' => ''
                    ]
                ]);
                return;
            }

            $stmt = $db->prepare("SELECT program, notifications, events, updated_at FROM camp_intranet_content WHERE camp_id = ? LIMIT 1");
            $stmt->execute([$camp['id']]);
            $content = $stmt->fetch();

            echo json_encode([
                'camp' => $camp,
                'content' => [
                    'program' => $content['program'] ?? '',
                    'notifications' => $content['notifications'] ?? '',
                    'events' => $content['events'] ?? '',
                    'updated_at' => $content['updated_at'] ?? null
                ]
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Server error', 'error' => $e->getMessage()]);
        }
    }

    /**
     * Admin endpoint: save intranet content for active camp.
     */
    public function adminSave() {
        if (!headers_sent()) header('Content-Type: application/json');

        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorised']);
            return;
        }

        try {
            $db = Database::connect();

            $campStmt = $db->query("SELECT * FROM camps WHERE status='Active' ORDER BY start_date DESC LIMIT 1");
            $camp = $campStmt ? $campStmt->fetch() : null;

            if (!$camp) {
                http_response_code(400);
                echo json_encode(['message' => 'No active camp set']);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input)) $input = [];

            $program = $input['program'] ?? '';
            $notifications = $input['notifications'] ?? '';
            $events = $input['events'] ?? '';

            // Upsert
            $stmt = $db->prepare("
                INSERT INTO camp_intranet_content (camp_id, program, notifications, events)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE program = VALUES(program), notifications = VALUES(notifications), events = VALUES(events)
            ");
            $stmt->execute([$camp['id'], $program, $notifications, $events]);

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Server error', 'error' => $e->getMessage()]);
        }
    }
}
