<?php

require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Database.php';

class CampController {
    public function index() {
        if (!$this->requireAuth()) {
            return;
        }

        $db = Database::connect();
        $stmt = $db->query("SELECT * FROM camps ORDER BY start_date DESC, id DESC");
        echo json_encode($stmt->fetchAll());
    }

    public function active() {
        if (!$this->requireAuth()) {
            return;
        }

        $db = Database::connect();
        $stmt = $db->query("SELECT * FROM camps WHERE LOWER(status) = 'active' ORDER BY start_date DESC, id DESC");
        echo json_encode($stmt->fetchAll());
    }

    public function store() {
        if (!$this->requireAuth()) {
            return;
        }

        $data = $this->jsonInput();
        $db = Database::connect();
        $churchSuite = $this->normaliseChurchSuiteFields($data);

        $stmt = $db->prepare("
            INSERT INTO camps (
                name, year, start_date, end_date, on_peak_start, on_peak_end, status,
                churchsuite_event_id, churchsuite_event_identifier, churchsuite_event_name
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['name'],
            $data['year'],
            $data['start_date'],
            $data['end_date'],
            $data['on_peak_start'] ?: null,
            $data['on_peak_end'] ?: null,
            $data['status'],
            $churchSuite['churchsuite_event_id'],
            $churchSuite['churchsuite_event_identifier'],
            $churchSuite['churchsuite_event_name']
        ]);

        $campId = $db->lastInsertId();

        if (isset($data['rates']) && is_array($data['rates'])) {
            $stmtRate = $db->prepare("INSERT INTO camp_rates (camp_id, rate_type, amount) VALUES (?, ?, ?)");
            foreach ($data['rates'] as $rate) {
                $stmtRate->execute([$campId, $rate['type'], $rate['amount']]);
            }
        }

        echo json_encode(['success' => true, 'id' => (int)$campId]);
    }

    public function rates($id) {
        if (!$this->requireAuth()) {
            return;
        }

        $db = Database::connect();
        $stmt = $db->prepare("SELECT * FROM camp_rates WHERE camp_id = ?");
        $stmt->execute([(int)$id]);
        echo json_encode($stmt->fetchAll());
    }

    public function update($id) {
        if (!$this->requireAuth()) {
            return;
        }

        $data = $this->jsonInput();
        $db = Database::connect();
        $campId = (int)$id;
        $current = $this->findCamp($db, $campId);
        if (!$current) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Camp not found.']);
            return;
        }

        $churchSuite = $this->normaliseChurchSuiteFields($data);

        try {
            $this->assertChurchSuiteRelinkAllowed($db, $current, $churchSuite);
        } catch (RuntimeException $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            return;
        }

        $stmt = $db->prepare("
            UPDATE camps
            SET name = ?, year = ?, start_date = ?, end_date = ?, on_peak_start = ?, on_peak_end = ?, status = ?,
                churchsuite_event_id = ?, churchsuite_event_identifier = ?, churchsuite_event_name = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $data['name'],
            $data['year'],
            $data['start_date'],
            $data['end_date'],
            $data['on_peak_start'] ?: null,
            $data['on_peak_end'] ?: null,
            $data['status'],
            $churchSuite['churchsuite_event_id'],
            $churchSuite['churchsuite_event_identifier'],
            $churchSuite['churchsuite_event_name'],
            $campId
        ]);

        echo json_encode(['success' => true]);
    }

    public function delete($id) {
        if (!$this->requireAuth()) {
            return;
        }

        $db = Database::connect();
        $stmt = $db->prepare("DELETE FROM camps WHERE id = ?");
        $stmt->execute([(int)$id]);
        echo json_encode(['success' => true]);
    }

    private function requireAuth() {
        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return false;
        }
        return true;
    }

    private function jsonInput() {
        $data = json_decode(file_get_contents('php://input'), true);
        return is_array($data) ? $data : [];
    }

    private function normaliseChurchSuiteFields(array $data) {
        $eventId = isset($data['churchsuite_event_id']) && $data['churchsuite_event_id'] !== ''
            ? (int)$data['churchsuite_event_id']
            : null;
        if ($eventId !== null && $eventId <= 0) {
            $eventId = null;
        }

        $identifier = trim((string)($data['churchsuite_event_identifier'] ?? ''));
        $eventName = trim((string)($data['churchsuite_event_name'] ?? ''));

        if ($identifier === '' && !$eventId && isset($data['churchsuite_event_ref'])) {
            $rawRef = trim((string)$data['churchsuite_event_ref']);
            if ($rawRef !== '' && !ctype_digit($rawRef)) {
                $identifier = $rawRef;
            }
        }

        if ($eventName === '') {
            $eventName = null;
        }
        if ($identifier === '') {
            $identifier = null;
        }

        if ($eventId === null && $identifier === null) {
            $eventName = null;
        }

        return [
            'churchsuite_event_id' => $eventId,
            'churchsuite_event_identifier' => $identifier,
            'churchsuite_event_name' => $eventName
        ];
    }

    private function findCamp(PDO $db, $campId) {
        $stmt = $db->prepare("SELECT * FROM camps WHERE id = ?");
        $stmt->execute([$campId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function assertChurchSuiteRelinkAllowed(PDO $db, array $current, array $next) {
        $currentRef = $this->churchSuiteReferenceKey($current['churchsuite_event_id'] ?? null, $current['churchsuite_event_identifier'] ?? null);
        $nextRef = $this->churchSuiteReferenceKey($next['churchsuite_event_id'], $next['churchsuite_event_identifier']);

        if ($currentRef === $nextRef) {
            return;
        }

        if (!$this->hasPrepaymentColumn($db, 'source_system')) {
            throw new RuntimeException('Database migration required before linking ChurchSuite events. Open /api/migrate once, then retry.');
        }

        $stmt = $db->prepare("SELECT COUNT(*) FROM prepayments WHERE camp_id = ? AND source_system = 'churchsuite'");
        $stmt->execute([(int)$current['id']]);
        $syncedCount = (int)$stmt->fetchColumn();

        if ($syncedCount > 0) {
            throw new RuntimeException('This camp already has ChurchSuite-synced pre-payments. Clear the camp pre-payments before changing or removing the linked event.');
        }
    }

    private function churchSuiteReferenceKey($eventId, $identifier) {
        $eventId = $eventId !== null && $eventId !== '' ? (string)(int)$eventId : '';
        if ($eventId !== '' && $eventId !== '0') {
            return 'id:' . $eventId;
        }

        $identifier = trim((string)$identifier);
        return $identifier !== '' ? 'identifier:' . strtolower($identifier) : '';
    }

    private function hasPrepaymentColumn(PDO $db, $column) {
        static $cache = [];

        if (array_key_exists($column, $cache)) {
            return $cache[$column];
        }

        $stmt = $db->query("SHOW COLUMNS FROM `prepayments` LIKE " . $db->quote($column));
        $cache[$column] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);

        return $cache[$column];
    }
}
