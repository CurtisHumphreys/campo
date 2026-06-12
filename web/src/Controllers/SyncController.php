<?php

require_once __DIR__ . '/../Database.php';

/**
 * SyncController — authenticated sync API for the Campo public intranet.
 * All routes require a valid X-Sync-Key header matching sync_api_keys.api_key.
 */
class SyncController {

    private function sendJsonHeaders(): void {
        if (!headers_sent()) {
            header('Content-Type: application/json');
            header('Cache-Control: no-store, no-cache, must-revalidate');
        }
    }

    private function readInput(): array {
        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($ct, 'application/json') !== false) {
            $d = json_decode(file_get_contents('php://input'), true);
            return is_array($d) ? $d : [];
        }
        return is_array($_POST) ? $_POST : [];
    }

    private function requireSyncKey(): void {
        $key = trim((string)($_SERVER['HTTP_X_SYNC_KEY'] ?? ''));
        if ($key === '') {
            $this->abort(401, 'Missing sync key');
        }
        try {
            $db   = Database::connect();
            $stmt = $db->prepare("SELECT id FROM sync_api_keys WHERE api_key = ? AND is_active = 1 LIMIT 1");
            $stmt->execute([$key]);
            $row  = $stmt->fetch();
            if (!$row) {
                $this->abort(403, 'Invalid or inactive sync key');
            }
            $db->prepare("UPDATE sync_api_keys SET last_used_at = NOW() WHERE id = ?")->execute([$row['id']]);
        } catch (Exception $e) {
            $this->abort(500, 'Key validation error');
        }
    }

    private function abort(int $status, string $message): never {
        $this->sendJsonHeaders();
        http_response_code($status);
        echo json_encode(['error' => $message]);
        exit;
    }

    private function getActiveCamp($db): ?array {
        $stmt = $db->query("SELECT id, name, year, start_date, end_date, banner_image, location, emergency_contact, first_aid_location FROM camps WHERE status = 'active' ORDER BY start_date DESC LIMIT 1");
        $row  = $stmt ? $stmt->fetch() : null;
        return $row ?: null;
    }

    // ── GET /api/sync/camp ────────────────────────────────────────────────────

    public function camp(): never {
        $this->requireSyncKey();
        $this->sendJsonHeaders();
        try {
            $db   = Database::connect();
            $camp = $this->getActiveCamp($db);
            if (!$camp) {
                echo json_encode(null);
                exit;
            }
            $attendance = (int)$db->query("SELECT COUNT(DISTINCT member_id) FROM site_allocations WHERE is_current = 1")->fetchColumn();
            echo json_encode([
                'id'                => (int)$camp['id'],
                'name'              => $camp['name'],
                'year'              => $camp['year'],
                'start_date'        => $camp['start_date'],
                'end_date'          => $camp['end_date'],
                'banner_image'      => $camp['banner_image'] ?: null,
                'location'          => $camp['location'] ?: null,
                'emergency_contact' => $camp['emergency_contact'] ?: null,
                'first_aid_location'=> $camp['first_aid_location'] ?: null,
                'attendance'        => $attendance ?: null,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // ── GET /api/sync/schedule ────────────────────────────────────────────────

    public function schedule(): never {
        $this->requireSyncKey();
        $this->sendJsonHeaders();
        try {
            $db   = Database::connect();
            $camp = $this->getActiveCamp($db);
            if (!$camp) {
                echo json_encode(['camp' => null, 'sessions' => []]);
                exit;
            }
            $stmt = $db->prepare("SELECT id, date, title, start_time, end_time, location, description, session_type FROM program_sessions WHERE camp_id = ? ORDER BY date ASC, start_time ASC");
            $stmt->execute([(int)$camp['id']]);
            $sessions = $stmt->fetchAll();
            foreach ($sessions as &$s) {
                $s['id'] = (int)$s['id'];
            }
            unset($s);
            echo json_encode([
                'camp'     => [
                    'id'         => (int)$camp['id'],
                    'name'       => $camp['name'],
                    'start_date' => $camp['start_date'],
                    'end_date'   => $camp['end_date'],
                ],
                'sessions' => $sessions,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // ── GET /api/sync/features  (noticeboard + polls + lost-found) ────────────

    public function features(): never {
        $this->requireSyncKey();
        $this->sendJsonHeaders();
        try {
            $db   = Database::connect();
            $camp = $this->getActiveCamp($db);
            if (!$camp) {
                echo json_encode(['noticeboard' => [], 'polls' => [], 'lost_found' => []]);
                exit;
            }
            $campId = (int)$camp['id'];

            $noticeboard = $this->fetchNoticeboard($db, $campId);
            $polls       = $this->fetchPolls($db, $campId);
            $lostFound   = $this->fetchLostFound($db, $campId);

            echo json_encode([
                'noticeboard' => $noticeboard,
                'polls'       => $polls,
                'lost_found'  => $lostFound,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    private function fetchNoticeboard($db, int $campId): array {
        $stmt = $db->prepare("
            SELECT id, category, title, message, contact_details, author_name, site_number,
                   status, approved_at, expires_at, created_at
            FROM camp_intranet_noticeboard
            WHERE camp_id = ? AND status = 'approved'
              AND (expires_at IS NULL OR expires_at >= NOW())
            ORDER BY COALESCE(expires_at, '9999-12-31') ASC, approved_at DESC, created_at DESC
        ");
        $stmt->execute([$campId]);
        return $stmt->fetchAll() ?: [];
    }

    private function fetchPolls($db, int $campId): array {
        $stmt = $db->prepare("
            SELECT id, title, description, poll_type, status, show_results_public, closes_at, created_at
            FROM camp_intranet_polls
            WHERE camp_id = ? AND status = 'live'
              AND (closes_at IS NULL OR closes_at >= NOW())
            ORDER BY COALESCE(closes_at, '9999-12-31') ASC, created_at DESC
        ");
        $stmt->execute([$campId]);
        $polls = $stmt->fetchAll() ?: [];
        if (!$polls) return [];

        $pollIds = array_map(fn($p) => (int)$p['id'], $polls);
        $marks   = implode(',', array_fill(0, count($pollIds), '?'));
        $optStmt = $db->prepare("
            SELECT o.id, o.poll_id, o.label, o.sort_order, COUNT(r.id) AS vote_count
            FROM camp_intranet_poll_options o
            LEFT JOIN camp_intranet_poll_responses r ON r.option_id = o.id
            WHERE o.poll_id IN ($marks)
            GROUP BY o.id, o.poll_id, o.label, o.sort_order
            ORDER BY o.poll_id, o.sort_order, o.id
        ");
        $optStmt->execute($pollIds);
        $optsByPoll = [];
        foreach ($optStmt->fetchAll() as $row) {
            $pid = (int)$row['poll_id'];
            $optsByPoll[$pid][] = [
                'id'         => (int)$row['id'],
                'label'      => $row['label'],
                'sort_order' => (int)$row['sort_order'],
                'vote_count' => (int)$row['vote_count'],
            ];
        }
        foreach ($polls as &$poll) {
            $pid = (int)$poll['id'];
            $poll['id']                  = $pid;
            $poll['show_results_public'] = (int)$poll['show_results_public'];
            $poll['options']             = $optsByPoll[$pid] ?? [];
        }
        unset($poll);
        return $polls;
    }

    private function fetchLostFound($db, int $campId): array {
        $stmt = $db->prepare("
            SELECT id, item_type, title, description, location_details, contact_details,
                   reporter_name, site_number, status, approved_at, created_at
            FROM camp_intranet_lost_found
            WHERE camp_id = ? AND status = 'approved'
            ORDER BY COALESCE(approved_at, created_at) DESC
        ");
        $stmt->execute([$campId]);
        return $stmt->fetchAll() ?: [];
    }

    // ── POST submits — validate key then delegate to public handlers ───────────

    public function submitNoticeboard(): never {
        $this->requireSyncKey();
        (new IntranetFeaturesController())->publicSubmitNoticeboard();
    }

    public function submitLostFound(): never {
        $this->requireSyncKey();
        (new IntranetFeaturesController())->publicSubmitLostFound();
    }

    public function submitMessage(): never {
        $this->requireSyncKey();
        (new IntranetFeaturesController())->publicSubmitMessage();
    }

    public function pollVote(): never {
        $this->requireSyncKey();
        (new IntranetFeaturesController())->publicSubmitPollResponse();
    }

    // ── POST /api/sync/push/subscribe ─────────────────────────────────────────

    public function pushSubscribe(): never {
        $this->requireSyncKey();
        $this->sendJsonHeaders();
        try {
            $input    = $this->readInput();
            $endpoint = trim((string)($input['endpoint'] ?? ''));
            $p256dh   = trim((string)($input['p256dh']   ?? ''));
            $auth     = trim((string)($input['auth']     ?? ''));
            $tier     = in_array($input['tier'] ?? '', ['all', 'main', 'critical'])
                        ? $input['tier'] : 'main';

            if (!$endpoint || !$p256dh || !$auth) {
                http_response_code(422);
                echo json_encode(['error' => 'endpoint, p256dh, and auth are required']);
                exit;
            }

            $db     = Database::connect();
            $camp   = $db->query("SELECT id FROM camps WHERE status = 'active' LIMIT 1")->fetch();
            $campId = $camp ? (int)$camp['id'] : 0;

            $db->prepare("
                INSERT INTO push_subscriptions (camp_id, endpoint, p256dh, auth, notification_tier)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    camp_id           = VALUES(camp_id),
                    p256dh            = VALUES(p256dh),
                    auth              = VALUES(auth),
                    notification_tier = VALUES(notification_tier),
                    updated_at        = CURRENT_TIMESTAMP
            ")->execute([$campId, $endpoint, $p256dh, $auth, $tier]);

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }
}
