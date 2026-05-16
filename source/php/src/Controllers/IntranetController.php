<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../SiteMapOccupantService.php';

class IntranetController {
    private function sendJsonHeaders() {
        if (!headers_sent()) {
            header('Content-Type: application/json');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
            $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
            if (in_array($origin, ['https://campo.urbantek.online', 'http://campo.urbantek.online', 'https://campo.nix.local', 'http://campo.nix.local'], true)) {
                header('Access-Control-Allow-Origin: ' . $origin);
                header('Vary: Origin');
            }
        }
    }

    private function ensureSchema($db) {
        $db->exec("CREATE TABLE IF NOT EXISTS camp_intranet_content (
            id INT AUTO_INCREMENT PRIMARY KEY,
            camp_id INT NOT NULL UNIQUE,
            program TEXT,
            program_schedule LONGTEXT NULL,
            program_image_path VARCHAR(255) NULL,
            notifications TEXT,
            events TEXT,
            theme_image_path VARCHAR(255) NULL,
            between_camps_mode TINYINT(1) NOT NULL DEFAULT 0,
            between_camps_checkout_url VARCHAR(255) NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (camp_id) REFERENCES camps(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS intranet_page_visits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_key VARCHAR(80) NOT NULL UNIQUE,
            visit_path VARCHAR(120) NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_intranet_page_visits_last_seen (last_seen_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS camp_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            camp_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            date DATE NULL,
            start_time TIME NULL,
            end_time TIME NULL,
            location VARCHAR(255) NULL,
            session_type VARCHAR(50) NULL DEFAULT 'general',
            description TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_camp_sessions_camp_date (camp_id, date, start_time),
            FOREIGN KEY (camp_id) REFERENCES camps(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        try {
            $stmt = $db->query("SHOW COLUMNS FROM camp_intranet_content LIKE 'theme_image_path'");
            if ($stmt && $stmt->rowCount() === 0) {
                $db->exec("ALTER TABLE camp_intranet_content ADD COLUMN theme_image_path VARCHAR(255) NULL AFTER events");
            }
        } catch (Exception $e) {
            // Leave quietly if ALTER fails because column already exists or table is unavailable.
        }

        try {
            $stmt = $db->query("SHOW COLUMNS FROM camp_intranet_content LIKE 'program_schedule'");
            if ($stmt && $stmt->rowCount() === 0) {
                $db->exec("ALTER TABLE camp_intranet_content ADD COLUMN program_schedule LONGTEXT NULL AFTER program");
            }
        } catch (Exception $e) {
            // Leave quietly if ALTER fails because column already exists or table is unavailable.
        }

        try {
            $stmt = $db->query("SHOW COLUMNS FROM camp_intranet_content LIKE 'program_image_path'");
            if ($stmt && $stmt->rowCount() === 0) {
                $db->exec("ALTER TABLE camp_intranet_content ADD COLUMN program_image_path VARCHAR(255) NULL AFTER program_schedule");
            }
        } catch (Exception $e) {
            // Leave quietly if ALTER fails because column already exists or table is unavailable.
        }

        try {
            $stmt = $db->query("SHOW COLUMNS FROM camp_intranet_content LIKE 'between_camps_mode'");
            if ($stmt && $stmt->rowCount() === 0) {
                $db->exec("ALTER TABLE camp_intranet_content ADD COLUMN between_camps_mode TINYINT(1) NOT NULL DEFAULT 0 AFTER theme_image_path");
            }
        } catch (Exception $e) {
            // Leave quietly if ALTER fails because column already exists or table is unavailable.
        }

        try {
            $stmt = $db->query("SHOW COLUMNS FROM camp_intranet_content LIKE 'between_camps_checkout_url'");
            if ($stmt && $stmt->rowCount() === 0) {
                $db->exec("ALTER TABLE camp_intranet_content ADD COLUMN between_camps_checkout_url VARCHAR(255) NULL AFTER between_camps_mode");
            }
        } catch (Exception $e) {
            // Leave quietly if ALTER fails because column already exists or table is unavailable.
        }

        try {
            $stmt = $db->query("SHOW COLUMNS FROM camp_sessions LIKE 'session_type'");
            if ($stmt && $stmt->rowCount() === 0) {
                $db->exec("ALTER TABLE camp_sessions ADD COLUMN session_type VARCHAR(50) NULL DEFAULT 'general' AFTER location");
            }
        } catch (Exception $e) {
            // Leave quietly if ALTER fails because column already exists or table is unavailable.
        }
    }

    private function getActiveCamp($db) {
        $campStmt = $db->query("SELECT * FROM camps WHERE status='active' ORDER BY start_date DESC LIMIT 1");
        return $campStmt ? $campStmt->fetch() : null;
    }

    private function getPublicCamp($db) {
        $camp = $this->getActiveCamp($db);
        if ($camp) return $camp;

        $stmt = $db->query("SELECT c.*
            FROM camps c
            INNER JOIN camp_intranet_content cic ON cic.camp_id = c.id
            ORDER BY COALESCE(cic.updated_at, c.start_date) DESC, c.start_date DESC
            LIMIT 1");
        return $stmt ? $stmt->fetch() : null;
    }

    private function getAdminCamp($db) {
        return $this->getActiveCamp($db) ?: $this->getPublicCamp($db);
    }

    private function readInput() {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $data = json_decode(file_get_contents('php://input'), true);
            return is_array($data) ? $data : [];
        }
        return is_array($_POST) ? $_POST : [];
    }

    private function normaliseUploadedImagePath($path) {
        $path = trim((string) $path);
        if ($path === '') return null;
        $relative = ltrim($path, '/');
        $full = realpath(__DIR__ . '/../../' . $relative);
        $base = realpath(__DIR__ . '/../../');
        if (!$full || !$base || strpos($full, $base) !== 0 || !is_file($full)) {
            return null;
        }
        return '/' . str_replace('\\', '/', $relative);
    }

    private function normaliseThemeImagePath($path) {
        return $this->normaliseUploadedImagePath($path);
    }

    private function normaliseProgramImagePath($path) {
        return $this->normaliseUploadedImagePath($path);
    }

    private function getIntranetTimeZone() {
        try {
            return new DateTimeZone('Australia/Adelaide');
        } catch (Exception $e) {
            return new DateTimeZone(date_default_timezone_get() ?: 'UTC');
        }
    }

    private function buildCampProgramDays($camp) {
        $start = trim((string)($camp['start_date'] ?? ''));
        $end = trim((string)($camp['end_date'] ?? ''));
        if ($start === '' || $end === '') return [];

        $timezone = $this->getIntranetTimeZone();
        $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $start, $timezone);
        $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $end, $timezone);
        if (!$startDate || !$endDate || $endDate < $startDate) return [];

        $days = [];
        for ($cursor = $startDate; $cursor <= $endDate; $cursor = $cursor->modify('+1 day')) {
            $days[] = [
                'date' => $cursor->format('Y-m-d'),
                'short_label' => $cursor->format('D'),
                'display_label' => $cursor->format('D j M'),
                'long_label' => $cursor->format('l j F'),
                'entries' => []
            ];
        }

        return $days;
    }

    private function isAssocArray($value) {
        if (!is_array($value)) return false;
        return array_keys($value) !== range(0, count($value) - 1);
    }

    private function sanitiseProgramEntryRows($rows) {
        if (!is_array($rows)) return [];

        $sanitised = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;

            $time = trim((string)($row['time'] ?? ''));
            $event = trim((string)($row['event'] ?? ''));
            if ($time === '' && $event === '') continue;

            $sanitised[] = [
                'time' => substr($time, 0, 80),
                'event' => substr($event, 0, 255)
            ];
        }

        return $sanitised;
    }

    private function decodeProgramScheduleMap($rawSchedule) {
        if (is_string($rawSchedule)) {
            $rawSchedule = trim($rawSchedule);
            if ($rawSchedule === '') return [];
            $decoded = json_decode($rawSchedule, true);
        } elseif (is_array($rawSchedule)) {
            $decoded = $rawSchedule;
        } else {
            $decoded = [];
        }

        if (!is_array($decoded)) return [];

        $map = [];
        if ($this->isAssocArray($decoded)) {
            foreach ($decoded as $date => $rows) {
                $date = trim((string)$date);
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
                $map[$date] = $this->sanitiseProgramEntryRows($rows);
            }
            return $map;
        }

        foreach ($decoded as $day) {
            if (!is_array($day)) continue;
            $date = trim((string)($day['date'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
            $map[$date] = $this->sanitiseProgramEntryRows($day['entries'] ?? []);
        }

        return $map;
    }

    private function normaliseProgramSchedule($camp, $rawSchedule) {
        $days = $this->buildCampProgramDays($camp);
        if (!$days) return [];

        $map = $this->decodeProgramScheduleMap($rawSchedule);
        foreach ($days as &$day) {
            $day['entries'] = $map[$day['date']] ?? [];
        }
        unset($day);

        return $days;
    }

    private function getStructuredProgramSessions($db, $campId) {
        if (!$campId) return [];

        try {
            $stmt = $db->prepare("
                SELECT id, camp_id, title, date, start_time, end_time, location, description, session_type
                FROM camp_sessions
                WHERE camp_id = ?
                ORDER BY date ASC, start_time ASC, id ASC
            ");
            $stmt->execute([(int)$campId]);
            $rows = $stmt->fetchAll();
        } catch (Exception $e) {
            return [];
        }

        $sessions = [];
        foreach ($rows as $row) {
            $date = trim((string)($row['date'] ?? ''));
            $title = trim((string)($row['title'] ?? ''));
            if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $title === '') continue;

            $start = $this->normaliseSessionTime($row['start_time'] ?? null);
            $end = $this->normaliseSessionTime($row['end_time'] ?? null);
            $type = trim((string)($row['session_type'] ?? 'general')) ?: 'general';
            $location = trim((string)($row['location'] ?? ''));
            $description = trim((string)($row['description'] ?? ''));

            $sessions[] = [
                'id' => (int)($row['id'] ?? 0),
                'date' => $date,
                'start_time' => $start,
                'end_time' => $end,
                'title' => substr($title, 0, 255),
                'event' => substr($title, 0, 255),
                'time' => $this->formatSessionTimeRange($start, $end),
                'location' => substr($location, 0, 255),
                'description' => $description,
                'session_type' => substr($type, 0, 50),
            ];
        }

        return $sessions;
    }

    private function normaliseProgramScheduleFromSessions($camp, $sessions) {
        $days = $this->buildCampProgramDays($camp);
        if (!$days) return [];

        $map = [];
        foreach ($sessions as $session) {
            $date = $session['date'] ?? '';
            if (!isset($map[$date])) $map[$date] = [];
            $map[$date][] = $session;
        }

        foreach ($days as &$day) {
            $day['entries'] = $map[$day['date']] ?? [];
        }
        unset($day);

        return $days;
    }

    private function normaliseSessionTime($time) {
        $time = trim((string)$time);
        if ($time === '') return '';
        if (preg_match('/^(\d{1,2}):(\d{2})/', $time, $matches)) {
            return str_pad($matches[1], 2, '0', STR_PAD_LEFT) . ':' . $matches[2];
        }
        return substr($time, 0, 5);
    }

    private function formatSessionTimeRange($start, $end) {
        if ($start === '' && $end === '') return '';
        if ($end === '') return $start;
        if ($start === '') return $end;
        return $start . '-' . $end;
    }

    private function encodeProgramScheduleForStorage($camp, $rawSchedule) {
        $days = $this->buildCampProgramDays($camp);
        if (!$days) return null;

        $validDates = [];
        foreach ($days as $day) {
            $validDates[$day['date']] = true;
        }

        $incomingMap = $this->decodeProgramScheduleMap($rawSchedule);
        $stored = [];
        foreach ($incomingMap as $date => $rows) {
            if (!isset($validDates[$date]) || !$rows) continue;
            $stored[$date] = $rows;
        }

        if (!$stored) return null;

        return json_encode($stored, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function ensureMapPlaceholdersTable($db) {
        $db->exec("
            CREATE TABLE IF NOT EXISTS site_map_placeholders (
                site_number VARCHAR(20) NOT NULL PRIMARY KEY,
                map_x DECIMAL(5,2) NULL,
                map_y DECIMAL(5,2) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    private function mergePublicMapPlaceholders($db, &$sites) {
        $this->ensureMapPlaceholdersTable($db);

        $placeholderStmt = $db->query("SELECT site_number, map_x, map_y FROM site_map_placeholders");
        $placeholders = $placeholderStmt ? $placeholderStmt->fetchAll() : [];
        if (!$placeholders) return;

        $sitesByNumber = [];
        foreach ($sites as &$site) {
            $key = strtolower(trim((string)($site['site_number'] ?? '')));
            if ($key !== '') {
                $sitesByNumber[$key] = &$site;
            }
        }
        unset($site);

        foreach ($placeholders as $placeholder) {
            $siteNumber = trim((string)($placeholder['site_number'] ?? ''));
            if ($siteNumber === '') continue;

            $key = strtolower($siteNumber);
            if (isset($sitesByNumber[$key])) {
                $existing = &$sitesByNumber[$key];
                if (($existing['map_x'] === null || $existing['map_x'] === '') && ($existing['map_y'] === null || $existing['map_y'] === '')) {
                    $existing['map_x'] = $placeholder['map_x'];
                    $existing['map_y'] = $placeholder['map_y'];
                }
                unset($existing);
                continue;
            }

            $sites[] = [
                'id' => 'placeholder:' . $siteNumber,
                'site_number' => $siteNumber,
                'site_type' => '',
                'status' => 'Unallocated',
                'map_x' => $placeholder['map_x'],
                'map_y' => $placeholder['map_y'],
                'occupants' => '',
                'map_occupants' => '',
                'map_occupants_list' => [],
                'is_placeholder' => true
            ];
        }
    }

    private function getContentForCamp($db, $camp) {
        $campId = (int)($camp['id'] ?? 0);
        $stmt = $db->prepare("SELECT program, program_schedule, program_image_path, notifications, events, theme_image_path, between_camps_mode, between_camps_checkout_url, updated_at FROM camp_intranet_content WHERE camp_id = ? LIMIT 1");
        $stmt->execute([$campId]);
        $content = $stmt->fetch();

        $program = $content['program'] ?? '';
        $programScheduleRaw = $content['program_schedule'] ?? null;
        $programSessions = $this->getStructuredProgramSessions($db, $campId);
        $programSchedule = $programSessions
            ? $this->normaliseProgramScheduleFromSessions($camp, $programSessions)
            : $this->normaliseProgramSchedule($camp, $programScheduleRaw);
        $programImagePath = $this->normaliseProgramImagePath($content['program_image_path'] ?? null);
        $notifications = $content['notifications'] ?? '';
        $events = $content['events'] ?? '';
        $themePath = $this->normaliseThemeImagePath($content['theme_image_path'] ?? null);
        $betweenCampsMode = !empty($content['between_camps_mode']) ? 1 : 0;
        $betweenCampsCheckoutUrl = trim((string)($content['between_camps_checkout_url'] ?? ''));
        $updatedAt = $content['updated_at'] ?? null;
        $version = sha1(json_encode([$campId, $program, $programScheduleRaw, $programSessions, $programImagePath, $notifications, $events, $themePath, $betweenCampsMode, $betweenCampsCheckoutUrl, $updatedAt]));

        return [
            'program' => $program,
            'program_schedule' => $programSchedule,
            'program_sessions' => $programSessions,
            'program_schedule_raw' => $programScheduleRaw,
            'program_image_path' => $programImagePath,
            'notifications' => $notifications,
            'events' => $events,
            'theme_image_path' => $themePath,
            'between_camps_mode' => $betweenCampsMode,
            'between_camps_checkout_url' => $betweenCampsCheckoutUrl,
            'updated_at' => $updatedAt,
            'version' => $version,
        ];
    }

    private function getTrafficStats($db) {
        $liveWindowMinutes = 3;

        $liveStmt = $db->prepare("
            SELECT COUNT(*)
            FROM intranet_page_visits
            WHERE last_seen_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ");
        $liveStmt->execute([$liveWindowMinutes]);
        $onlineNow = (int)$liveStmt->fetchColumn();

        $totalStmt = $db->query("SELECT COUNT(*) FROM intranet_page_visits");
        $totalVisits = $totalStmt ? (int)$totalStmt->fetchColumn() : 0;

        return [
            'online_now' => $onlineNow,
            'total_visits' => $totalVisits,
        ];
    }

    private function deleteUploadedImage($path) {
        if (!$path) return;

        $existingFile = realpath(__DIR__ . '/../../' . ltrim($path, '/'));
        $uploadsBase = realpath(__DIR__ . '/../../uploads/intranet');
        if ($existingFile && $uploadsBase && strpos($existingFile, $uploadsBase) === 0 && is_file($existingFile)) {
            @unlink($existingFile);
        }
    }

    private function storeUploadedIntranetImage($campId, $fieldName, $label, $prefix) {
        if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
            return null;
        }

        $file = $_FILES[$fieldName];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new Exception($label . ' upload failed.');
        }

        $tmp = $file['tmp_name'] ?? '';
        if (!$tmp || !is_uploaded_file($tmp)) {
            throw new Exception('Uploaded file was not received correctly.');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $tmp) : null;
        if ($finfo) finfo_close($finfo);

        $allowed = $fieldName === 'program_image'
            ? [
                'image/jpeg' => 'jpg',
                'image/png' => 'png'
            ]
            : [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif'
            ];
        if (!isset($allowed[$mime])) {
            throw new Exception($fieldName === 'program_image'
                ? 'Please upload a JPG or PNG image.'
                : 'Please upload a JPG, PNG, WebP or GIF image.');
        }

        $dir = __DIR__ . '/../../uploads/intranet';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new Exception('Could not create uploads directory.');
        }

        $filename = 'camp_' . intval($campId) . '_' . $prefix . '_' . date('Ymd_His') . '.' . $allowed[$mime];
        $destination = $dir . '/' . $filename;
        if (!move_uploaded_file($tmp, $destination)) {
            throw new Exception('Failed to save uploaded image.');
        }

        return '/uploads/intranet/' . $filename;
    }

    private function storeUploadedThemeImage($campId) {
        return $this->storeUploadedIntranetImage($campId, 'theme_image', 'Theme image', 'theme');
    }

    private function storeUploadedProgramImage($campId) {
        return $this->storeUploadedIntranetImage($campId, 'program_image', 'Program image', 'program');
    }

    public function publicActive() {
        $this->sendJsonHeaders();
        try {
            $db = Database::connect();
            $this->ensureSchema($db);
            $stats = $this->getTrafficStats($db);
            $camp = $this->getPublicCamp($db);
            if (!$camp) {
                echo json_encode([
                    'camp' => null,
                    'mode' => 'between',
                    'content' => [
                        'program' => '',
                        'program_schedule' => [],
                        'program_sessions' => [],
                        'program_image_path' => null,
                        'notifications' => '',
                        'events' => '',
                        'theme_image_path' => null,
                        'between_camps_mode' => 0,
                        'between_camps_checkout_url' => '',
                        'updated_at' => null
                    ],
                    'stats' => $stats,
                    'version' => sha1('empty')
                ]);
                return;
            }

            $content = $this->getContentForCamp($db, $camp);
            $isActiveCamp = strtolower((string)($camp['status'] ?? '')) === 'active';
            $mode = ($isActiveCamp && empty($content['between_camps_mode'])) ? 'camp' : 'between';
            echo json_encode([
                'camp' => [
                    'id' => $camp['id'],
                    'name' => $camp['name'],
                    'year' => $camp['year'],
                    'start_date' => $camp['start_date'],
                    'end_date' => $camp['end_date'],
                    'status' => $camp['status'] ?? null,
                    'is_active' => $isActiveCamp,
                ],
                'mode' => $mode,
                'content' => [
                    'program' => $content['program'],
                    'program_schedule' => $content['program_schedule'],
                    'program_sessions' => $content['program_sessions'],
                    'program_image_path' => $content['program_image_path'],
                    'notifications' => $content['notifications'],
                    'events' => $content['events'],
                    'theme_image_path' => $content['theme_image_path'],
                    'between_camps_mode' => $content['between_camps_mode'],
                    'between_camps_checkout_url' => $content['between_camps_checkout_url'],
                    'updated_at' => $content['updated_at']
                ],
                'stats' => $stats,
                'version' => $content['version']
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Server error', 'error' => $e->getMessage()]);
        }
    }

    public function publicTrackVisit() {
        $this->sendJsonHeaders();
        try {
            $db = Database::connect();
            $this->ensureSchema($db);
            $input = $this->readInput();
            $sessionKey = trim((string)($input['session_key'] ?? ''));
            $visitPath = trim((string)($input['path'] ?? '/intranet'));

            if ($sessionKey === '' || strlen($sessionKey) > 80) {
                http_response_code(422);
                echo json_encode(['message' => 'A valid session key is required.']);
                return;
            }

            $ipAddress = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
            $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

            $stmt = $db->prepare("
                INSERT INTO intranet_page_visits (session_key, visit_path, ip_address, user_agent, first_seen_at, last_seen_at)
                VALUES (?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    visit_path = VALUES(visit_path),
                    ip_address = VALUES(ip_address),
                    user_agent = VALUES(user_agent),
                    last_seen_at = NOW()
            ");
            $stmt->execute([$sessionKey, $visitPath, $ipAddress, $userAgent]);

            echo json_encode([
                'success' => true,
                'stats' => $this->getTrafficStats($db)
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Server error', 'error' => $e->getMessage()]);
        }
    }

    public function publicSitesMap() {
        $this->sendJsonHeaders();
        try {
            $db = Database::connect();
            $sitesStmt = $db->query("SELECT id, site_number, site_type, map_lat, map_lng FROM sites");
            $sites = $sitesStmt ? $sitesStmt->fetchAll() : [];
            $mapOccBySite = [];
            try {
                $mapOccBySite = (new SiteMapOccupantService($db))->buildBySite();
            } catch (Throwable $e) {
            }
            foreach ($sites as &$s) {
                $siteId = (int)($s['id'] ?? 0);
                $s['occupants'] = $mapOccBySite[$siteId]['map_occupants'] ?? '';
                $s['map_occupants'] = $s['occupants'];
                $s['map_occupants_list'] = $mapOccBySite[$siteId]['map_occupants_list'] ?? [];
                $s['is_placeholder'] = false;
            }
            unset($s);

            $this->mergePublicMapPlaceholders($db, $sites);
            echo json_encode($sites);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Server error', 'error' => $e->getMessage()]);
        }
    }

    public function adminGet() {
        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorised']);
            return;
        }
        $this->sendJsonHeaders();
        try {
            $db = Database::connect();
            $this->ensureSchema($db);
            $camp = $this->getAdminCamp($db);
            if (!$camp) {
                echo json_encode([
                    'camp' => null,
                    'content' => [
                        'program' => '',
                        'program_schedule' => [],
                        'program_image_path' => null,
                        'notifications' => '',
                        'events' => '',
                        'theme_image_path' => null,
                        'between_camps_mode' => 0,
                        'between_camps_checkout_url' => '',
                        'updated_at' => null
                    ],
                    'version' => sha1('empty-admin')
                ]);
                return;
            }

            $content = $this->getContentForCamp($db, $camp);
            echo json_encode([
                'camp' => $camp,
                'content' => [
                    'program' => $content['program'],
                    'program_schedule' => $content['program_schedule'],
                    'program_image_path' => $content['program_image_path'],
                    'notifications' => $content['notifications'],
                    'events' => $content['events'],
                    'theme_image_path' => $content['theme_image_path'],
                    'between_camps_mode' => $content['between_camps_mode'],
                    'between_camps_checkout_url' => $content['between_camps_checkout_url'],
                    'updated_at' => $content['updated_at']
                ],
                'version' => $content['version']
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Server error', 'error' => $e->getMessage()]);
        }
    }

    public function adminSave() {
        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorised']);
            return;
        }
        $this->sendJsonHeaders();
        try {
            $db = Database::connect();
            $this->ensureSchema($db);
            $camp = $this->getAdminCamp($db);
            if (!$camp) {
                http_response_code(400);
                echo json_encode(['message' => 'No camp is available for intranet editing']);
                return;
            }

            $existing = $this->getContentForCamp($db, $camp);
            $isJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
            $input = $isJson ? json_decode(file_get_contents('php://input'), true) : $_POST;
            if (!is_array($input)) $input = [];

            $program = array_key_exists('program', $input) ? (string)$input['program'] : ($existing['program'] ?? '');
            $programScheduleRaw = array_key_exists('program_schedule', $input)
                ? $this->encodeProgramScheduleForStorage($camp, $input['program_schedule'])
                : ($existing['program_schedule_raw'] ?? null);
            $notifications = array_key_exists('notifications', $input) ? (string)$input['notifications'] : '';
            $events = array_key_exists('events', $input) ? (string)$input['events'] : '';
            $betweenCampsMode = !empty($input['between_camps_mode']) && $input['between_camps_mode'] !== '0' ? 1 : 0;
            $betweenCampsCheckoutUrl = trim((string)($input['between_camps_checkout_url'] ?? ($existing['between_camps_checkout_url'] ?? '')));
            $removeProgramImage = !empty($input['remove_program_image']) && $input['remove_program_image'] !== '0';
            $removeThemeImage = !empty($input['remove_theme_image']) && $input['remove_theme_image'] !== '0';
            $programImagePath = $existing['program_image_path'] ?? null;
            $themeImagePath = $existing['theme_image_path'] ?? null;

            if ($removeProgramImage && $programImagePath) {
                $this->deleteUploadedImage($programImagePath);
                $programImagePath = null;
            }

            if ($removeThemeImage && $themeImagePath) {
                $this->deleteUploadedImage($themeImagePath);
                $themeImagePath = null;
            }

            $uploadedProgramPath = $this->storeUploadedProgramImage($camp['id']);
            if ($uploadedProgramPath) {
                if ($programImagePath && $programImagePath !== $uploadedProgramPath) {
                    $this->deleteUploadedImage($programImagePath);
                }
                $programImagePath = $uploadedProgramPath;
            }

            $uploadedPath = $this->storeUploadedThemeImage($camp['id']);
            if ($uploadedPath) {
                if ($themeImagePath && $themeImagePath !== $uploadedPath) {
                    $this->deleteUploadedImage($themeImagePath);
                }
                $themeImagePath = $uploadedPath;
            }

            $stmt = $db->prepare("INSERT INTO camp_intranet_content (camp_id, program, program_schedule, program_image_path, notifications, events, theme_image_path, between_camps_mode, between_camps_checkout_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE program = VALUES(program), program_schedule = VALUES(program_schedule), program_image_path = VALUES(program_image_path), notifications = VALUES(notifications), events = VALUES(events), theme_image_path = VALUES(theme_image_path), between_camps_mode = VALUES(between_camps_mode), between_camps_checkout_url = VALUES(between_camps_checkout_url), updated_at = CURRENT_TIMESTAMP");
            $stmt->execute([$camp['id'], $program, $programScheduleRaw, $programImagePath, $notifications, $events, $themeImagePath, $betweenCampsMode, $betweenCampsCheckoutUrl ?: null]);

            echo json_encode([
                'success' => true,
                'theme_image_path' => $themeImagePath,
                'program_image_path' => $programImagePath,
                'between_camps_mode' => $betweenCampsMode,
                'between_camps_checkout_url' => $betweenCampsCheckoutUrl
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Server error', 'error' => $e->getMessage()]);
        }
    }
}
