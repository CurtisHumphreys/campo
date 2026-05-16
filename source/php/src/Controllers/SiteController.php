<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../SiteMapOccupantService.php';

class SiteController {
    private function refreshSiteStatus(PDO $db, $siteId) {
        $siteId = (int)$siteId;
        if ($siteId <= 0) {
            return;
        }

        $stmt = $db->prepare("SELECT COUNT(*) FROM site_allocations WHERE site_id = ? AND is_current = 1");
        $stmt->execute([$siteId]);
        $hasCurrentOccupants = (int)$stmt->fetchColumn() > 0;

        $update = $db->prepare("UPDATE sites SET status = ? WHERE id = ?");
        $update->execute([$hasCurrentOccupants ? 'Allocated' : 'Available', $siteId]);
    }

    private function assignMemberToSite(PDO $db, $memberId, $siteId) {
        $memberId = (int)$memberId;
        $siteId = (int)$siteId;
        if ($memberId <= 0 || $siteId <= 0) {
            return;
        }

        $currentStmt = $db->prepare("
            SELECT id, site_id
            FROM site_allocations
            WHERE member_id = ? AND is_current = 1
            ORDER BY id DESC
        ");
        $currentStmt->execute([$memberId]);
        $currentRows = $currentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $keepCurrent = false;
        foreach ($currentRows as $row) {
            $allocationId = (int)($row['id'] ?? 0);
            $currentSiteId = (int)($row['site_id'] ?? 0);
            if ($allocationId <= 0 || $currentSiteId <= 0) {
                continue;
            }

            if ($currentSiteId === $siteId && !$keepCurrent) {
                $keepCurrent = true;
                continue;
            }

            $clearStmt = $db->prepare("
                UPDATE site_allocations
                SET is_current = 0, end_date = COALESCE(end_date, CURDATE())
                WHERE id = ?
            ");
            $clearStmt->execute([$allocationId]);
            $this->refreshSiteStatus($db, $currentSiteId);
        }

        if (!$keepCurrent) {
            $insertStmt = $db->prepare("
                INSERT INTO site_allocations (site_id, member_id, start_date, is_current)
                VALUES (?, ?, CURDATE(), 1)
            ");
            $insertStmt->execute([$siteId, $memberId]);
        }

        $this->refreshSiteStatus($db, $siteId);
    }

    public function index() {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }

        try {
            $db = Database::connect();

            $sitesStmt = $db->query("SELECT * FROM sites");
            $sites = $sitesStmt ? $sitesStmt->fetchAll() : [];

            $occBySite = [];
            $mapOccBySite = [];
            try {
                $mapOccBySite = (new SiteMapOccupantService($db))->buildBySite();
                foreach ($mapOccBySite as $siteId => $payload) {
                    $occBySite[$siteId] = $payload['occupants_list'] ?? [];
                }
            } catch (Throwable $eOcc) {
                $occBySite = [];
                $mapOccBySite = [];
            }

            $memberIds = $this->currentMemberIdsFromOccupancy($occBySite);
            $feeByMember = $this->loadFeeAccountsByMember($db, $memberIds);
            $intervalsByMember = $this->loadStayIntervalsByMember($db, $memberIds);
            $today = new DateTimeImmutable('today', new DateTimeZone('Australia/Adelaide'));

            foreach ($sites as &$site) {
                $siteId = $site['id'] ?? null;
                $site['occupants_list'] = ($siteId !== null && isset($occBySite[$siteId])) ? $occBySite[$siteId] : [];
                $names = array_map(function ($occupant) {
                    return $occupant['name'];
                }, $site['occupants_list']);
                $site['occupants'] = implode(', ', $names);
                $site['map_occupants_list'] = ($siteId !== null && isset($mapOccBySite[$siteId]['map_occupants_list']))
                    ? $mapOccBySite[$siteId]['map_occupants_list']
                    : $site['occupants_list'];
                $site['map_occupants'] = ($siteId !== null && isset($mapOccBySite[$siteId]['map_occupants']))
                    ? $mapOccBySite[$siteId]['map_occupants']
                    : $site['occupants'];
                $siteIntervals = [];
                $earliestDue = null;

                foreach ($site['occupants_list'] as &$occupant) {
                    $memberId = (int)($occupant['id'] ?? 0);
                    $feeRow = $feeByMember[$memberId] ?? null;
                    $feeMeta = $this->describePaidUntil($feeRow['paid_until'] ?? null, $today);

                    $occupant['paid_until'] = $feeRow['paid_until'] ?? null;
                    $occupant['fee_state'] = $feeMeta['fee_state'];
                    $occupant['days_overdue'] = $feeMeta['days_overdue'];
                    $occupant['days_until_due'] = $feeMeta['days_until_due'];

                    foreach ($intervalsByMember[$memberId] ?? [] as $interval) {
                        $siteIntervals[] = $interval;
                    }

                    if (!empty($occupant['paid_until']) && ($earliestDue === null || strcmp($occupant['paid_until'], $earliestDue) < 0)) {
                        $earliestDue = $occupant['paid_until'];
                    }
                }
                unset($occupant);

                $site['total_nights'] = $this->totalNightsFromIntervals($siteIntervals);
                $site['next_due_date'] = $earliestDue;
                $site['fee_filter_state'] = $this->resolveSiteFeeFilterState($site['occupants_list']);
                $site['is_placeholder'] = false;
            }
            unset($site);

            $this->mergeMapPlaceholders($db, $sites);

            echo json_encode($sites);
        } catch (Throwable $e) {
            http_response_code(200);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to load sites',
                'debug' => $e->getMessage(),
            ]);
        }
    }

