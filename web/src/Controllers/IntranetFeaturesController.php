<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Mailer.php';
require_once __DIR__ . '/../MemberHouseholdService.php';

class IntranetFeaturesController {
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

    private function ensureInteractionSchema($db) {
        $db->exec("CREATE TABLE IF NOT EXISTS camp_intranet_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            camp_id INT NOT NULL,
            category VARCHAR(50) NOT NULL DEFAULT 'General Question',
            submitter_name VARCHAR(120) NOT NULL,
            site_number VARCHAR(30) NOT NULL,
            site_id INT NULL,
            message TEXT NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'new',
            is_verified TINYINT(1) NOT NULL DEFAULT 0,
            verification_note VARCHAR(255) NULL,
            admin_notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_intranet_messages_camp_status (camp_id, status, created_at),
            CONSTRAINT fk_intranet_messages_camp FOREIGN KEY (camp_id) REFERENCES camps(id) ON DELETE CASCADE,
            CONSTRAINT fk_intranet_messages_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS camp_intranet_site_updates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            camp_id INT NOT NULL,
            site_number VARCHAR(30) NOT NULL,
            site_id INT NULL,
            member_first_name VARCHAR(120) NOT NULL,
            member_last_name VARCHAR(120) NOT NULL,
            phone_number VARCHAR(60) NULL,
            email VARCHAR(190) NULL,
            other_members TEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'new',
            is_verified TINYINT(1) NOT NULL DEFAULT 0,
            verification_note VARCHAR(255) NULL,
            admin_notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_intranet_site_updates_camp_status (camp_id, status, created_at),
            CONSTRAINT fk_intranet_site_updates_camp FOREIGN KEY (camp_id) REFERENCES camps(id) ON DELETE CASCADE,
            CONSTRAINT fk_intranet_site_updates_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS camp_intranet_checkins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            camp_id INT NOT NULL,
            site_number VARCHAR(30) NOT NULL,
            site_id INT NULL,
            submitter_name VARCHAR(120) NOT NULL,
            phone_number VARCHAR(60) NULL,
            email VARCHAR(190) NULL,
            arrival_date DATE NULL,
            departure_date DATE NULL,
            adults_count INT NOT NULL DEFAULT 0,
            kids_count INT NOT NULL DEFAULT 0,
            site_type VARCHAR(100) NULL,
            is_day_trip TINYINT(1) NOT NULL DEFAULT 0,
            matched_member_id INT NULL,
            matched_household_id INT NULL,
            applied_payment_id INT NULL,
            applied_member_id INT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'new',
            is_verified TINYINT(1) NOT NULL DEFAULT 0,
            verification_note VARCHAR(255) NULL,
            admin_notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_intranet_checkins_camp_status (camp_id, status, created_at),
            INDEX idx_intranet_checkins_household (matched_household_id, status),
            INDEX idx_intranet_checkins_member (matched_member_id, status),
            INDEX idx_intranet_checkins_site (site_id, status),
            CONSTRAINT fk_intranet_checkins_camp FOREIGN KEY (camp_id) REFERENCES camps(id) ON DELETE CASCADE,
            CONSTRAINT fk_intranet_checkins_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS camp_intranet_lost_found (
            id INT AUTO_INCREMENT PRIMARY KEY,
            camp_id INT NOT NULL,
            item_type VARCHAR(20) NOT NULL DEFAULT 'found',
            title VARCHAR(160) NOT NULL,
            description TEXT NOT NULL,
            location_details VARCHAR(180) NULL,
            contact_details VARCHAR(180) NULL,
            reporter_name VARCHAR(120) NOT NULL,
            site_number VARCHAR(30) NOT NULL,
            site_id INT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            is_verified TINYINT(1) NOT NULL DEFAULT 0,
            verification_note VARCHAR(255) NULL,
            admin_notes TEXT NULL,
            approved_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_intranet_lost_found_camp_status (camp_id, status, created_at),
            CONSTRAINT fk_intranet_lost_found_camp FOREIGN KEY (camp_id) REFERENCES camps(id) ON DELETE CASCADE,
            CONSTRAINT fk_intranet_lost_found_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS camp_intranet_noticeboard (
            id INT AUTO_INCREMENT PRIMARY KEY,
            camp_id INT NOT NULL,
            category VARCHAR(50) NOT NULL DEFAULT 'General',
            title VARCHAR(160) NOT NULL,
            message TEXT NOT NULL,
            contact_details VARCHAR(180) NULL,
            author_name VARCHAR(120) NOT NULL,
            site_number VARCHAR(30) NOT NULL,
            site_id INT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            is_verified TINYINT(1) NOT NULL DEFAULT 0,
            verification_note VARCHAR(255) NULL,
            approved_at DATETIME NULL,
            expires_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_intranet_noticeboard_camp_status (camp_id, status, created_at),
            CONSTRAINT fk_intranet_noticeboard_camp FOREIGN KEY (camp_id) REFERENCES camps(id) ON DELETE CASCADE,
            CONSTRAINT fk_intranet_noticeboard_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS camp_intranet_polls (
            id INT AUTO_INCREMENT PRIMARY KEY,
            camp_id INT NOT NULL,
            title VARCHAR(180) NOT NULL,
            description TEXT NULL,
            poll_type VARCHAR(20) NOT NULL DEFAULT 'poll',
            status VARCHAR(30) NOT NULL DEFAULT 'draft',
            show_results_public TINYINT(1) NOT NULL DEFAULT 0,
            closes_at DATETIME NULL,
            created_by_username VARCHAR(120) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_intranet_polls_camp_status (camp_id, status, created_at),
            CONSTRAINT fk_intranet_polls_camp FOREIGN KEY (camp_id) REFERENCES camps(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS camp_intranet_poll_options (
            id INT AUTO_INCREMENT PRIMARY KEY,
            poll_id INT NOT NULL,
            label VARCHAR(160) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            INDEX idx_intranet_poll_options_poll (poll_id, sort_order),
            CONSTRAINT fk_intranet_poll_options_poll FOREIGN KEY (poll_id) REFERENCES camp_intranet_polls(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS camp_intranet_poll_responses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            poll_id INT NOT NULL,
            option_id INT NOT NULL,
            responder_name VARCHAR(120) NOT NULL,
            site_number VARCHAR(30) NOT NULL,
            site_id INT NULL,
            response_key VARCHAR(190) NOT NULL,
            is_verified TINYINT(1) NOT NULL DEFAULT 0,
            verification_note VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_intranet_poll_response_key (poll_id, response_key),
            INDEX idx_intranet_poll_responses_poll (poll_id),
            CONSTRAINT fk_intranet_poll_responses_poll FOREIGN KEY (poll_id) REFERENCES camp_intranet_polls(id) ON DELETE CASCADE,
            CONSTRAINT fk_intranet_poll_responses_option FOREIGN KEY (option_id) REFERENCES camp_intranet_poll_options(id) ON DELETE CASCADE,
            CONSTRAINT fk_intranet_poll_responses_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS admin_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            source_type VARCHAR(30) NOT NULL,
            source_id INT NOT NULL,
            title VARCHAR(180) NOT NULL,
            body TEXT NOT NULL,
            link_path VARCHAR(255) NOT NULL,
            payload_json LONGTEXT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            read_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_admin_notifications_source (source_type, source_id),
            INDEX idx_admin_notifications_read (is_read, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS admin_notification_recipients (
            id INT AUTO_INCREMENT PRIMARY KEY,
            label VARCHAR(120) NOT NULL,
            email VARCHAR(190) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_admin_notification_recipients_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    private function appBaseUrl() {
        return CampoMailer::appBaseUrl($_SERVER);
    }

    private function trimExcerpt($value, $limit = 220) {
        $clean = trim(preg_replace('/\s+/', ' ', strip_tags((string)$value)));
        if ($clean === '') return '';
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($clean, 'UTF-8') > $limit
                ? rtrim(mb_substr($clean, 0, $limit - 1, 'UTF-8')) . '…'
                : $clean;
        }
        return strlen($clean) > $limit ? rtrim(substr($clean, 0, $limit - 1)) . '…' : $clean;
    }

    private function buildSubmissionNotification($type, $sourceId, $submission) {
        $sourceType = $type;
        $focus = $type;
        $title = 'New intranet submission';
        $body = 'A new submission was received from the public intranet.';
        $subject = '[Campo] New intranet submission';
        $siteNumber = $this->collapseWhitespace($submission['site_number'] ?? '');
        $siteLabel = $siteNumber !== '' ? "Site {$siteNumber}" : 'No site supplied';

        if ($type === 'message') {
            $name = $this->collapseWhitespace($submission['submitter_name'] ?? '');
            $category = $this->collapseWhitespace($submission['category'] ?? 'General Question');
            $excerpt = $this->trimExcerpt($submission['message'] ?? '');
            $title = "Ask Admin: {$name}";
            $body = trim("{$category} | {$siteLabel}" . ($excerpt !== '' ? " | {$excerpt}" : ''));
            $subject = '[Campo] New Ask Admin submission';
        } elseif ($type === 'site_update') {
            $focus = 'site-updates';
            $firstName = $this->collapseWhitespace($submission['member_first_name'] ?? '');
            $lastName = $this->collapseWhitespace($submission['member_last_name'] ?? '');
            $name = trim("{$firstName} {$lastName}");
            $phone = $this->collapseWhitespace($submission['phone_number'] ?? '');
            $email = $this->collapseWhitespace($submission['email'] ?? '');
            $otherMembers = $this->trimExcerpt($submission['other_members'] ?? '');
            $title = "Site Update: {$name}";
            $bits = array_filter([
                $siteLabel,
                $phone !== '' ? "Phone {$phone}" : '',
                $email !== '' ? $email : '',
                $otherMembers !== '' ? $otherMembers : ''
            ]);
            $body = implode(' | ', $bits);
            $subject = '[Campo] New site details update request';
        } elseif ($type === 'check_in') {
            $focus = 'check-ins';
            $name = $this->collapseWhitespace($submission['submitter_name'] ?? '');
            $arrival = $this->collapseWhitespace($submission['arrival_date'] ?? '');
            $departure = $this->collapseWhitespace($submission['departure_date'] ?? '');
            $adults = (int)($submission['adults_count'] ?? 0);
            $kids = (int)($submission['kids_count'] ?? 0);
            $siteType = $this->collapseWhitespace($submission['site_type'] ?? '');
            $dayTrip = !empty($submission['is_day_trip']) ? 'Day trip' : '';
            $dates = trim(implode(' to ', array_filter([$arrival, $departure])));
            $summary = trim(implode(', ', array_filter([
                $adults > 0 ? "{$adults} adult" . ($adults === 1 ? '' : 's') : '',
                $kids > 0 ? "{$kids} kid" . ($kids === 1 ? '' : 's') : '',
                $siteType,
                $dayTrip
            ])));
            $title = "Check-In: {$name}";
            $body = implode(' | ', array_filter([$siteLabel, $dates, $summary]));
            $subject = '[Campo] New camp self check-in';
        } elseif ($type === 'lost_found') {
            $focus = 'lost-found';
            $name = $this->collapseWhitespace($submission['reporter_name'] ?? '');
            $itemType = ucfirst($this->collapseWhitespace($submission['item_type'] ?? 'found'));
            $entryTitle = $this->collapseWhitespace($submission['title'] ?? 'Untitled item');
            $excerpt = $this->trimExcerpt($submission['description'] ?? '');
            $title = "Lost & Found: {$entryTitle}";
            $body = trim("{$itemType} | {$name} | {$siteLabel}" . ($excerpt !== '' ? " | {$excerpt}" : ''));
            $subject = '[Campo] New Lost & Found submission';
        } elseif ($type === 'noticeboard') {
            $name = $this->collapseWhitespace($submission['author_name'] ?? '');
            $category = $this->collapseWhitespace($submission['category'] ?? 'General');
            $entryTitle = $this->collapseWhitespace($submission['title'] ?? 'Untitled notice');
            $excerpt = $this->trimExcerpt($submission['message'] ?? '');
            $title = "Noticeboard: {$entryTitle}";
            $body = trim("{$category} | {$name} | {$siteLabel}" . ($excerpt !== '' ? " | {$excerpt}" : ''));
            $subject = '[Campo] New Noticeboard submission';
        }

        $linkPath = "/intranet-admin?focus={$focus}&id=" . (int)$sourceId;

        return [
            'source_type' => $sourceType,
            'source_id' => (int)$sourceId,
            'title' => $title,
            'body' => $body,
            'subject' => $subject,
            'link_path' => $linkPath,
            'payload' => $submission
        ];
    }

    private function createAdminNotification($db, $notification) {
        $stmt = $db->prepare("
            INSERT INTO admin_notifications (source_type, source_id, title, body, link_path, payload_json, is_read)
            VALUES (?, ?, ?, ?, ?, ?, 0)
        ");

        try {
            $stmt->execute([
                $notification['source_type'],
                (int)$notification['source_id'],
                $notification['title'],
                $notification['body'],
                $notification['link_path'],
                json_encode($notification['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ]);

            return [
                'created' => true,
                'id' => (int)$db->lastInsertId()
            ];
        } catch (PDOException $e) {
            if (($e->getCode() ?? '') === '23000') {
                return ['created' => false, 'id' => null];
            }
            throw $e;
        }
    }

    private function fetchNotificationRecipients($db, $activeOnly = false) {
        $sql = "SELECT id, label, email, is_active, created_at FROM admin_notification_recipients";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY is_active DESC, label ASC, email ASC";
        $stmt = $db->query($sql);
        return $stmt ? $stmt->fetchAll() : [];
    }

    private function fetchNotificationRecipientById($db, $id) {
        $stmt = $db->prepare("SELECT id, label, email, is_active, created_at FROM admin_notification_recipients WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$id]);
        return $stmt->fetch();
    }

    private function notificationMailStatus() {
        return CampoMailer::status($_SERVER);
    }

    private function buildSubmissionNotificationEmailBody($notification) {
        $link = $this->appBaseUrl() . $notification['link_path'];
        $payload = $notification['payload'];

        $details = [];
        foreach ([
            'submitter_name' => 'Submitted by',
            'reporter_name' => 'Submitted by',
            'author_name' => 'Submitted by',
            'member_first_name' => 'First name',
            'member_last_name' => 'Last name',
            'site_number' => 'Site',
            'phone_number' => 'Phone',
            'email' => 'Email',
            'arrival_date' => 'Arrival',
            'departure_date' => 'Departure',
            'adults_count' => 'Adults',
            'kids_count' => 'Kids (5-13)',
            'site_type' => 'Site type',
            'is_day_trip' => 'Day trip',
            'category' => 'Category',
            'item_type' => 'Type',
            'title' => 'Title',
            'message' => 'Message',
            'description' => 'Description',
            'contact_details' => 'Contact',
            'other_members' => 'Other members on site'
        ] as $key => $label) {
            $value = $this->trimExcerpt($payload[$key] ?? '', $key === 'message' || $key === 'description' ? 500 : 220);
            if ($value !== '') {
                $details[] = "{$label}: {$value}";
            }
        }

        $body = $notification['title'] . "\n\n" . $notification['body'];
        if ($details) {
            $body .= "\n\n" . implode("\n", $details);
        }

        return $body . "\n\nOpen in Campo:\n{$link}\n";
    }

    private function sendNotificationEmails($db, $notification) {
        $recipients = $this->fetchNotificationRecipients($db, true);
        if (!$recipients) {
            return ['attempted' => 0, 'sent' => 0, 'failed' => 0, 'errors' => []];
        }

        $mailStatus = $this->notificationMailStatus();
        if (!$mailStatus['configured']) {
            error_log('Campo notification email skipped: ' . implode(' ', $mailStatus['issues']));
            return [
                'attempted' => count($recipients),
                'sent' => 0,
                'failed' => count($recipients),
                'errors' => $mailStatus['issues']
            ];
        }

        $body = $this->buildSubmissionNotificationEmailBody($notification);
        $sentCount = 0;
        $errors = [];

        foreach ($recipients as $recipient) {
            $email = trim((string)($recipient['email'] ?? ''));
            if ($email === '') continue;

            try {
                CampoMailer::sendText($email, $notification['subject'], $body, $_SERVER);
                $sentCount++;
            } catch (Throwable $e) {
                $errors[] = "{$email}: {$e->getMessage()}";
                error_log("Campo notification email failed for {$email} ({$notification['subject']}): " . $e->getMessage());
            }
        }

        return [
            'attempted' => count($recipients),
            'sent' => $sentCount,
            'failed' => count($errors),
            'errors' => $errors
        ];
    }

    private function notifyAdminsForSubmission($db, $type, $sourceId, $submission) {
        if ($type === 'check_in') {
            return [
                'created' => false,
                'suppressed' => true
            ];
        }

        $notification = $this->buildSubmissionNotification($type, $sourceId, $submission);
        $result = $this->createAdminNotification($db, $notification);
        if ($result['created']) {
            $this->sendNotificationEmails($db, $notification);
        }
        return $result;
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

    private function collapseWhitespace($value) {
        $value = trim((string)$value);
        return preg_replace('/\s+/', ' ', $value);
    }

    private function lowerText($value) {
        $clean = $this->collapseWhitespace($value);
        return function_exists('mb_strtolower') ? mb_strtolower($clean, 'UTF-8') : strtolower($clean);
    }

    private function cleanKey($value) {
        $value = $this->lowerText($value);
        $value = preg_replace('/[^[:alnum:] ]/u', ' ', $value);
        return preg_replace('/\s+/', ' ', trim((string)$value));
    }

    private function tokeniseText($value) {
        $clean = $this->cleanKey($value);
        return $clean === '' ? [] : explode(' ', $clean);
    }

    private function readInput() {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $data = json_decode(file_get_contents('php://input'), true);
            return is_array($data) ? $data : [];
        }
        return is_array($_POST) ? $_POST : [];
    }

    private function normaliseStatus($value, $allowed, $fallback) {
        $value = $this->lowerText($value);
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function normaliseDateTime($value) {
        $value = $this->collapseWhitespace($value);
        if ($value === '') return null;
        $timestamp = strtotime($value);
        return $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;
    }

    private function tableExists($db, $table) {
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function householdsEnabled($db) {
        return $this->tableExists($db, 'member_households') && $this->tableExists($db, 'member_household_members');
    }

    private function findSiteByNumber($db, $siteNumber) {
        $stmt = $db->prepare("SELECT id, site_number FROM sites WHERE LOWER(site_number) = LOWER(?) LIMIT 1");
        $stmt->execute([$siteNumber]);
        return $stmt->fetch();
    }

    private function fetchSiteOccupants($db, $siteId) {
        if (!$siteId) return [];

        $stmt = $db->prepare("
            SELECT TRIM(CONCAT(COALESCE(m.first_name, ''), ' ', COALESCE(m.last_name, ''))) AS name
            FROM site_allocations sa
            JOIN members m ON m.id = sa.member_id
            WHERE sa.site_id = ? AND sa.is_current = 1
            ORDER BY m.last_name, m.first_name
        ");
        $stmt->execute([$siteId]);
        $rows = $stmt->fetchAll();

        return array_values(array_filter(array_map(function($row) {
            return $this->collapseWhitespace($row['name'] ?? '');
        }, $rows)));
    }

    private function fetchSiteOccupantRows($db, $siteId) {
        if (!$siteId) return [];

        $stmt = $db->prepare("
            SELECT
                m.id,
                TRIM(CONCAT(COALESCE(m.first_name, ''), ' ', COALESCE(m.last_name, ''))) AS name
            FROM site_allocations sa
            JOIN members m ON m.id = sa.member_id
            WHERE sa.site_id = ? AND sa.is_current = 1
            ORDER BY m.last_name, m.first_name
        ");
        $stmt->execute([(int)$siteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function namesMatch($submittedName, $occupantNames) {
        $submittedKey = $this->cleanKey($submittedName);
        if ($submittedKey === '') return false;

        $submittedTokens = $this->tokeniseText($submittedName);
        foreach ($occupantNames as $occupantName) {
            $occupantKey = $this->cleanKey($occupantName);
            if ($occupantKey === $submittedKey) {
                return true;
            }

            $occupantTokens = $this->tokeniseText($occupantName);
            if ($submittedTokens && !array_diff($submittedTokens, $occupantTokens)) {
                return true;
            }
        }

        return false;
    }

    private function findMatchingOccupantRow($submittedName, $occupants) {
        $submittedKey = $this->cleanKey($submittedName);
        if ($submittedKey === '') return null;

        $submittedTokens = $this->tokeniseText($submittedName);
        foreach ($occupants as $occupant) {
            $occupantName = $occupant['name'] ?? '';
            $occupantKey = $this->cleanKey($occupantName);
            if ($occupantKey === $submittedKey) {
                return $occupant;
            }

            $occupantTokens = $this->tokeniseText($occupantName);
            if ($submittedTokens && !array_diff($submittedTokens, $occupantTokens)) {
                return $occupant;
            }
        }

        return null;
    }

    private function resolveCheckInMatch($db, $siteId, $submittedName) {
        $result = [
            'matched_member_id' => null,
            'matched_household_id' => null
        ];

        $occupants = $this->fetchSiteOccupantRows($db, $siteId);
        if (!$occupants) {
            return $result;
        }

        $householdIds = [];
        $householdService = $this->householdsEnabled($db) ? new MemberHouseholdService($db) : null;
        foreach ($occupants as $occupant) {
            $memberId = (int)($occupant['id'] ?? 0);
            if ($memberId <= 0 || !$householdService) {
                continue;
            }
            $householdId = $householdService->getMemberHouseholdId($memberId);
            if ($householdId) {
                $householdIds[$memberId] = (int)$householdId;
            }
        }

        $match = $this->findMatchingOccupantRow($submittedName, $occupants);
        if ($match) {
            $memberId = (int)($match['id'] ?? 0);
            if ($memberId > 0) {
                $result['matched_member_id'] = $memberId;
                if (!empty($householdIds[$memberId])) {
                    $result['matched_household_id'] = (int)$householdIds[$memberId];
                }
                return $result;
            }
        }

        $uniqueHouseholds = array_values(array_unique(array_filter(array_map('intval', array_values($householdIds)))));
        if (count($uniqueHouseholds) === 1) {
            $result['matched_household_id'] = $uniqueHouseholds[0];
        }

        return $result;
    }

    private function resolveSelectedCheckInMember($db, $memberId) {
        $memberId = (int)$memberId;
        if ($memberId <= 0) {
            return null;
        }

        $stmt = $db->prepare("
            SELECT
                m.id,
                m.first_name,
                m.last_name,
                m.email,
                COALESCE(NULLIF(m.mobile, ''), NULLIF(m.phone, '')) AS phone_number,
                sa.site_id,
                s.site_number
            FROM members m
            LEFT JOIN site_allocations sa ON sa.member_id = m.id AND sa.is_current = 1
            LEFT JOIN sites s ON s.id = sa.site_id
            WHERE m.id = ?
            LIMIT 1
        ");
        $stmt->execute([$memberId]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$member) {
            return null;
        }

        $resolved = [
            'matched_member_id' => $memberId,
            'matched_household_id' => null,
            'site_id' => !empty($member['site_id']) ? (int)$member['site_id'] : null,
            'site_number' => $this->collapseWhitespace($member['site_number'] ?? ''),
            'name' => $this->collapseWhitespace(trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''))),
            'email' => trim((string)($member['email'] ?? '')),
            'phone_number' => $this->collapseWhitespace($member['phone_number'] ?? ''),
            'verification_note' => 'Matched from the selected member record.'
        ];

        if ($this->householdsEnabled($db)) {
            $householdService = new MemberHouseholdService($db);
            $householdId = (int)($householdService->getMemberHouseholdId($memberId) ?: 0);
            if ($householdId > 0) {
                $resolved['matched_household_id'] = $householdId;
                $householdDetail = $householdService->getHouseholdDetail($householdId);
                $household = $householdDetail['household'] ?? [];

                if (!$resolved['site_id'] && !empty($householdDetail['members']) && (int)($household['site_count'] ?? 0) === 1) {
                    foreach ($householdDetail['members'] as $householdMember) {
                        $siteId = (int)($householdMember['site_id'] ?? 0);
                        if ($siteId > 0) {
                            $resolved['site_id'] = $siteId;
                            break;
                        }
                    }
                }

                if ($resolved['site_number'] === '' && (int)($household['site_count'] ?? 0) === 1) {
                    $resolved['site_number'] = $this->collapseWhitespace($household['site_number'] ?? '');
                }
            }
        }

        return $resolved;
    }

    private function searchCheckInMembers($db, $query, $limit = 8) {
        $query = $this->collapseWhitespace($query);
        $length = function_exists('mb_strlen') ? mb_strlen($query, 'UTF-8') : strlen($query);
        if ($length < 2) {
            return [];
        }

        $householdsEnabled = $this->householdsEnabled($db);
        $like = '%' . $this->lowerText($query) . '%';
        $params = [$like, $like, $like, $like];
        $sql = "
            SELECT
                m.id,
                m.first_name,
                m.last_name,
                m.email,
                m.mobile,
                m.phone,
                " . ($householdsEnabled ? 'mh.display_name' : 'NULL') . " AS household_name
            FROM members m
        ";

        if ($householdsEnabled) {
            $sql .= "
                LEFT JOIN member_household_members mhm ON mhm.member_id = m.id
                LEFT JOIN member_households mh ON mh.id = mhm.household_id
            ";
        }

        $sql .= "
            WHERE (
                LOWER(TRIM(CONCAT(COALESCE(m.first_name, ''), ' ', COALESCE(m.last_name, '')))) LIKE ?
                OR LOWER(COALESCE(m.email, '')) LIKE ?
                OR LOWER(COALESCE(m.mobile, '')) LIKE ?
                OR LOWER(COALESCE(m.phone, '')) LIKE ?
        ";

        if ($householdsEnabled) {
            $sql .= " OR LOWER(COALESCE(mh.display_name, '')) LIKE ? ";
            $params[] = $like;
        }

        $sql .= ")
            ORDER BY m.last_name ASC, m.first_name ASC
            LIMIT 40
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $tokens = $this->tokeniseText($query);
        $results = [];

        foreach ($rows as $row) {
            $haystack = implode(' ', array_filter([
                $row['first_name'] ?? '',
                $row['last_name'] ?? '',
                $row['email'] ?? '',
                $row['mobile'] ?? '',
                $row['phone'] ?? '',
                $row['household_name'] ?? ''
            ]));
            $haystackTokens = $this->tokeniseText($haystack);
            if ($tokens && array_diff($tokens, $haystackTokens)) {
                continue;
            }

            $resolved = $this->resolveSelectedCheckInMember($db, (int)$row['id']);
            if (!$resolved || $resolved['site_number'] === '') {
                continue;
            }

            $results[] = [
                'id' => (int)$row['id'],
                'name' => $resolved['name'],
                'site_number' => $resolved['site_number'],
                'email' => $resolved['email'],
                'phone_number' => $resolved['phone_number'],
                'household_name' => $row['household_name'] ?? ''
            ];

            if (count($results) >= (int)$limit) {
                break;
            }
        }

        return $results;
    }

    private function findOpenCheckIn($db, $campId, $matchedHouseholdId, $matchedMemberId, $siteId, $submitterName) {
        if ($matchedHouseholdId) {
            $stmt = $db->prepare("
                SELECT id
                FROM camp_intranet_checkins
                WHERE camp_id = ?
                  AND status IN ('new', 'in_progress')
                  AND matched_household_id = ?
                ORDER BY updated_at DESC, id DESC
                LIMIT 1
            ");
            $stmt->execute([(int)$campId, (int)$matchedHouseholdId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($matchedMemberId) {
            $stmt = $db->prepare("
                SELECT id
                FROM camp_intranet_checkins
                WHERE camp_id = ?
                  AND status IN ('new', 'in_progress')
                  AND matched_member_id = ?
                ORDER BY updated_at DESC, id DESC
                LIMIT 1
            ");
            $stmt->execute([(int)$campId, (int)$matchedMemberId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($siteId && $submitterName !== '') {
            $lowerName = function_exists('mb_strtolower')
                ? mb_strtolower(trim($submitterName), 'UTF-8')
                : strtolower(trim($submitterName));
            $stmt = $db->prepare("
                SELECT id
                FROM camp_intranet_checkins
                WHERE camp_id = ?
                  AND status IN ('new', 'in_progress')
                  AND site_id = ?
                  AND LOWER(TRIM(submitter_name)) = ?
                ORDER BY updated_at DESC, id DESC
                LIMIT 1
            ");
            $stmt->execute([(int)$campId, (int)$siteId, $lowerName]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        return null;
    }

    private function resolveVerification($db, $siteNumber, $name) {
        $siteNumber = $this->collapseWhitespace($siteNumber);
        $name = $this->collapseWhitespace($name);

        $result = [
            'site_id' => null,
            'site_number' => $siteNumber,
            'is_verified' => 0,
            'verification_note' => 'Name and site will be reviewed by admin.'
        ];

        $site = $this->findSiteByNumber($db, $siteNumber);
        if (!$site) {
            $result['verification_note'] = 'Site number was not found in the current camp records.';
            return $result;
        }

        $result['site_id'] = $site['id'];
        $occupants = $this->fetchSiteOccupants($db, $site['id']);
        if (!$occupants) {
            $result['verification_note'] = 'Site was found, but no current allocation could be matched.';
            return $result;
        }

        if ($this->namesMatch($name, $occupants)) {
            $result['is_verified'] = 1;
            $result['verification_note'] = 'Matched to the current site allocation.';
            return $result;
        }

        $result['verification_note'] = 'Site was found, but the submitted name did not match the current allocation.';
        return $result;
    }

    private function requireActiveCamp($db) {
        $camp = $this->getActiveCamp($db);
        if (!$camp) {
            throw new Exception('No active camp set.');
        }
        return $camp;
    }

    private function requirePublicCamp($db) {
        $camp = $this->getPublicCamp($db);
        if (!$camp) {
            throw new Exception('No camp is available right now.');
        }
        return $camp;
    }

    private function fetchMessagesForCamp($db, $campId) {
        $stmt = $db->prepare("
            SELECT id, category, submitter_name, site_number, message, status, is_verified, verification_note, admin_notes, created_at, updated_at
            FROM camp_intranet_messages
            WHERE camp_id = ?
            ORDER BY FIELD(status, 'new', 'in_progress', 'resolved', 'archived'), created_at DESC
        ");
        $stmt->execute([$campId]);
        return $stmt->fetchAll();
    }

    private function fetchSiteUpdatesForCamp($db, $campId) {
        $stmt = $db->prepare("
            SELECT id, site_number, member_first_name, member_last_name, phone_number, email, other_members, status, is_verified, verification_note, admin_notes, created_at, updated_at
            FROM camp_intranet_site_updates
            WHERE camp_id = ?
            ORDER BY FIELD(status, 'new', 'in_progress', 'resolved', 'archived'), created_at DESC
        ");
        $stmt->execute([$campId]);
        return $stmt->fetchAll();
    }

    private function fetchCheckInsForCamp($db, $campId) {
        $stmt = $db->prepare("
            SELECT
                id,
                site_number,
                submitter_name,
                phone_number,
                email,
                arrival_date,
                departure_date,
                adults_count,
                kids_count,
                site_type,
                is_day_trip,
                matched_member_id,
                matched_household_id,
                applied_payment_id,
                applied_member_id,
                status,
                is_verified,
                verification_note,
                admin_notes,
                created_at,
                updated_at
            FROM camp_intranet_checkins
            WHERE camp_id = ?
            ORDER BY FIELD(status, 'new', 'in_progress', 'resolved', 'archived'), created_at DESC
        ");
        $stmt->execute([$campId]);
        return $stmt->fetchAll();
    }

    private function fetchLostFoundForCamp($db, $campId, $publicOnly = false) {
        $sql = "
            SELECT id, item_type, title, description, location_details, contact_details, reporter_name, site_number, status, is_verified, verification_note, admin_notes, approved_at, created_at, updated_at
            FROM camp_intranet_lost_found
            WHERE camp_id = ?
        ";
        if ($publicOnly) {
            $sql .= " AND status = 'approved'";
        }
        $sql .= $publicOnly
            ? " ORDER BY COALESCE(approved_at, created_at) DESC, created_at DESC"
            : " ORDER BY FIELD(status, 'pending', 'approved', 'returned', 'rejected', 'archived'), created_at DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute([$campId]);
        return $stmt->fetchAll();
    }

    private function fetchNoticeboardForCamp($db, $campId, $publicOnly = false) {
        $sql = "
            SELECT id, category, title, message, contact_details, author_name, site_number, status, is_verified, verification_note, approved_at, expires_at, created_at, updated_at
            FROM camp_intranet_noticeboard
            WHERE camp_id = ?
        ";
        if ($publicOnly) {
            $sql .= " AND status = 'approved' AND (expires_at IS NULL OR expires_at >= NOW())";
        }
        $sql .= $publicOnly
            ? " ORDER BY COALESCE(expires_at, '9999-12-31 23:59:59') ASC, approved_at DESC, created_at DESC"
            : " ORDER BY FIELD(status, 'pending', 'approved', 'expired', 'rejected', 'archived'), created_at DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute([$campId]);
        return $stmt->fetchAll();
    }

    private function fetchPollsForCamp($db, $campId, $publicOnly = false) {
        $sql = "
            SELECT p.id, p.title, p.description, p.poll_type, p.status, p.show_results_public, p.closes_at, p.created_by_username, p.created_at, p.updated_at,
                   (SELECT COUNT(*) FROM camp_intranet_poll_responses r WHERE r.poll_id = p.id) AS responses_count
            FROM camp_intranet_polls p
            WHERE p.camp_id = ?
        ";
        if ($publicOnly) {
            $sql .= " AND p.status = 'live' AND (p.closes_at IS NULL OR p.closes_at >= NOW())";
        }
        $sql .= $publicOnly
            ? " ORDER BY COALESCE(p.closes_at, '9999-12-31 23:59:59') ASC, p.created_at DESC"
            : " ORDER BY FIELD(p.status, 'live', 'draft', 'closed', 'archived'), p.created_at DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute([$campId]);
        $polls = $stmt->fetchAll();
        if (!$polls) return [];

        $pollIds = array_map(function($poll) {
            return (int)$poll['id'];
        }, $polls);
        $marks = implode(',', array_fill(0, count($pollIds), '?'));

        $optionsStmt = $db->prepare("
            SELECT o.id, o.poll_id, o.label, o.sort_order, COUNT(r.id) AS response_count
            FROM camp_intranet_poll_options o
            LEFT JOIN camp_intranet_poll_responses r ON r.option_id = o.id
            WHERE o.poll_id IN ($marks)
            GROUP BY o.id, o.poll_id, o.label, o.sort_order
            ORDER BY o.poll_id, o.sort_order, o.id
        ");
        $optionsStmt->execute($pollIds);
        $optionsRows = $optionsStmt->fetchAll();

        $optionsByPoll = [];
        foreach ($optionsRows as $row) {
            $pollId = (int)$row['poll_id'];
            if (!isset($optionsByPoll[$pollId])) {
                $optionsByPoll[$pollId] = [];
            }
            $optionsByPoll[$pollId][] = [
                'id' => (int)$row['id'],
                'label' => $row['label'],
                'sort_order' => (int)$row['sort_order'],
                'response_count' => (int)$row['response_count']
            ];
        }

        foreach ($polls as &$poll) {
            $pollId = (int)$poll['id'];
            $poll['show_results_public'] = (int)$poll['show_results_public'];
            $poll['responses_count'] = (int)$poll['responses_count'];
            $poll['options'] = $optionsByPoll[$pollId] ?? [];
            $poll['is_open'] = ($poll['status'] === 'live') && (!$poll['closes_at'] || strtotime($poll['closes_at']) >= time());
            $poll['has_responses'] = $poll['responses_count'] > 0;
        }
        unset($poll);

        return $polls;
    }

    public function publicFeatures() {
        $this->sendJsonHeaders();
        try {
            $db = Database::connect();
            $this->ensureInteractionSchema($db);
            $camp = $this->getPublicCamp($db);

            if (!$camp) {
                echo json_encode([
                    'camp' => null,
                    'lost_found' => [],
                    'polls' => [],
                    'noticeboard' => [],
                    'version' => sha1('empty-features')
                ]);
                return;
            }

            $lostFound = $this->fetchLostFoundForCamp($db, $camp['id'], true);
            $polls = $this->fetchPollsForCamp($db, $camp['id'], true);
            $noticeboard = $this->fetchNoticeboardForCamp($db, $camp['id'], true);

            echo json_encode([
                'camp' => [
                    'id' => $camp['id'],
                    'name' => $camp['name'],
                    'year' => $camp['year'],
                    'start_date' => $camp['start_date'],
                    'end_date' => $camp['end_date'],
                    'status' => $camp['status'] ?? null,
                    'is_active' => strtolower((string)($camp['status'] ?? '')) === 'active'
                ],
                'lost_found' => $lostFound,
                'polls' => $polls,
                'noticeboard' => $noticeboard,
                'version' => sha1(json_encode([$camp['id'], $lostFound, $polls, $noticeboard]))
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Server error', 'error' => $e->getMessage()]);
        }
    }

    public function adminFeatures() {
        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorised']);
            return;
        }

        $this->sendJsonHeaders();
        try {
            $db = Database::connect();
            $this->ensureInteractionSchema($db);
            $camp = $this->getActiveCamp($db);

            if (!$camp) {
                echo json_encode([
                    'camp' => null,
                    'inbox' => [],
                    'site_updates' => [],
                    'check_ins' => [],
                    'lost_found' => [],
                    'polls' => [],
                    'noticeboard' => [],
                    'version' => sha1('empty-admin-features')
                ]);
                return;
            }

            $inbox = $this->fetchMessagesForCamp($db, $camp['id']);
            $siteUpdates = $this->fetchSiteUpdatesForCamp($db, $camp['id']);
            $checkIns = $this->fetchCheckInsForCamp($db, $camp['id']);
            $lostFound = $this->fetchLostFoundForCamp($db, $camp['id'], false);
            $polls = $this->fetchPollsForCamp($db, $camp['id'], false);
            $noticeboard = $this->fetchNoticeboardForCamp($db, $camp['id'], false);

            echo json_encode([
                'camp' => $camp,
                'user' => Auth::user(),
                'inbox' => $inbox,
                'site_updates' => $siteUpdates,
                'check_ins' => $checkIns,
                'lost_found' => $lostFound,
                'polls' => $polls,
                'noticeboard' => $noticeboard,
                'version' => sha1(json_encode([$camp['id'], $inbox, $siteUpdates, $checkIns, $lostFound, $polls, $noticeboard]))
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Server error', 'error' => $e->getMessage()]);
        }
    }

    public function adminNotifications() {
        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorised']);
            return;
        }

        $this->sendJsonHeaders();
        try {
            $db = Database::connect();
            $this->ensureInteractionSchema($db);

            $stmt = $db->query("
                SELECT id, source_type, source_id, title, body, link_path, payload_json, is_read, read_at, created_at
                FROM admin_notifications
                WHERE source_type <> 'check_in'
                ORDER BY is_read ASC, created_at DESC, id DESC
                LIMIT 120
            ");
            $rows = $stmt ? $stmt->fetchAll() : [];
            $notifications = array_map(function($row) {
                return [
                    'id' => (int)$row['id'],
                    'source_type' => $row['source_type'],
                    'source_id' => (int)$row['source_id'],
                    'title' => $row['title'],
                    'body' => $row['body'],
                    'link_path' => $row['link_path'],
                    'payload' => $row['payload_json'] ? json_decode($row['payload_json'], true) : null,
                    'is_read' => (int)$row['is_read'],
                    'read_at' => $row['read_at'],
                    'created_at' => $row['created_at']
                ];
            }, $rows);

            $unreadStmt = $db->query("SELECT COUNT(*) FROM admin_notifications WHERE is_read = 0 AND source_type <> 'check_in'");
            $unreadCount = $unreadStmt ? (int)$unreadStmt->fetchColumn() : 0;

            echo json_encode([
                'notifications' => $notifications,
                'unread_count' => $unreadCount
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Unable to load notifications', 'error' => $e->getMessage()]);
        }
    }

    public function adminMarkNotificationRead() {
        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorised']);
            return;
        }

        $this->sendJsonHeaders();
        try {
            $db = Database::connect();
            $this->ensureInteractionSchema($db);
            $input = $this->readInput();
            $id = isset($input['id']) ? (int)$input['id'] : 0;
            if ($id <= 0) {
                http_response_code(422);
                echo json_encode(['message' => 'Notification id is required.']);
                return;
            }

            $stmt = $db->prepare("UPDATE admin_notifications SET is_read = 1, read_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Unable to update notification', 'error' => $e->getMessage()]);
        }
    }

    public function adminMarkAllNotificationsRead() {
        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorised']);
            return;
        }

        $this->sendJsonHeaders();
        try {
            $db = Database::connect();
            $this->ensureInteractionSchema($db);
            $db->exec("UPDATE admin_notifications SET is_read = 1, read_at = NOW() WHERE is_read = 0");
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Unable to update notifications', 'error' => $e->getMessage()]);
        }
    }

    public function adminNotificationRecipients() {
        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorised']);
            return;
        }

        $this->sendJsonHeaders();
        try {
            $db = Database::connect();
            $this->ensureInteractionSchema($db);
            echo json_encode([
                'recipients' => $this->fetchNotificationRecipients($db, false),
                'mail_status' => $this->notificationMailStatus()
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Unable to load notification recipients', 'error' => $e->getMessage()]);
        }
    }

    public function adminSaveNotificationRecipient() {
        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorised']);
            return;
        }

        $this->sendJsonHeaders();
        try {
            $db = Database::connect();
            $this->ensureInteractionSchema($db);
            $input = $this->readInput();

            $id = isset($input['id']) ? (int)$input['id'] : 0;
            $label = $this->collapseWhitespace($input['label'] ?? '');
            $email = trim((string)($input['email'] ?? ''));
            $isActive = !empty($input['is_active']) ? 1 : 0;

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(422);
                echo json_encode(['message' => 'Please enter a valid email address.']);
                return;
            }

            if ($label === '') {
                $label = $email;
            }

            if ($id > 0) {
                $stmt = $db->prepare("
                    UPDATE admin_notification_recipients
                    SET label = ?, email = ?, is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([$label, $email, $isActive, $id]);
            } else {
                $stmt = $db->prepare("
                    INSERT INTO admin_notification_recipients (label, email, is_active)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$label, $email, $isActive]);
            }

            echo json_encode([
                'success' => true,
                'recipients' => $this->fetchNotificationRecipients($db, false),
                'mail_status' => $this->notificationMailStatus()
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            if (($e->getCode() ?? '') === '23000') {
                echo json_encode(['message' => 'That email address is already on the notification list.', 'error' => $e->getMessage()]);
                return;
            }
            echo json_encode(['message' => 'Unable to save notification recipient', 'error' => $e->getMessage()]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Unable to save notification recipient', 'error' => $e->getMessage()]);
        }
    }

    public function adminDeleteNotificationRecipient() {
        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorised']);
            return;
        }

        $this->sendJsonHeaders();
        try {
            $db = Database::connect();
            $this->ensureInteractionSchema($db);
            $input = $this->readInput();
            $id = isset($input['id']) ? (int)$input['id'] : 0;
            if ($id <= 0) {
                http_response_code(422);
                echo json_encode(['message' => 'Recipient id is required.']);
                return;
            }

            $stmt = $db->prepare("DELETE FROM admin_notification_recipients WHERE id = ?");
            $stmt->execute([$id]);

            echo json_encode([
                'success' => true,
                'recipients' => $this->fetchNotificationRecipients($db, false),
                'mail_status' => $this->notificationMailStatus()
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Unable to delete notification recipient', 'error' => $e->getMessage()]);
        }
    }

    public function adminTestNotificationRecipient() {
        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorised']);
            return;
        }

        $this->sendJsonHeaders();
        try {
            $db = Database::connect();
            $this->ensureInteractionSchema($db);
            $input = $this->readInput();
            $id = isset($input['id']) ? (int)$input['id'] : 0;
            if ($id <= 0) {
                http_response_code(422);
                echo json_encode(['message' => 'Recipient id is required.', 'mail_status' => $this->notificationMailStatus()]);
                return;
            }

            $recipient = $this->fetchNotificationRecipientById($db, $id);
            if (!$recipient) {
                http_response_code(404);
                echo json_encode(['message' => 'Notification recipient not found.', 'mail_status' => $this->notificationMailStatus()]);
                return;
            }

            $body = "This is a Campo SMTP test email.\n\n"
                . "Recipient: " . ($recipient['label'] ?: $recipient['email']) . "\n"
                . "Sent at: " . date('Y-m-d H:i:s') . "\n"
                . "Admin link: " . $this->appBaseUrl() . "/intranet-admin\n";

            CampoMailer::sendText($recipient['email'], '[Campo] Notification email test', $body, $_SERVER);

            echo json_encode([
                'success' => true,
                'message' => 'Test email sent to ' . $recipient['email'] . '.',
                'mail_status' => $this->notificationMailStatus()
            ]);
        } catch (RuntimeException $e) {
            http_response_code(422);
            echo json_encode(['message' => $e->getMessage(), 'mail_status' => $this->notificationMailStatus()]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Unable to send test email', 'error' => $e->getMessage(), 'mail_status' => $this->notificationMailStatus()]);
        }
    }

    public function publicSubmitMessage() {
        $this->sendJsonHeaders();
        try {
            $db = Database::connect();
            $this->ensureInteractionSchema($db);
            $camp = $this->requireActiveCamp($db);
            $input = $this->readInput();

            $name = $this->collapseWhitespace($input['name'] ?? '');
            $siteNumber = $this->collapseWhitespace($input['site_number'] ?? '');
            $category = $this->collapseWhitespace($input['category'] ?? 'General Question');
            $message = trim((string)($input['message'] ?? ''));

            if ($name === '' || $siteNumber === '' || $message === '') {
                http_response_code(422);
                echo json_encode(['message' => 'Please enter your name, site number, and message.']);
                return;
            }

            $verification = $this->resolveVerification($db, $siteNumber, $name);
            $stmt = $db->prepare("
                INSERT INTO camp_intranet_messages (camp_id, category, submitter_name, site_number, site_id, message, status, is_verified, verification_note)
                VALUES (?, ?, ?, ?, ?, ?, 'new', ?, ?)
            ");
            $stmt->execute([
                $camp['id'],
                $category,
                $name,
                $siteNumber,
                $verification['site_id'],
                $message,
                $verification['is_verified'],
                $verification['verification_note']
            ]);

            $this->notifyAdminsForSubmission($db, 'message', (int)$db->lastInsertId(), [
                'camp_id' => $camp['id'],
                'category' => $category,
                'submitter_name' => $name,
                'site_number' => $siteNumber,
                'site_id' => $verification['site_id'],
                'message' => $message,
                'verification_note' => $verification['verification_note']
            ]);

            echo json_encode([
                'success' => true,
                'verification' => $verification['is_verified'] ? 'verified' : 'unverified',
                'message' => 'Your message has been sent to admin.'
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Server error', 'error' => $e->getMessage()]);
        }
    }

    public function publicSubmitSiteUpdate() {
        $this->sendJsonHeaders();
        try {
            $db = Database::connect();
            $this->ensureInteractionSchema($db);
            $camp = $this->requirePublicCamp($db);
            $input = $this->readInput();

            $siteNumber = $this->collapseWhitespace($input['site_number'] ?? '');
            $memberFirstName = $this->collapseWhitespace($input['member_first_name'] ?? '');
            $memberLastName = $this->collapseWhitespace($input['member_last_name'] ?? '');
            $phoneNumber = $this->collapseWhitespace($input['phone_number'] ?? '');
            $email = trim((string)($input['email'] ?? ''));
            $otherMembers = trim((string)($input['other_members'] ?? ''));
            $fullName = trim("{$memberFirstName} {$memberLastName}");

            if ($siteNumber === '' || $memberFirstName === '' || $memberLastName === '') {
                http_response_code(422);
                echo json_encode(['message' => 'Please enter the site number and member first and last name.']);
                return;
            }

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(422);
                echo json_encode(['message' => 'Please enter a valid email address or leave it blank.']);
                return;
            }

            $verification = $this->resolveVerification($db, $siteNumber, $fullName);
            $stmt = $db->prepare("
                INSERT INTO camp_intranet_site_updates (camp_id, site_number, site_id, member_first_name, member_last_name, phone_number, email, other_members, status, is_verified, verification_note)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'new', ?, ?)
            ");
            $stmt->execute([
                $camp['id'],
                $siteNumber,
                $verification['site_id'],
                $memberFirstName,
                $memberLastName,
                $phoneNumber,
                $email,
                $otherMembers,
                $verification['is_verified'],
                $verification['verification_note']
            ]);

            $this->notifyAdminsForSubmission($db, 'site_update', (int)$db->lastInsertId(), [
                'camp_id' => $camp['id'],
                'site_number' => $siteNumber,
                'site_id' => $verification['site_id'],
                'member_first_name' => $memberFirstName,
                'member_last_name' => $memberLastName,
                'phone_number' => $phoneNumber,
                'email' => $email,
                'other_members' => $otherMembers,
                'verification_note' => $verification['verification_note']
            ]);

            echo json_encode([
                'success' => true,
                'verification' => $verification['is_verified'] ? 'verified' : 'unverified',
                'message' => 'Your site details update request has been sent to admin.'
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Server error', 'error' => $e->getMessage()]);
        }
    }

    public function publicSubmitCheckIn() {
        $this->sendJsonHeaders();
        try {
            $db = Database::connect();
            $this->ensureInteractionSchema($db);
            $camp = $this->requireActiveCamp($db);
            $input = $this->readInput();

            $name = $this->collapseWhitespace($input['name'] ?? '');
            $siteNumber = $this->collapseWhitespace($input['site_number'] ?? '');
            $phoneNumber = $this->collapseWhitespace($input['phone_number'] ?? '');
            $email = trim((string)($input['email'] ?? ''));
            $arrivalDate = $this->collapseWhitespace($input['arrival_date'] ?? '');
            $departureDate = $this->collapseWhitespace($input['departure_date'] ?? '');
            $adultsCount = max(0, (int)($input['adults_count'] ?? 0));
            $kidsCount = max(0, (int)($input['kids_count'] ?? 0));
            $siteType = $this->collapseWhitespace($input['site_type'] ?? '');
            $isDayTrip = !empty($input['is_day_trip']) ? 1 : 0;
            $selectedMemberId = max(0, (int)($input['selected_member_id'] ?? 0));
            $selectedMember = $selectedMemberId > 0 ? $this->resolveSelectedCheckInMember($db, $selectedMemberId) : null;

            if ($selectedMember) {
                if ($selectedMember['name'] !== '') {
                    $name = $selectedMember['name'];
                }
                if ($selectedMember['site_number'] !== '') {
                    $siteNumber = $selectedMember['site_number'];
                }
                if ($phoneNumber === '' && $selectedMember['phone_number'] !== '') {
                    $phoneNumber = $selectedMember['phone_number'];
                }
                if ($email === '' && $selectedMember['email'] !== '') {
                    $email = $selectedMember['email'];
                }
            }

            if ($name === '' || $siteNumber === '' || $arrivalDate === '' || $departureDate === '') {
                http_response_code(422);
                echo json_encode(['message' => 'Please enter your name, site number, arrival date, and departure date.']);
                return;
            }

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(422);
                echo json_encode(['message' => 'Please enter a valid email address or leave it blank.']);
                return;
            }

            $arrivalTimestamp = strtotime($arrivalDate);
            $departureTimestamp = strtotime($departureDate);
            if (!$arrivalTimestamp || !$departureTimestamp) {
                http_response_code(422);
                echo json_encode(['message' => 'Please enter valid arrival and departure dates.']);
                return;
            }

            $arrivalIso = date('Y-m-d', $arrivalTimestamp);
            $departureIso = date('Y-m-d', $departureTimestamp);
            if ($arrivalIso > $departureIso) {
                http_response_code(422);
                echo json_encode(['message' => 'Departure date must be on or after the arrival date.']);
                return;
            }

            if ($selectedMember && !empty($selectedMember['site_id']) && $selectedMember['site_number'] !== '') {
                $verification = [
                    'site_id' => (int)$selectedMember['site_id'],
                    'site_number' => $selectedMember['site_number'],
                    'is_verified' => 1,
                    'verification_note' => $selectedMember['verification_note']
                ];
                $match = [
                    'matched_member_id' => (int)($selectedMember['matched_member_id'] ?? 0),
                    'matched_household_id' => (int)($selectedMember['matched_household_id'] ?? 0)
                ];
            } else {
                $verification = $this->resolveVerification($db, $siteNumber, $name);
                $match = $selectedMember
                    ? [
                        'matched_member_id' => (int)($selectedMember['matched_member_id'] ?? 0),
                        'matched_household_id' => (int)($selectedMember['matched_household_id'] ?? 0)
                    ]
                    : $this->resolveCheckInMatch($db, $verification['site_id'] ?? null, $name);
            }
            $existing = $this->findOpenCheckIn(
                $db,
                (int)$camp['id'],
                $match['matched_household_id'] ?? null,
                $match['matched_member_id'] ?? null,
                $verification['site_id'] ?? null,
                $name
            );

            if ($existing) {
                $stmt = $db->prepare("
                    UPDATE camp_intranet_checkins
                    SET
                        site_number = ?,
                        site_id = ?,
                        submitter_name = ?,
                        phone_number = ?,
                        email = ?,
                        arrival_date = ?,
                        departure_date = ?,
                        adults_count = ?,
                        kids_count = ?,
                        site_type = ?,
                        is_day_trip = ?,
                        matched_member_id = ?,
                        matched_household_id = ?,
                        is_verified = ?,
                        verification_note = ?
                    WHERE id = ? AND camp_id = ?
                ");
                $stmt->execute([
                    $siteNumber,
                    $verification['site_id'],
                    $name,
                    $phoneNumber,
                    $email,
                    $arrivalIso,
                    $departureIso,
                    $adultsCount,
                    $kidsCount,
                    $siteType !== '' ? $siteType : null,
                    $isDayTrip,
                    $match['matched_member_id'] ?: null,
                    $match['matched_household_id'] ?: null,
                    $verification['is_verified'],
                    $verification['verification_note'],
                    (int)$existing['id'],
                    (int)$camp['id']
                ]);
                $checkInId = (int)$existing['id'];
                $message = 'Your camp check-in has been updated. Please come to the office to confirm and pay.';
            } else {
                $stmt = $db->prepare("
                    INSERT INTO camp_intranet_checkins (
                        camp_id,
                        site_number,
                        site_id,
                        submitter_name,
                        phone_number,
                        email,
                        arrival_date,
                        departure_date,
                        adults_count,
                        kids_count,
                        site_type,
                        is_day_trip,
                        matched_member_id,
                        matched_household_id,
                        status,
                        is_verified,
                        verification_note
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new', ?, ?)
                ");
                $stmt->execute([
                    (int)$camp['id'],
                    $siteNumber,
                    $verification['site_id'],
                    $name,
                    $phoneNumber,
                    $email,
                    $arrivalIso,
                    $departureIso,
                    $adultsCount,
                    $kidsCount,
                    $siteType !== '' ? $siteType : null,
                    $isDayTrip,
                    $match['matched_member_id'] ?: null,
                    $match['matched_household_id'] ?: null,
                    $verification['is_verified'],
                    $verification['verification_note']
                ]);
                $checkInId = (int)$db->lastInsertId();
                $message = 'Your camp check-in has been saved. Please come to the office to confirm and pay.';
            }

            $this->notifyAdminsForSubmission($db, 'check_in', $checkInId, [
                'camp_id' => $camp['id'],
                'site_number' => $siteNumber,
                'site_id' => $verification['site_id'],
                'submitter_name' => $name,
                'phone_number' => $phoneNumber,
                'email' => $email,
                'arrival_date' => $arrivalIso,
                'departure_date' => $departureIso,
                'adults_count' => $adultsCount,
                'kids_count' => $kidsCount,
                'site_type' => $siteType,
                'is_day_trip' => $isDayTrip,
                'verification_note' => $verification['verification_note']
            ]);

            echo json_encode([
                'success' => true,
                'verification' => $verification['is_verified'] ? 'verified' : 'unverified',
                'message' => $message
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Server error', 'error' => $e->getMessage()]);
        }
    }

    public function publicSearchCheckInMembers() {
        $this->sendJsonHeaders();
        try {
            $db = Database::connect();
            $this->ensureInteractionSchema($db);
            $this->requireActiveCamp($db);
            $query = $this->collapseWhitespace($_GET['q'] ?? '');

            echo json_encode([
                'success' => true,
                'results' => $this->searchCheckInMembers($db, $query, 8)
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Server error', 'error' => $e->getMessage()]);
        }
    }

    public function adminUpdateMessage($id) {
        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorised']);
            return;
        }

        $this->sendJsonHeaders();
        try {
            $db = Database::connect();
            $this->ensureInteractionSchema($db);
            $camp = $this->requireActiveCamp($db);
            $input = $this->readInput();
            $status = $this->normaliseStatus($input['status'] ?? 'new', ['new', 'in_progress', 'resolved', 'archived'], 'new');
            $adminNotes = trim((string)($input['admin_notes'] ?? ''));

            $stmt = $db->prepare("
                UPDATE camp_intranet_messages
                SET status = ?, admin_notes = ?
                WHERE id = ? AND camp_id = ?
            ");
            $stmt->execute([$status, $adminNotes, (int)$id, $camp['id']]);

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Server error', 'error' => $e->getMessage()]);
        }
    }

    public function adminUpdateSiteUpdate($id) {
        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorised']);
            return;
        }

        $this->sendJsonHeaders();
        try {
            $db = Database::connect();
            $this->ensureInteractionSchema($db);
            $camp = $this->requireActiveCamp($db);
            $input = $this->readInput();
            $status = $this->normaliseStatus($input['status'] ?? 'new', ['new', 'in_progress', 'resolved', 'archived'], 'new');
            $adminNotes = trim((string)($input['admin_notes'] ?? ''));

            $stmt = $db->prepare("
                UPDATE camp_intranet_site_updates
                SET status = ?, admin_notes = ?
                WHERE id = ? AND camp_id = ?
            ");
            $stmt->execute([$status, $adminNotes, (int)$id, $camp['id']]);

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Server error', 'error' => $e->getMessage()]);
        }
    }

    public function adminDeleteSiteUpdate($id) {
        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorised']);
            return;
        }

        $this->sendJsonHeaders();
        try {
            $db = Database::connect();
            $this->ensureInteractionSchema($db);
            $camp = $this->requireActiveCamp($db);
            $stmt = $db->prepare("DELETE FROM camp_intranet_site_updates WHERE id = ? AND camp_id = ?");
            $stmt->execute([(int)$id, $camp['id']]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Server error', 'error' => $e->getMessage()]);
        }
    }

    public function adminUpdateCheckIn($id) {
        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorised']);
            return;
        }

        $this->sendJsonHeaders();
        try {
            $db = Database::connect();
            $this->ensureInteractionSchema($db);
            $camp = $this->requireActiveCamp($db);
            $input = $this->readInput();
            $status = $this->normaliseStatus($input['status'] ?? 'new', ['new', 'in_progress', 'resolved', 'archived'], 'new');
            $adminNotes = trim((string)($input['admin_notes'] ?? ''));

            $stmt = $db->prepare("
                UPDATE camp_intranet_checkins
                SET status = ?, admin_notes = ?
                WHERE id = ? AND camp_id = ?
            ");
            $stmt->execute([$status, $adminNotes, (int)$id, $camp['id']]);

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Server error', 'error' => $e->getMessage()]);
        }
    }

    public function adminDeleteCheckIn($id) {
        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorised']);
            return;
        }

        $this->sendJsonHeaders();
        try {
            $db = Database::connect();
            $this->ensureInteractionSchema($db);
            $camp = $this->requireActiveCamp($db);
            $stmt = $db->prepare("DELETE FROM camp_intranet_checkins WHERE id = ? AND camp_id = ?");
            $stmt->execute([(int)$id, $camp['id']]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Server error', 'error' => $e->getMessage()]);
        }
    }

    public function publicSubmitLostFound() {
        $this->sendJsonHeaders();
        try {
            $db = Database::connect();
            $this->ensureInteractionSchema($db);
            $camp = $this->requireActiveCamp($db);
            $input = $this->readInput();

            $itemType = $this->normaliseStatus($input['item_type'] ?? 'found', ['lost', 'found'], 'found');
            $title = $this->collapseWhitespace($input['title'] ?? '');
            $description = trim((string)($input['description'] ?? ''));
            $name = $this->collapseWhitespace($input['name'] ?? '');
            $siteNumber = $this->collapseWhitespace($input['site_number'] ?? '');
            $locationDetails = $this->collapseWhitespace($input['location_details'] ?? '');
            $contactDetails = $this->collapseWhitespace($input['contact_details'] ?? '');

            if ($title === '' || $description === '' || $name === '' || $siteNumber === '') {
                http_response_code(422);
                echo json_encode(['message' => 'Please enter your name, site number, item title, and description.']);
                return;
            }

            $verification = $this->resolveVerification($db, $siteNumber, $name);
            $stmt = $db->prepare("
                INSERT INTO camp_intranet_lost_found (camp_id, item_type, title, description, location_details, contact_details, reporter_name, site_number, site_id, status, is_verified, verification_note)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)
            ");
            $stmt->execute([
                $camp['id'],
                $itemType,
                $title,
                $description,
                $locationDetails,
                $contactDetails,
                $name,
                $siteNumber,
                $verification['site_id'],
                $verification['is_verified'],
                $verification['verification_note']
            ]);

            $this->notifyAdminsForSubmission($db, 'lost_found', (int)$db->lastInsertId(), [
                'camp_id' => $camp['id'],
                'item_type' => $itemType,
                'title' => $title,
                'description' => $description,
                'location_details' => $locationDetails,
                'contact_details' => $contactDetails,
                'reporter_name' => $name,
                'site_number' => $siteNumber,
                'site_id' => $verification['site_id'],
                'verification_note' => $verification['verification_note']
            ]);

            echo json_encode(['success' => true, 'message' => 'Your lost and found submission has been sent for admin approval.']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Server error', 'error' => $e->getMessage()]);
        }
    }

    public function publicSubmitNoticeboard() {
        $this->sendJsonHeaders();
        try {
            $db = Database::connect();
            $this->ensureInteractionSchema($db);
            $camp = $this->requireActiveCamp($db);
            $input = $this->readInput();

            $category = $this->collapseWhitespace($input['category'] ?? 'General');
            $title = $this->collapseWhitespace($input['title'] ?? '');
            $message = trim((string)($input['message'] ?? ''));
            $contactDetails = $this->collapseWhitespace($input['contact_details'] ?? '');
            $name = $this->collapseWhitespace($input['name'] ?? '');
            $siteNumber = $this->collapseWhitespace($input['site_number'] ?? '');

            if ($title === '' || $message === '' || $name === '' || $siteNumber === '') {
                http_response_code(422);
                echo json_encode(['message' => 'Please enter your name, site number, notice title, and message.']);
                return;
            }

            $verification = $this->resolveVerification($db, $siteNumber, $name);
            $stmt = $db->prepare("
                INSERT INTO camp_intranet_noticeboard (camp_id, category, title, message, contact_details, author_name, site_number, site_id, status, is_verified, verification_note)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)
            ");
            $stmt->execute([
                $camp['id'],
                $category,
                $title,
                $message,
                $contactDetails,
                $name,
                $siteNumber,
                $verification['site_id'],
                $verification['is_verified'],
                $verification['verification_note']
            ]);

            $this->notifyAdminsForSubmission($db, 'noticeboard', (int)$db->lastInsertId(), [
                'camp_id' => $camp['id'],
                'category' => $category,
                'title' => $title,
                'message' => $message,
                'contact_details' => $contactDetails,
                'author_name' => $name,
                'site_number' => $siteNumber,
                'site_id' => $verification['site_id'],
                'verification_note' => $verification['verification_note']
            ]);

            echo json_encode(['success' => true, 'message' => 'Your notice has been sent for admin approval.']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Server error', 'error' => $e->getMessage()]);
        }
    }

    public function publicSubmitPollResponse() {
        $this->sendJsonHeaders();
        try {
            $db = Database::connect();
            $this->ensureInteractionSchema($db);
            $camp = $this->requireActiveCamp($db);
            $input = $this->readInput();

            $pollId = isset($input['poll_id']) ? (int)$input['poll_id'] : 0;
            $optionId = isset($input['option_id']) ? (int)$input['option_id'] : 0;
            $name = $this->collapseWhitespace($input['name'] ?? '');
            $siteNumber = $this->collapseWhitespace($input['site_number'] ?? '');

            if ($pollId <= 0 || $optionId <= 0 || $name === '' || $siteNumber === '') {
                http_response_code(422);
                echo json_encode(['message' => 'Please choose an option and enter your name and site number.']);
                return;
            }

            $pollStmt = $db->prepare("
                SELECT id, status, closes_at
                FROM camp_intranet_polls
                WHERE id = ? AND camp_id = ?
                LIMIT 1
            ");
            $pollStmt->execute([$pollId, $camp['id']]);
            $poll = $pollStmt->fetch();
            if (!$poll) {
                http_response_code(404);
                echo json_encode(['message' => 'Poll not found.']);
                return;
            }

            if ($poll['status'] !== 'live' || ($poll['closes_at'] && strtotime($poll['closes_at']) < time())) {
                http_response_code(422);
                echo json_encode(['message' => 'This poll is closed.']);
                return;
            }

            $optionStmt = $db->prepare("SELECT id FROM camp_intranet_poll_options WHERE id = ? AND poll_id = ? LIMIT 1");
            $optionStmt->execute([$optionId, $pollId]);
            if (!$optionStmt->fetch()) {
                http_response_code(422);
                echo json_encode(['message' => 'That option is no longer available.']);
                return;
            }

            $verification = $this->resolveVerification($db, $siteNumber, $name);
            $responseKey = $this->cleanKey($siteNumber) . '|' . $this->cleanKey($name);

            $existingStmt = $db->prepare("SELECT id FROM camp_intranet_poll_responses WHERE poll_id = ? AND response_key = ? LIMIT 1");
            $existingStmt->execute([$pollId, $responseKey]);
            $existing = $existingStmt->fetch();

            if ($existing) {
                $stmt = $db->prepare("
                    UPDATE camp_intranet_poll_responses
                    SET option_id = ?, responder_name = ?, site_number = ?, site_id = ?, is_verified = ?, verification_note = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $optionId,
                    $name,
                    $siteNumber,
                    $verification['site_id'],
                    $verification['is_verified'],
                    $verification['verification_note'],
                    $existing['id']
                ]);
            } else {
                $stmt = $db->prepare("
                    INSERT INTO camp_intranet_poll_responses (poll_id, option_id, responder_name, site_number, site_id, response_key, is_verified, verification_note)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $pollId,
                    $optionId,
                    $name,
                    $siteNumber,
                    $verification['site_id'],
                    $responseKey,
                    $verification['is_verified'],
                    $verification['verification_note']
                ]);
            }

            echo json_encode(['success' => true, 'message' => 'Your response has been recorded.']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Server error', 'error' => $e->getMessage()]);
        }
    }

    public function adminSaveLostFound($id = null) {
        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorised']);
            return;
        }

        $this->sendJsonHeaders();
        try {
            $db = Database::connect();
            $this->ensureInteractionSchema($db);
            $camp = $this->requireActiveCamp($db);
            $input = $this->readInput();

            $itemType = $this->normaliseStatus($input['item_type'] ?? 'found', ['lost', 'found'], 'found');
            $status = $this->normaliseStatus($input['status'] ?? 'approved', ['pending', 'approved', 'rejected', 'returned', 'archived'], 'approved');
            $title = $this->collapseWhitespace($input['title'] ?? '');
            $description = trim((string)($input['description'] ?? ''));
            $locationDetails = $this->collapseWhitespace($input['location_details'] ?? '');
            $contactDetails = $this->collapseWhitespace($input['contact_details'] ?? '');
            $reporterName = $this->collapseWhitespace($input['reporter_name'] ?? '');
            $siteNumber = $this->collapseWhitespace($input['site_number'] ?? '');
            $adminNotes = trim((string)($input['admin_notes'] ?? ''));

            if ($title === '' || $description === '') {
                http_response_code(422);
                echo json_encode(['message' => 'Please enter a title and description.']);
                return;
            }

            $current = null;
            if ($id) {
                $stmt = $db->prepare("SELECT * FROM camp_intranet_lost_found WHERE id = ? AND camp_id = ? LIMIT 1");
                $stmt->execute([(int)$id, $camp['id']]);
                $current = $stmt->fetch();
                if (!$current) {
                    http_response_code(404);
                    echo json_encode(['message' => 'Lost and found item not found.']);
                    return;
                }
            }

            $verification = [
                'site_id' => null,
                'is_verified' => 0,
                'verification_note' => $reporterName !== '' || $siteNumber !== '' ? 'Name and site will be reviewed by admin.' : 'Added by admin.'
            ];
            if ($reporterName !== '' && $siteNumber !== '') {
                $verification = $this->resolveVerification($db, $siteNumber, $reporterName);
            }

            $approvedAt = $current['approved_at'] ?? null;
            if ($status === 'approved' && !$approvedAt) {
                $approvedAt = date('Y-m-d H:i:s');
            }

            if ($current) {
                $stmt = $db->prepare("
                    UPDATE camp_intranet_lost_found
                    SET item_type = ?, title = ?, description = ?, location_details = ?, contact_details = ?, reporter_name = ?, site_number = ?, site_id = ?, status = ?, is_verified = ?, verification_note = ?, admin_notes = ?, approved_at = ?
                    WHERE id = ? AND camp_id = ?
                ");
                $stmt->execute([
                    $itemType,
                    $title,
                    $description,
                    $locationDetails,
                    $contactDetails,
                    $reporterName,
                    $siteNumber,
                    $verification['site_id'],
                    $status,
                    $verification['is_verified'],
                    $verification['verification_note'],
                    $adminNotes,
                    $approvedAt,
                    (int)$id,
                    $camp['id']
                ]);
            } else {
                $stmt = $db->prepare("
                    INSERT INTO camp_intranet_lost_found (camp_id, item_type, title, description, location_details, contact_details, reporter_name, site_number, site_id, status, is_verified, verification_note, admin_notes, approved_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $camp['id'],
                    $itemType,
                    $title,
                    $description,
                    $locationDetails,
                    $contactDetails,
                    $reporterName,
                    $siteNumber,
                    $verification['site_id'],
                    $status,
                    $verification['is_verified'],
                    $verification['verification_note'],
                    $adminNotes,
                    $approvedAt
                ]);
            }

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Server error', 'error' => $e->getMessage()]);
        }
    }

    public function adminSaveNoticeboard($id = null) {
        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorised']);
            return;
        }

        $this->sendJsonHeaders();
        try {
            $db = Database::connect();
            $this->ensureInteractionSchema($db);
            $camp = $this->requireActiveCamp($db);
            $input = $this->readInput();

            $category = $this->collapseWhitespace($input['category'] ?? 'General');
            $title = $this->collapseWhitespace($input['title'] ?? '');
            $message = trim((string)($input['message'] ?? ''));
            $contactDetails = $this->collapseWhitespace($input['contact_details'] ?? '');
            $authorName = $this->collapseWhitespace($input['author_name'] ?? '');
            $siteNumber = $this->collapseWhitespace($input['site_number'] ?? '');
            $status = $this->normaliseStatus($input['status'] ?? 'approved', ['pending', 'approved', 'rejected', 'expired', 'archived'], 'approved');
            $expiresAt = $this->normaliseDateTime($input['expires_at'] ?? null);

            if ($title === '' || $message === '') {
                http_response_code(422);
                echo json_encode(['message' => 'Please enter a notice title and message.']);
                return;
            }

            $current = null;
            if ($id) {
                $stmt = $db->prepare("SELECT * FROM camp_intranet_noticeboard WHERE id = ? AND camp_id = ? LIMIT 1");
                $stmt->execute([(int)$id, $camp['id']]);
                $current = $stmt->fetch();
                if (!$current) {
                    http_response_code(404);
                    echo json_encode(['message' => 'Noticeboard item not found.']);
                    return;
                }
            }

            $verification = [
                'site_id' => null,
                'is_verified' => 0,
                'verification_note' => $authorName !== '' || $siteNumber !== '' ? 'Name and site will be reviewed by admin.' : 'Added by admin.'
            ];
            if ($authorName !== '' && $siteNumber !== '') {
                $verification = $this->resolveVerification($db, $siteNumber, $authorName);
            }

            $approvedAt = $current['approved_at'] ?? null;
            if ($status === 'approved' && !$approvedAt) {
                $approvedAt = date('Y-m-d H:i:s');
            }

            if ($current) {
                $stmt = $db->prepare("
                    UPDATE camp_intranet_noticeboard
                    SET category = ?, title = ?, message = ?, contact_details = ?, author_name = ?, site_number = ?, site_id = ?, status = ?, is_verified = ?, verification_note = ?, approved_at = ?, expires_at = ?
                    WHERE id = ? AND camp_id = ?
                ");
                $stmt->execute([
                    $category,
                    $title,
                    $message,
                    $contactDetails,
                    $authorName,
                    $siteNumber,
                    $verification['site_id'],
                    $status,
                    $verification['is_verified'],
                    $verification['verification_note'],
                    $approvedAt,
                    $expiresAt,
                    (int)$id,
                    $camp['id']
                ]);
            } else {
                $stmt = $db->prepare("
                    INSERT INTO camp_intranet_noticeboard (camp_id, category, title, message, contact_details, author_name, site_number, site_id, status, is_verified, verification_note, approved_at, expires_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $camp['id'],
                    $category,
                    $title,
                    $message,
                    $contactDetails,
                    $authorName,
                    $siteNumber,
                    $verification['site_id'],
                    $status,
                    $verification['is_verified'],
                    $verification['verification_note'],
                    $approvedAt,
                    $expiresAt
                ]);
            }

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Server error', 'error' => $e->getMessage()]);
        }
    }

    public function adminSavePoll($id = null) {
        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorised']);
            return;
        }

        $this->sendJsonHeaders();
        try {
            $db = Database::connect();
            $this->ensureInteractionSchema($db);
            $camp = $this->requireActiveCamp($db);
            $input = $this->readInput();

            $title = $this->collapseWhitespace($input['title'] ?? '');
            $description = trim((string)($input['description'] ?? ''));
            $pollType = $this->normaliseStatus($input['poll_type'] ?? 'poll', ['poll', 'eoi'], 'poll');
            $status = $this->normaliseStatus($input['status'] ?? 'draft', ['draft', 'live', 'closed', 'archived'], 'draft');
            $showResultsPublic = !empty($input['show_results_public']) ? 1 : 0;
            $closesAt = $this->normaliseDateTime($input['closes_at'] ?? null);

            $rawOptions = $input['options'] ?? [];
            if (is_string($rawOptions)) {
                $rawOptions = preg_split('/\r\n|\r|\n/', $rawOptions);
            }
            if (!is_array($rawOptions)) {
                $rawOptions = [];
            }

            $options = [];
            foreach ($rawOptions as $option) {
                $clean = $this->collapseWhitespace($option);
                if ($clean !== '') {
                    $options[] = $clean;
                }
            }
            $options = array_values(array_unique($options));

            if ($title === '') {
                http_response_code(422);
                echo json_encode(['message' => 'Please enter a poll title.']);
                return;
            }

            $current = null;
            $responseCount = 0;
            if ($id) {
                $stmt = $db->prepare("SELECT * FROM camp_intranet_polls WHERE id = ? AND camp_id = ? LIMIT 1");
                $stmt->execute([(int)$id, $camp['id']]);
                $current = $stmt->fetch();
                if (!$current) {
                    http_response_code(404);
                    echo json_encode(['message' => 'Poll not found.']);
                    return;
                }

                $countStmt = $db->prepare("SELECT COUNT(*) FROM camp_intranet_poll_responses WHERE poll_id = ?");
                $countStmt->execute([(int)$id]);
                $responseCount = (int)$countStmt->fetchColumn();
            }

            if ((!$current || $options) && count($options) < 2) {
                http_response_code(422);
                echo json_encode(['message' => 'Please enter at least two options.']);
                return;
            }

            if ($responseCount > 0 && $options) {
                $existingOptionsStmt = $db->prepare("SELECT label FROM camp_intranet_poll_options WHERE poll_id = ? ORDER BY sort_order, id");
                $existingOptionsStmt->execute([(int)$id]);
                $existingOptions = array_values(array_map(function($row) {
                    return $this->collapseWhitespace($row['label'] ?? '');
                }, $existingOptionsStmt->fetchAll()));

                if ($existingOptions !== $options) {
                    http_response_code(422);
                    echo json_encode(['message' => 'This poll already has responses, so its options can no longer be changed.']);
                    return;
                }
            }

            $db->beginTransaction();
            try {
                if ($current) {
                    $stmt = $db->prepare("
                        UPDATE camp_intranet_polls
                        SET title = ?, description = ?, poll_type = ?, status = ?, show_results_public = ?, closes_at = ?
                        WHERE id = ? AND camp_id = ?
                    ");
                    $stmt->execute([
                        $title,
                        $description,
                        $pollType,
                        $status,
                        $showResultsPublic,
                        $closesAt,
                        (int)$id,
                        $camp['id']
                    ]);
                    $pollId = (int)$id;
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO camp_intranet_polls (camp_id, title, description, poll_type, status, show_results_public, closes_at, created_by_username)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $camp['id'],
                        $title,
                        $description,
                        $pollType,
                        $status,
                        $showResultsPublic,
                        $closesAt,
                        Auth::user()['username'] ?? null
                    ]);
                    $pollId = (int)$db->lastInsertId();
                }

                if ((!$current && $options) || ($current && $options && $responseCount === 0)) {
                    $db->prepare("DELETE FROM camp_intranet_poll_options WHERE poll_id = ?")->execute([$pollId]);
                    $insertOption = $db->prepare("INSERT INTO camp_intranet_poll_options (poll_id, label, sort_order) VALUES (?, ?, ?)");
                    foreach ($options as $index => $optionLabel) {
                        $insertOption->execute([$pollId, $optionLabel, $index + 1]);
                    }
                }

                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Server error', 'error' => $e->getMessage()]);
        }
    }

    public function adminDeleteMessage($id) {
        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorised']);
            return;
        }

        $this->sendJsonHeaders();
        try {
            $db = Database::connect();
            $this->ensureInteractionSchema($db);
            $camp = $this->requireActiveCamp($db);
            $stmt = $db->prepare("DELETE FROM camp_intranet_messages WHERE id = ? AND camp_id = ?");
            $stmt->execute([(int)$id, $camp['id']]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Server error', 'error' => $e->getMessage()]);
        }
    }

    public function adminDeleteLostFound($id) {
        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorised']);
            return;
        }

        $this->sendJsonHeaders();
        try {
            $db = Database::connect();
            $this->ensureInteractionSchema($db);
            $camp = $this->requireActiveCamp($db);
            $stmt = $db->prepare("DELETE FROM camp_intranet_lost_found WHERE id = ? AND camp_id = ?");
            $stmt->execute([(int)$id, $camp['id']]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Server error', 'error' => $e->getMessage()]);
        }
    }

    public function adminDeleteNoticeboard($id) {
        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorised']);
            return;
        }

        $this->sendJsonHeaders();
        try {
            $db = Database::connect();
            $this->ensureInteractionSchema($db);
            $camp = $this->requireActiveCamp($db);
            $stmt = $db->prepare("DELETE FROM camp_intranet_noticeboard WHERE id = ? AND camp_id = ?");
            $stmt->execute([(int)$id, $camp['id']]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Server error', 'error' => $e->getMessage()]);
        }
    }

    public function adminDeletePoll($id) {
        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorised']);
            return;
        }

        $this->sendJsonHeaders();
        try {
            $db = Database::connect();
            $this->ensureInteractionSchema($db);
            $camp = $this->requireActiveCamp($db);
            $stmt = $db->prepare("DELETE FROM camp_intranet_polls WHERE id = ? AND camp_id = ?");
            $stmt->execute([(int)$id, $camp['id']]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Server error', 'error' => $e->getMessage()]);
        }
    }
}