    public function store() {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        $db = Database::connect();
        $siteNumber = trim((string)($data['site_number'] ?? ''));
        $section = trim((string)($data['section'] ?? ''));
        $siteType = trim((string)($data['site_type'] ?? ''));
        $status = trim((string)($data['status'] ?? 'Available'));

        $stmt = $db->prepare("INSERT INTO sites (site_number, section, site_type, status) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $siteNumber,
            $section,
            $siteType,
            $status
        ]);

        $siteId = (int)$db->lastInsertId();
        $memberId = isset($data['member_id']) ? (int)$data['member_id'] : 0;
        if ($memberId > 0) {
            $this->assignMemberToSite($db, $memberId, $siteId);
            $status = 'Allocated';
        }

        $this->logSiteRevision(
            $db,
            $siteId,
            $siteNumber,
            'created',
            'Site created',
            json_encode([
                'section' => $section,
                'site_type' => $siteType,
                'status' => $status,
            ])
        );

        if ($memberId > 0) {
            $memberName = $this->memberNameById($db, $memberId);
            $this->logSiteRevision(
                $db,
                $siteId,
                $siteNumber,
                'allocated',
                sprintf('Allocated site %s to %s', $siteNumber !== '' ? $siteNumber : '#' . $siteId, $memberName),
                null
            );
        }

        echo json_encode(['success' => true, 'id' => $siteId]);
    }

    public function update($id) {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        $db = Database::connect();

        $existing = $this->findSiteById($db, (int)$id);
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Site not found']);
            return;
        }

        if (array_key_exists('map_x', $data) || array_key_exists('map_y', $data)) {
            $stmt = $db->prepare("UPDATE sites SET map_x = ?, map_y = ? WHERE id = ?");
            $mapX = array_key_exists('map_x', $data) ? $data['map_x'] : null;
            $mapY = array_key_exists('map_y', $data) ? $data['map_y'] : null;
            $stmt->execute([$mapX, $mapY, $id]);

            $this->logSiteRevision(
                $db,
                (int)$id,
                (string)($existing['site_number'] ?? ''),
                'map_updated',
                'Map pin updated',
                json_encode(['map_x' => $mapX, 'map_y' => $mapY])
            );
        } else {
            $siteNumber = trim((string)($data['site_number'] ?? ''));
            $section = trim((string)($data['section'] ?? ''));
            $siteType = trim((string)($data['site_type'] ?? ''));
            $status = trim((string)($data['status'] ?? 'Available'));

            $stmt = $db->prepare("UPDATE sites SET site_number = ?, section = ?, site_type = ?, status = ? WHERE id = ?");
            $stmt->execute([$siteNumber, $section, $siteType, $status, $id]);

            $changes = [];
            foreach ([
                'site_number' => $siteNumber,
                'section' => $section,
                'site_type' => $siteType,
                'status' => $status,
            ] as $field => $newValue) {
                $oldValue = (string)($existing[$field] ?? '');
                if ($oldValue !== (string)$newValue) {
                    $label = ucwords(str_replace('_', ' ', $field));
                    $changes[] = sprintf('%s: %s -> %s', $label, $oldValue === '' ? 'blank' : $oldValue, $newValue === '' ? 'blank' : $newValue);
                }
            }

            if ($changes) {
                $this->logSiteRevision(
                    $db,
                    (int)$id,
                    $siteNumber !== '' ? $siteNumber : (string)($existing['site_number'] ?? ''),
                    'updated',
                    'Site details updated',
                    implode('; ', $changes)
                );
            }
        }

        if (isset($data['member_id']) && !empty($data['member_id'])) {
            $memberId = (int)$data['member_id'];
            $wasAlreadyCurrent = false;
            $check = $db->prepare("SELECT id FROM site_allocations WHERE site_id = ? AND member_id = ? AND is_current = 1 LIMIT 1");
            $check->execute([$id, $memberId]);
            $wasAlreadyCurrent = (bool)$check->fetchColumn();

            $this->assignMemberToSite($db, $memberId, (int)$id);

            if (!$wasAlreadyCurrent) {
                $siteNumber = trim((string)($data['site_number'] ?? ($existing['site_number'] ?? '')));
                $memberName = $this->memberNameById($db, $memberId);
                $this->logSiteRevision(
                    $db,
                    (int)$id,
                    $siteNumber,
                    'allocated',
                    sprintf('Allocated site %s to %s', $siteNumber !== '' ? $siteNumber : '#' . $id, $memberName),
                    null
                );
            }
        }

        echo json_encode(['success' => true]);
    }

    public function allocate() {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        $db = Database::connect();

        $siteId = isset($data['site_id']) ? (int)$data['site_id'] : 0;
        $memberId = isset($data['member_id']) ? (int)$data['member_id'] : 0;

        if (!$siteId || !$memberId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing ID']);
            return;
        }

        $site = $this->findSiteById($db, $siteId);
        if (!$site) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Site not found']);
            return;
        }

        $check = $db->prepare("SELECT id FROM site_allocations WHERE site_id = ? AND member_id = ? AND is_current = 1 LIMIT 1");
        $check->execute([$siteId, $memberId]);
        $wasAlreadyCurrent = (bool)$check->fetchColumn();
        $this->assignMemberToSite($db, $memberId, $siteId);

        $siteNumber = trim((string)($site['site_number'] ?? ''));
        $memberName = $this->memberNameById($db, $memberId);
        if (!$wasAlreadyCurrent) {
            $this->logSiteRevision(
                $db,
                $siteId,
                $siteNumber,
                'allocated',
                sprintf('Allocated site %s to %s', $siteNumber !== '' ? $siteNumber : '#' . $siteId, $memberName),
                null
            );
        }

        echo json_encode(['success' => true]);
    }

    public function deallocate() {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        $db = Database::connect();

        $siteId = isset($data['site_id']) ? (int)$data['site_id'] : 0;
        $memberId = isset($data['member_id']) ? (int)$data['member_id'] : 0;

        if (!$siteId || !$memberId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing ID']);
            return;
        }

        $site = $this->findSiteById($db, $siteId);
        $siteNumber = trim((string)($site['site_number'] ?? ''));
        $memberName = $this->memberNameById($db, $memberId);

        $db->prepare("UPDATE site_allocations SET is_current = 0, end_date = NOW() WHERE site_id = ? AND member_id = ? AND is_current = 1")
            ->execute([$siteId, $memberId]);

        $this->refreshSiteStatus($db, $siteId);

        $this->logSiteRevision(
            $db,
            $siteId,
            $siteNumber,
            'deallocated',
            sprintf('Removed %s from site %s', $memberName, $siteNumber !== '' ? $siteNumber : '#' . $siteId),
            null
        );

        echo json_encode(['success' => true]);
    }

    public function saveMapPin() {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        $db = Database::connect();
        $this->ensureMapPlaceholdersTable($db);

        $siteId = isset($data['site_id']) ? (int)$data['site_id'] : 0;
        $siteNumber = trim((string)($data['site_number'] ?? ''));
        $mapX = array_key_exists('map_x', $data) ? $data['map_x'] : null;
        $mapY = array_key_exists('map_y', $data) ? $data['map_y'] : null;

        if ($siteId > 0) {
            $stmt = $db->prepare("UPDATE sites SET map_x = ?, map_y = ? WHERE id = ?");
            $stmt->execute([$mapX, $mapY, $siteId]);

            $site = $this->findSiteById($db, $siteId);
            $this->logSiteRevision(
                $db,
                $siteId,
                trim((string)($site['site_number'] ?? '')),
                'map_updated',
                'Map pin updated',
                json_encode(['map_x' => $mapX, 'map_y' => $mapY])
            );

            echo json_encode(['success' => true, 'kind' => 'site']);
            return;
        }

        if ($siteNumber === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing site number']);
            return;
        }

        $existingSite = $this->findSiteByNumber($db, $siteNumber);
        if ($existingSite) {
            $stmt = $db->prepare("UPDATE sites SET map_x = ?, map_y = ? WHERE id = ?");
            $stmt->execute([$mapX, $mapY, $existingSite['id']]);
            $db->prepare("DELETE FROM site_map_placeholders WHERE LOWER(site_number) = LOWER(?)")->execute([$siteNumber]);

            $this->logSiteRevision(
                $db,
                (int)$existingSite['id'],
                trim((string)$existingSite['site_number']),
                'map_updated',
                'Map pin updated',
                json_encode(['map_x' => $mapX, 'map_y' => $mapY])
            );

            echo json_encode(['success' => true, 'kind' => 'site', 'site_id' => $existingSite['id']]);
            return;
        }

        $stmt = $db->prepare(
            "INSERT INTO site_map_placeholders (site_number, map_x, map_y)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE map_x = VALUES(map_x), map_y = VALUES(map_y)"
        );
        $stmt->execute([$siteNumber, $mapX, $mapY]);

        echo json_encode(['success' => true, 'kind' => 'placeholder', 'site_number' => $siteNumber]);
    }

    public function deleteMapPin() {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        $db = Database::connect();
        $this->ensureMapPlaceholdersTable($db);

        $siteId = isset($data['site_id']) ? (int)$data['site_id'] : 0;
        $siteNumber = trim((string)($data['site_number'] ?? ''));
        $isPlaceholder = !empty($data['is_placeholder']);

        if ($siteId > 0 && !$isPlaceholder) {
            $site = $this->findSiteById($db, $siteId);
            $db->prepare("UPDATE sites SET map_x = NULL, map_y = NULL WHERE id = ?")->execute([$siteId]);
            $this->logSiteRevision(
                $db,
                $siteId,
                trim((string)($site['site_number'] ?? '')),
                'map_removed',
                'Map pin removed',
                null
            );
            echo json_encode(['success' => true]);
            return;
        }

        if ($siteNumber === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing site number']);
            return;
        }

        $db->prepare("DELETE FROM site_map_placeholders WHERE LOWER(site_number) = LOWER(?)")->execute([$siteNumber]);
        echo json_encode(['success' => true]);
    }

    public function bulkDeleteMapPins() {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        $db = Database::connect();
        $this->ensureMapPlaceholdersTable($db);

        $siteIds = array_values(array_filter(array_map('intval', $data['site_ids'] ?? [])));
        $placeholderNumbers = array_values(array_filter(array_map(function ($value) {
            return trim((string)$value);
        }, $data['placeholder_site_numbers'] ?? [])));

        if (!$siteIds && !$placeholderNumbers) {
            echo json_encode(['success' => true]);
            return;
        }

        if ($siteIds) {
            $marks = implode(',', array_fill(0, count($siteIds), '?'));
            $stmt = $db->prepare("UPDATE sites SET map_x = NULL, map_y = NULL WHERE id IN ($marks)");
            $stmt->execute($siteIds);
        }

        if ($placeholderNumbers) {
            $marks = implode(',', array_fill(0, count($placeholderNumbers), '?'));
            $stmt = $db->prepare("DELETE FROM site_map_placeholders WHERE site_number IN ($marks)");
            $stmt->execute($placeholderNumbers);
        }

        echo json_encode(['success' => true]);
    }

    public function revisions() {
        header('Content-Type: application/json');
        $db = Database::connect();
        $this->ensureSiteRevisionsTable($db);

        $stmt = $db->query(
            "SELECT id, site_id, site_number, action, summary, details, created_at
             FROM site_revisions
             ORDER BY created_at DESC, id DESC
             LIMIT 500"
        );

        echo json_encode($stmt ? $stmt->fetchAll() : []);
    }

    public function allocations() {
        header('Content-Type: application/json');
        $db = Database::connect();

        $stmt = $db->query(
            "SELECT
                sa.id,
                sa.site_id,
                sa.member_id,
                sa.start_date,
                sa.end_date,
                sa.is_current,
                sa.created_at,
                s.site_number,
                m.first_name,
                m.last_name
             FROM site_allocations sa
             JOIN sites s ON s.id = sa.site_id
             JOIN members m ON m.id = sa.member_id
             ORDER BY COALESCE(sa.end_date, sa.start_date) DESC, sa.id DESC
             LIMIT 300"
        );

        $rows = $stmt ? $stmt->fetchAll() : [];
        foreach ($rows as &$row) {
            $row['member_name'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        }
        unset($row);

        echo json_encode($rows);
    }

    public function waitlist() {
        header('Content-Type: application/json');
        try {
            $db = Database::connect();
            $supportsPriorityOverride = $this->ensureWaitlistEnhancements($db);

            $rows = $this->fetchWaitlistRows($db);
            $decorated = $this->decorateWaitlistRows($rows);
            $this->syncWaitlistPriorities($db, $decorated, $supportsPriorityOverride);

            echo json_encode($decorated);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Unable to load waitlist submissions',
                'debug' => $e->getMessage()
            ]);
        }
    }

    public function storeWaitlist() {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        echo json_encode($this->insertWaitlistData($data));
    }

    private function insertWaitlistData(array $data) {
        $db = Database::connect();
        $supportsPriorityOverride = $this->ensureWaitlistEnhancements($db);

        $stmt = $db->prepare(
            "INSERT INTO waitlist (
                first_name, last_name, phone, site_type, adults, kids,
                special_considerations, intended_days, home_assembly,
                overflow_willing, subscription_willing, additional_comments, priority
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            trim((string)($data['first_name'] ?? '')),
            trim((string)($data['last_name'] ?? '')),
            trim((string)($data['phone'] ?? '')),
            trim((string)($data['site_type'] ?? 'Powered')),
            (int)($data['adults'] ?? 1),
            (int)($data['kids'] ?? 0),
            trim((string)($data['special_considerations'] ?? '')),
            trim((string)($data['intended_days'] ?? '')),
            trim((string)($data['home_assembly'] ?? '')),
            trim((string)($data['overflow_willing'] ?? 'No')),
            trim((string)($data['subscription_willing'] ?? 'No')),
            trim((string)($data['additional_comments'] ?? '')),
            'Low'
        ]);

        $waitlistId = (int)$db->lastInsertId();
        $rows = $this->fetchWaitlistRows($db);
        $decorated = $this->decorateWaitlistRows($rows);
        $this->syncWaitlistPriorities($db, $decorated, $supportsPriorityOverride);

        $item = null;
        foreach ($decorated as $row) {
            if ((int)$row['id'] === $waitlistId) {
                $item = $row;
                break;
            }
        }

        return ['success' => true, 'id' => $waitlistId, 'item' => $item];
    }

    public function updateWaitlist() {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing ID']);
            return;
        }

        $db = Database::connect();
        $supportsPriorityOverride = $this->ensureWaitlistEnhancements($db);

        $existing = $this->fetchWaitlistRow($db, $id);
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['error' => 'Waitlist item not found']);
            return;
        }

        $fields = [];
        $params = [];

        $simpleFields = [
            'first_name',
            'last_name',
            'phone',
            'site_type',
            'special_considerations',
            'intended_days',
            'home_assembly',
            'overflow_willing',
            'subscription_willing',
            'additional_comments',
            'status',
        ];

        foreach ($simpleFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $params[] = trim((string)$data[$field]);
            }
        }

        foreach (['adults', 'kids'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $params[] = (int)$data[$field];
            }
        }

        if (array_key_exists('created_at', $data)) {
            $normalisedCreatedAt = $this->normaliseDateTimeInput($data['created_at']);
            if ($normalisedCreatedAt) {
                $fields[] = "created_at = ?";
                $params[] = $normalisedCreatedAt;
            }
        }

        $prioritySelection = null;
        if (array_key_exists('priority_override', $data)) {
            $prioritySelection = $data['priority_override'];
        } elseif (array_key_exists('priority', $data)) {
            $prioritySelection = $data['priority'];
        }

        if ($prioritySelection !== null) {
            $override = $this->normalisePriorityChoice($prioritySelection);
            if ($supportsPriorityOverride) {
                $fields[] = "priority_override = ?";
                $params[] = $override !== '' ? $override : null;
            }
            if ($override !== '' || !$supportsPriorityOverride) {
                $fields[] = "priority = ?";
                $params[] = $override !== '' ? $override : ($existing['priority'] ?? 'Low');
            }
        }

        if (!$fields) {
            echo json_encode(['success' => true]);
            return;
        }

        $params[] = $id;
        $stmt = $db->prepare("UPDATE waitlist SET " . implode(', ', $fields) . " WHERE id = ?");
        $stmt->execute($params);

        $rows = $this->fetchWaitlistRows($db);
        $decorated = $this->decorateWaitlistRows($rows);
        $this->syncWaitlistPriorities($db, $decorated, $supportsPriorityOverride);

        $item = null;
        foreach ($decorated as $row) {
            if ((int)$row['id'] === $id) {
                $item = $row;
                break;
            }
        }

        echo json_encode(['success' => true, 'item' => $item]);
    }

    public function deleteWaitlist() {
        header('Content-Type: application/json');
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing waitlist ID']);
            return;
        }

        $db = Database::connect();
        $db->prepare("DELETE FROM waitlist WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
    }

    public function storePublicWaitlist() {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowed = ['https://campo.urbantek.online', 'http://campo.urbantek.online', 'https://campo.nix.local', 'http://campo.nix.local'];
        if (in_array($origin, $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }
        header('Content-Type: application/json');
        // campo posts application/x-www-form-urlencoded — read $_POST, not php://input
        echo json_encode($this->insertWaitlistData($_POST ?: []));
    }

    private function ensureMapPlaceholdersTable($db) {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS site_map_placeholders (
                site_number VARCHAR(20) NOT NULL PRIMARY KEY,
                map_x DECIMAL(5,2) NULL,
                map_y DECIMAL(5,2) NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function ensureSiteRevisionsTable($db) {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS site_revisions (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id INT NULL,
                site_number VARCHAR(50) NULL,
                action VARCHAR(50) NOT NULL,
                summary VARCHAR(255) NOT NULL,
                details TEXT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_site_revisions_created_at (created_at),
                INDEX idx_site_revisions_site_id (site_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function ensureWaitlistEnhancements($db) {
        if ($this->columnExists($db, 'waitlist', 'priority_override')) {
            return true;
        }
        try {
            $db->exec("ALTER TABLE waitlist ADD COLUMN priority_override VARCHAR(20) NULL DEFAULT NULL AFTER priority");
        } catch (Throwable $e) {
            return $this->columnExists($db, 'waitlist', 'priority_override');
        }
        return $this->columnExists($db, 'waitlist', 'priority_override');
    }

    private function columnExists($db, $table, $column) {
        $stmt = $db->prepare(
            "SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1"
        );
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetch();
    }

    private function findSiteById($db, $siteId) {
        $stmt = $db->prepare("SELECT * FROM sites WHERE id = ? LIMIT 1");
        $stmt->execute([$siteId]);
        return $stmt->fetch();
    }

    private function findSiteByNumber($db, $siteNumber) {
        $stmt = $db->prepare("SELECT id, site_number, map_x, map_y FROM sites WHERE LOWER(site_number) = LOWER(?) LIMIT 1");
        $stmt->execute([$siteNumber]);
        return $stmt->fetch();
    }

    private function memberNameById($db, $memberId) {
        $stmt = $db->prepare("SELECT first_name, last_name FROM members WHERE id = ? LIMIT 1");
        $stmt->execute([$memberId]);
        $member = $stmt->fetch();
        if (!$member) {
            return 'Member #' . $memberId;
        }
        return trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
    }

    private function mergeMapPlaceholders($db, &$sites) {
        $this->ensureMapPlaceholdersTable($db);

        $placeholderStmt = $db->query("SELECT site_number, map_x, map_y FROM site_map_placeholders");
        $placeholders = $placeholderStmt ? $placeholderStmt->fetchAll() : [];
        if (!$placeholders) {
            return;
        }

        $sitesByNumber = [];
        foreach ($sites as &$site) {
            $numberKey = strtolower(trim((string)($site['site_number'] ?? '')));
            if ($numberKey !== '') {
                $sitesByNumber[$numberKey] = &$site;
            }
        }
        unset($site);

        $matchedPlaceholderNumbers = [];

        foreach ($placeholders as $placeholder) {
            $siteNumber = trim((string)($placeholder['site_number'] ?? ''));
            if ($siteNumber === '') {
                continue;
            }

            $key = strtolower($siteNumber);
            if (isset($sitesByNumber[$key])) {
                $matchedPlaceholderNumbers[] = $siteNumber;
                $site = &$sitesByNumber[$key];
                $siteHasCoords = isset($site['map_x'], $site['map_y']) && $site['map_x'] !== null && $site['map_y'] !== null;

                if (!$siteHasCoords && $placeholder['map_x'] !== null && $placeholder['map_y'] !== null) {
                    $site['map_x'] = $placeholder['map_x'];
                    $site['map_y'] = $placeholder['map_y'];
                    $db->prepare("UPDATE sites SET map_x = ?, map_y = ? WHERE id = ?")
                        ->execute([$placeholder['map_x'], $placeholder['map_y'], $site['id']]);
                }
                unset($site);
                continue;
            }

            $sites[] = [
                'id' => 'placeholder:' . $siteNumber,
                'site_number' => $siteNumber,
                'section' => '',
                'site_type' => '',
                'status' => 'Unallocated',
                'map_x' => $placeholder['map_x'],
                'map_y' => $placeholder['map_y'],
                'occupants_list' => [],
                'occupants' => '',
                'map_occupants_list' => [],
                'map_occupants' => '',
                'is_placeholder' => true
            ];
        }

        if ($matchedPlaceholderNumbers) {
            $marks = implode(',', array_fill(0, count($matchedPlaceholderNumbers), '?'));
            $stmt = $db->prepare("DELETE FROM site_map_placeholders WHERE site_number IN ($marks)");
            $stmt->execute($matchedPlaceholderNumbers);
        }
    }

    private function logSiteRevision($db, $siteId, $siteNumber, $action, $summary, $details = null) {
        $this->ensureSiteRevisionsTable($db);
        $stmt = $db->prepare(
            "INSERT INTO site_revisions (site_id, site_number, action, summary, details)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $siteId ?: null,
            $siteNumber !== '' ? $siteNumber : null,
            $action,
            $summary,
            $details
        ]);
    }

    private function currentMemberIdsFromOccupancy($occBySite) {
        $memberIds = [];
        foreach ($occBySite as $occupants) {
            foreach ($occupants as $occupant) {
                $memberId = isset($occupant['id']) ? (int)$occupant['id'] : 0;
                if ($memberId > 0) {
                    $memberIds[$memberId] = $memberId;
                }
            }
        }
        return array_values($memberIds);
    }

    private function loadFeeAccountsByMember($db, $memberIds) {
        if (!$memberIds) {
            return [];
        }

        $marks = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $db->prepare(
            "SELECT member_id, paid_until, status, created_at
             FROM site_fee_accounts
             WHERE member_id IN ($marks)
             ORDER BY member_id, paid_until DESC, created_at DESC"
        );
        $stmt->execute($memberIds);

        $rows = $stmt->fetchAll();
        $byMember = [];
        foreach ($rows as $row) {
            $memberId = (int)($row['member_id'] ?? 0);
            if ($memberId <= 0 || isset($byMember[$memberId])) {
                continue;
            }
            $byMember[$memberId] = $row;
        }
        return $byMember;
    }

    private function loadStayIntervalsByMember($db, $memberIds) {
        if (!$memberIds) {
            return [];
        }

        $marks = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $db->prepare(
            "SELECT member_id, arrival_date, departure_date
             FROM payments
             WHERE member_id IN ($marks)
               AND arrival_date IS NOT NULL
               AND departure_date IS NOT NULL
               AND arrival_date <> '0000-00-00'
               AND departure_date <> '0000-00-00'"
        );
        $stmt->execute($memberIds);

        $rows = $stmt->fetchAll();
        $intervalsByMember = [];
        foreach ($rows as $row) {
            $memberId = (int)($row['member_id'] ?? 0);
            if ($memberId <= 0) {
                continue;
            }

            $start = (string)($row['arrival_date'] ?? '');
            $end = (string)($row['departure_date'] ?? '');
            if ($start === '' || $end === '' || strcmp($end, $start) <= 0) {
                continue;
            }

            if (!isset($intervalsByMember[$memberId])) {
                $intervalsByMember[$memberId] = [];
            }

            $intervalsByMember[$memberId][] = [
                'start' => $start,
                'end' => $end
            ];
        }
        return $intervalsByMember;
    }

    private function describePaidUntil($paidUntil, DateTimeImmutable $today) {
        if (empty($paidUntil)) {
            return [
                'fee_state' => 'unknown',
                'days_overdue' => 0,
                'days_until_due' => null
            ];
        }

        try {
            $dueDate = new DateTimeImmutable((string)$paidUntil, $today->getTimezone());
        } catch (Throwable $e) {
            return [
                'fee_state' => 'unknown',
                'days_overdue' => 0,
                'days_until_due' => null
            ];
        }

        $daysDifference = (int)$today->diff($dueDate)->format('%r%a');
        if ($daysDifference >= 0) {
            return [
                'fee_state' => 'current',
                'days_overdue' => 0,
                'days_until_due' => $daysDifference
            ];
        }

        $daysOverdue = abs($daysDifference);
        return [
            'fee_state' => $daysOverdue >= 183 ? 'flagged' : 'overdue',
            'days_overdue' => $daysOverdue,
            'days_until_due' => 0
        ];
    }

    private function resolveSiteFeeFilterState($occupants) {
        if (!$occupants) {
            return 'none';
        }

        $hasCurrent = false;
        $hasUnknown = false;
        foreach ($occupants as $occupant) {
            $state = strtolower((string)($occupant['fee_state'] ?? 'unknown'));
            if ($state === 'flagged') {
                return 'flagged';
            }
            if ($state === 'overdue') {
                return 'overdue';
            }
            if ($state === 'unknown') {
                $hasUnknown = true;
                continue;
            }
            if ($state === 'current') {
                $hasCurrent = true;
                continue;
            }
        }

        if ($hasUnknown) {
            return 'unknown';
        }
        if ($hasCurrent) {
            return 'current';
        }
        return 'none';
    }

    private function totalNightsFromIntervals($intervals) {
        if (!$intervals) {
            return 0;
        }

        usort($intervals, function ($a, $b) {
            return strcmp((string)($a['start'] ?? ''), (string)($b['start'] ?? ''));
        });

        $total = 0;
        $currentStart = null;
        $currentEnd = null;

        foreach ($intervals as $interval) {
            $startTs = strtotime((string)($interval['start'] ?? ''));
            $endTs = strtotime((string)($interval['end'] ?? ''));
            if (!$startTs || !$endTs || $endTs <= $startTs) {
                continue;
            }

            if ($currentStart === null) {
                $currentStart = $startTs;
                $currentEnd = $endTs;
                continue;
            }

            if ($startTs <= $currentEnd) {
                $currentEnd = max($currentEnd, $endTs);
                continue;
            }

            $total += (int)round(($currentEnd - $currentStart) / 86400);
            $currentStart = $startTs;
            $currentEnd = $endTs;
        }

        if ($currentStart !== null && $currentEnd !== null) {
            $total += (int)round(($currentEnd - $currentStart) / 86400);
        }

        return max(0, $total);
    }

    private function fetchWaitlistRows($db) {
        $stmt = $db->query("SELECT * FROM waitlist ORDER BY created_at DESC, id DESC");
        return $stmt ? $stmt->fetchAll() : [];
    }

    private function fetchWaitlistRow($db, $id) {
        $stmt = $db->prepare("SELECT * FROM waitlist WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    private function decorateWaitlistRows($rows) {
        if (!$rows) {
            return [];
        }

        $scores = [];
        foreach ($rows as $row) {
            $scores[] = $this->calculateWaitlistScore($row);
        }
        $trimmedMean = $this->trimmedMean($scores);

        $decorated = [];
        foreach ($rows as $index => $row) {
            $score = $scores[$index];
            $daysWaiting = $this->daysWaiting($row['created_at'] ?? null);
            $autoPriority = $this->priorityFromScore($score, $trimmedMean);
            $manualPriority = $this->normalisePriorityChoice($row['priority_override'] ?? '');
            $effectivePriority = $manualPriority !== '' ? $manualPriority : $autoPriority;

            $row['score'] = $score;
            $row['days_waiting'] = $daysWaiting;
            $row['auto_priority'] = $autoPriority;
            $row['priority_override'] = $manualPriority !== '' ? $manualPriority : null;
            $row['priority'] = $effectivePriority;
            $row['priority_source'] = $manualPriority !== '' ? 'Manual' : 'Auto';
            $decorated[] = $row;
        }

        return $decorated;
    }

    private function syncWaitlistPriorities($db, $rows, $supportsPriorityOverride = true) {
        $stmt = $supportsPriorityOverride
            ? $db->prepare("UPDATE waitlist SET priority = ?, priority_override = ? WHERE id = ?")
            : $db->prepare("UPDATE waitlist SET priority = ? WHERE id = ?");
        foreach ($rows as $row) {
            if ($supportsPriorityOverride) {
                $stmt->execute([
                    $row['priority'],
                    $row['priority_override'],
                    $row['id']
                ]);
            } else {
                $stmt->execute([
                    $row['priority'],
                    $row['id']
                ]);
            }
        }
    }

    private function calculateWaitlistScore($row) {
        $adults = max(0, (int)($row['adults'] ?? 0));
        $kids = max(0, (int)($row['kids'] ?? 0));
        $daysWaiting = $this->daysWaiting($row['created_at'] ?? null);
        $intendedDays = $this->extractIntendedDays($row['intended_days'] ?? '');
        $overflowWilling = strtolower(trim((string)($row['overflow_willing'] ?? ''))) === 'yes';
        $subscription = strtolower(trim((string)($row['subscription_willing'] ?? '')));
        $hasSpecial = trim((string)($row['special_considerations'] ?? '')) !== '';

        $score = $adults + $kids;
        $score += (int)floor($daysWaiting / 60);
        $score += (int)floor($intendedDays / 7);

        if ($overflowWilling) {
            $score += 2;
        }

        if ($hasSpecial) {
            $score += 1;
        }

        switch ($subscription) {
            case 'annually':
                $score += 3;
                break;
            case 'quarterly':
                $score += 2;
                break;
            case 'monthly':
                $score += 1;
                break;
        }

        return max(0, $score);
    }

    private function extractIntendedDays($value) {
        if (is_numeric($value)) {
            return (int)$value;
        }
        if (preg_match('/\d+/', (string)$value, $match)) {
            return (int)$match[0];
        }
        return 0;
    }

    private function daysWaiting($createdAt) {
        if (!$createdAt) {
            return 0;
        }
        $timestamp = strtotime((string)$createdAt);
        if ($timestamp === false) {
            return 0;
        }
        $today = strtotime(date('Y-m-d'));
        $createdDay = strtotime(date('Y-m-d', $timestamp));
        return max(0, (int)floor(($today - $createdDay) / 86400));
    }

    private function trimmedMean($scores) {
        $scores = array_values(array_map('floatval', $scores));
        $count = count($scores);
        if ($count === 0) {
            return 0;
        }
        sort($scores);
        if ($count > 2) {
            array_shift($scores);
            array_pop($scores);
        }
        $trimmedCount = count($scores);
        return $trimmedCount > 0 ? array_sum($scores) / $trimmedCount : 0;
    }

    private function priorityFromScore($score, $trimmedMean) {
        $score = (float)$score;
        if ($score > 15) {
            return 'Critical';
        }

        $lowUpper = max(9, (int)floor($trimmedMean - 1));
        $highLower = max($lowUpper + 2, (int)ceil($trimmedMean + 2));

        if ($score <= $lowUpper) {
            return 'Low';
        }
        if ($score >= $highLower) {
            return 'High';
        }
        return 'Medium';
    }

    private function normalisePriorityChoice($value) {
        $value = strtolower(trim((string)$value));
        $map = [
            'auto' => '',
            '' => '',
            'low' => 'Low',
            'medium' => 'Medium',
            'high' => 'High',
            'critical' => 'Critical',
        ];
        return $map[$value] ?? '';
    }

    private function normaliseDateTimeInput($value) {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value . ' 00:00:00';
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }
}
