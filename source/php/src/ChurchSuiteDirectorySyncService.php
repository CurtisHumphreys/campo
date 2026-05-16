<?php

require_once __DIR__ . '/ChurchSuiteClientInterface.php';
require_once __DIR__ . '/MemberMatchingService.php';
require_once __DIR__ . '/MemberHouseholdService.php';

class ChurchSuiteDirectorySyncService {
    private $db;
    private $client;
    private $syncLockKey = null;

    private const SYNC_CHUNK_SIZE = 100;
    private const SYNC_STATE_TTL = 1800;

    public function __construct(PDO $db, ChurchSuiteClientInterface $client) {
        $this->db = $db;
        $this->client = $client;
    }

    public function sync($syncToken = null) {
        $this->assertSchemaReady();
        $this->acquireSyncLock();

        try {
            $state = $this->loadSyncState($syncToken);
            if (!$state) {
                $state = $this->createSyncState();
            }
            if (!isset($state['relationship_links']) || !is_array($state['relationship_links'])) {
                $state['relationship_links'] = [];
            }

            $stage = $state['stage'];
            $page = (int)$state['page'];
            $perPage = (int)$state['per_page'];
            $syncStamp = $state['sync_started_at'];
            $summary = $state['summary'];

            if ($stage === 'relationships') {
                $response = $this->client->listParentCarerRelationships($page, $perPage);
                $records = $response['relationships'] ?? [];
                $processedThisPage = $this->captureRelationshipLinks($state, $records);
            } else {
                $matcher = new MemberMatchingService($this->db);
                $indexes = $matcher->buildIndexes();

                if ($stage === 'contacts') {
                    $response = $this->client->listContacts($page, $perPage);
                    $records = $response['contacts'] ?? [];
                    $personType = 'contact';
                } else {
                    $response = $this->client->listChildren($page, $perPage);
                    $records = $response['children'] ?? [];
                    $personType = 'child';
                }

                $processedThisPage = 0;

                $this->db->beginTransaction();
                foreach ($records as $record) {
                    $prepared = $this->preparePerson($record, $personType);
                    if ($prepared['churchsuite_person_id'] === '') {
                        $summary['skipped']++;
                        continue;
                    }

                    $result = $this->upsertPerson($prepared, $indexes, $matcher, $syncStamp);
                    $summary[$result['bucket']]++;
                    $processedThisPage++;
                }
                $this->db->commit();
            }

            $state['summary'] = $summary;
            $state['processed'] += $processedThisPage;
            $state['updated_at'] = date('Y-m-d H:i:s');

            $pagination = $response['pagination'] ?? [];
            $hasMore = $this->hasMorePages($pagination, count($records), $perPage);
            if ($hasMore) {
                $state['page'] = !empty($pagination['next_page']) ? (int)$pagination['next_page'] : ($page + 1);
                $this->saveSyncState($state);

                return [
                    'success' => true,
                    'in_progress' => true,
                    'sync_token' => $state['token'],
                    'summary' => $summary,
                    'stage' => $stage,
                    'processed' => $state['processed']
                ];
            }

            if ($stage === 'contacts') {
                $state['stage'] = 'children';
                $state['page'] = 1;
                $this->saveSyncState($state);

                return [
                    'success' => true,
                    'in_progress' => true,
                    'sync_token' => $state['token'],
                    'summary' => $summary,
                    'stage' => 'children',
                    'processed' => $state['processed']
                ];
            }

            if ($stage === 'children') {
                $state['stage'] = 'relationships';
                $state['page'] = 1;
                $this->saveSyncState($state);

                return [
                    'success' => true,
                    'in_progress' => true,
                    'sync_token' => $state['token'],
                    'summary' => $summary,
                    'stage' => 'relationships',
                    'processed' => $state['processed']
                ];
            }

            $this->finalizeDirectorySync($syncStamp, array_values($state['relationship_links']));
            $this->deleteSyncState();

            return [
                'success' => true,
                'in_progress' => false,
                'summary' => $summary,
                'last_sync_at' => $syncStamp
            ];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        } finally {
            $this->releaseSyncLock();
        }
    }

    private function upsertPerson(array $prepared, array &$indexes, MemberMatchingService $matcher, $syncStamp) {
        $match = $matcher->matchPerson($prepared, $indexes);
        $note = null;

        if ($match['status'] === 'matched' && !empty($match['member'])) {
            $this->updateMember((int)$match['member']['id'], $prepared, 'ok', null, $syncStamp);
            $this->refreshIndexes($indexes, (int)$match['member']['id']);
            return ['bucket' => 'updated'];
        }

        if ($match['status'] === 'review') {
            $note = $this->reviewNote($match['source'], $match['candidates']);
            $newId = $this->insertMember($prepared, 'review', $note, $syncStamp);
            $this->refreshIndexes($indexes, $newId);
            return ['bucket' => 'reviewed'];
        }

        $newId = $this->insertMember($prepared, 'ok', null, $syncStamp);
        $this->refreshIndexes($indexes, $newId);
        return ['bucket' => 'created'];
    }

    private function preparePerson(array $record, $personType) {
        $firstName = trim((string)($record['first_name'] ?? ($record['name']['first_name'] ?? '')));
        $lastName = trim((string)($record['last_name'] ?? ($record['name']['last_name'] ?? '')));
        $email = $this->extractFirstString($record, [
            ['email'],
            ['email_address'],
            ['emails', 0, 'email'],
            ['emails', 0, 'address'],
            ['contact_details', 'email']
        ]);
        $mobile = $this->extractFirstString($record, [
            ['mobile'],
            ['cell'],
            ['mobile_phone'],
            ['contact_details', 'mobile']
        ]);
        $phone = $this->extractFirstString($record, [
            ['phone'],
            ['telephone'],
            ['landline'],
            ['contact_details', 'telephone']
        ]);

        $personId = trim((string)($record['id'] ?? ($record[$personType . '_id'] ?? '')));
        $digitalAgreementConfirmed = $this->extractDigitalAgreementFlag($record);

        $sexRaw = strtolower(trim((string)($record['sex'] ?? ($record['gender'] ?? ''))));
        $gender = match($sexRaw) {
            'male', 'm'   => 'male',
            'female', 'f' => 'female',
            default       => ''
        };

        $memberType = $personType === 'child' ? 'child' : 'adult';

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'mobile' => $mobile,
            'phone' => $phone,
            'gender' => $gender,
            'member_type' => $memberType,
            'churchsuite_person_type' => $personType,
            'churchsuite_person_id' => $personId,
            'churchsuite_payload_json' => json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'digital_agreement_confirmed' => $digitalAgreementConfirmed ? 1 : 0
        ];
    }

    private function extractDigitalAgreementFlag(array $record) {
        $fieldKey = trim((string)(defined('CHURCHSUITE_DIGITAL_AGREEMENT_FIELD_KEY') ? CHURCHSUITE_DIGITAL_AGREEMENT_FIELD_KEY : ''));
        if ($fieldKey === '') {
            return false;
        }

        $candidates = $record['custom_fields'] ?? ($record['fields'] ?? []);
        if (!is_array($candidates)) {
            return false;
        }

        foreach ($candidates as $key => $value) {
            if ((string)$key === $fieldKey) {
                return $this->truthyValue($value);
            }
            if (is_array($value)) {
                $candidateKey = trim((string)($value['slug'] ?? $value['key'] ?? $value['name'] ?? ''));
                if ($candidateKey === $fieldKey) {
                    return $this->truthyValue($value['value'] ?? ($value['formatted_value'] ?? null));
                }
            }
        }

        return false;
    }

    private function truthyValue($value) {
        if (is_bool($value)) {
            return $value;
        }
        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 'yes', 'signed', 'complete', 'completed'], true);
    }

    private function reviewNote($source, array $candidates) {
        $names = array_map(function ($row) {
            return trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? '')) . ' (ID ' . (int)$row['id'] . ')';
        }, $candidates);
        $names = array_filter(array_map('trim', $names));
        if (!$names) {
            return 'ChurchSuite sync could not uniquely match this person.';
        }
        return 'ChurchSuite sync found multiple possible matches via ' . $source . ': ' . implode(', ', $names);
    }

    private function insertMember(array $prepared, $syncStatus, $syncNote, $syncStamp) {
        $stmt = $this->db->prepare("
            INSERT INTO members (
                first_name,
                last_name,
                email,
                mobile,
                phone,
                gender,
                member_type,
                fellowship,
                concession,
                site_fee_status,
                churchsuite_person_type,
                churchsuite_person_id,
                churchsuite_sync_status,
                churchsuite_sync_note,
                churchsuite_last_synced_at,
                churchsuite_payload_json,
                digital_agreement_confirmed
            ) VALUES (?, ?, ?, ?, ?, ?, ?, '', 'No', 'Unknown', ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $prepared['first_name'],
            $prepared['last_name'],
            $prepared['email'],
            $prepared['mobile'],
            $prepared['phone'],
            $prepared['gender'] ?? '',
            $prepared['member_type'] ?? 'Adult',
            $prepared['churchsuite_person_type'],
            $prepared['churchsuite_person_id'],
            $syncStatus,
            $syncNote,
            $syncStamp,
            $prepared['churchsuite_payload_json'],
            (int)($prepared['digital_agreement_confirmed'] ?? 0)
        ]);
        return (int)$this->db->lastInsertId();
    }

    private function updateMember($memberId, array $prepared, $syncStatus, $syncNote, $syncStamp) {
        $stmt = $this->db->prepare("
            UPDATE members
            SET
                first_name = ?,
                last_name = ?,
                email = ?,
                mobile = ?,
                phone = ?,
                gender = ?,
                member_type = ?,
                churchsuite_person_type = ?,
                churchsuite_person_id = ?,
                churchsuite_sync_status = ?,
                churchsuite_sync_note = ?,
                churchsuite_last_synced_at = ?,
                churchsuite_payload_json = ?,
                digital_agreement_confirmed = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $prepared['first_name'],
            $prepared['last_name'],
            $prepared['email'],
            $prepared['mobile'],
            $prepared['phone'],
            $prepared['gender'] ?? '',
            $prepared['member_type'] ?? 'Adult',
            $prepared['churchsuite_person_type'],
            $prepared['churchsuite_person_id'],
            $syncStatus,
            $syncNote,
            $syncStamp,
            $prepared['churchsuite_payload_json'],
            (int)($prepared['digital_agreement_confirmed'] ?? 0),
            (int)$memberId
        ]);
    }

    private function refreshIndexes(array &$indexes, $memberId) {
        $stmt = $this->db->prepare("
            SELECT
                id,
                first_name,
                last_name,
                email,
                mobile,
                phone,
                churchsuite_person_type,
                churchsuite_person_id,
                churchsuite_sync_status
            FROM members
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([(int)$memberId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }

        $row['external_key'] = MemberMatchingService::churchsuiteExternalKey($row['churchsuite_person_type'] ?? '', $row['churchsuite_person_id'] ?? '');
        $row['email_normalized'] = MemberMatchingService::normalizeEmail($row['email'] ?? '');
        $row['mobile_normalized'] = MemberMatchingService::normalizePhone($row['mobile'] ?? ($row['phone'] ?? ''));
        $row['name_key'] = strtolower(trim((string)($row['first_name'] ?? ''))) . '|' . strtolower(trim((string)($row['last_name'] ?? '')));

        $indexes['rows'][(int)$row['id']] = $row;
        if ($row['external_key'] !== '') {
            $indexes['external'][$row['external_key']] = $row;
        }
        if ($row['email_normalized'] !== '') {
            $indexes['email'][$row['email_normalized']][] = $row;
        }
        if ($row['mobile_normalized'] !== '') {
            $indexes['mobile'][$row['mobile_normalized']][] = $row;
        }
        if ($row['name_key'] !== '|') {
            $indexes['name'][$row['name_key']][] = $row;
        }
    }

    private function finalizeDirectorySync($syncStamp, array $relationshipLinks = []) {
        $householdService = new MemberHouseholdService($this->db);
        $householdService->rebuildChurchSuiteStructures($relationshipLinks);

        $markStale = $this->db->prepare("
            UPDATE members
            SET churchsuite_sync_status = 'stale'
            WHERE churchsuite_person_id IS NOT NULL
              AND churchsuite_person_id <> ''
              AND (churchsuite_last_synced_at IS NULL OR churchsuite_last_synced_at <> ?)
        ");
        $markStale->execute([$syncStamp]);

        $digitalStmt = $this->db->prepare("
            SELECT DISTINCT mhm.household_id
            FROM member_household_members mhm
            JOIN members m ON m.id = mhm.member_id
            WHERE mhm.source_system = 'churchsuite'
              AND m.churchsuite_last_synced_at = ?
        ");
        $digitalStmt->execute([$syncStamp]);
        $householdIds = $digitalStmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($householdIds as $householdId) {
            $this->syncHouseholdDigitalAgreement((int)$householdId);
            $householdService->recalculateAgreementStatus((int)$householdId);
        }
    }

    private function syncHouseholdDigitalAgreement($householdId) {
        $stmt = $this->db->prepare("
            SELECT MAX(CASE WHEN digital_agreement_confirmed = 1 THEN 1 ELSE 0 END) AS has_digital
            FROM member_household_members mhm
            JOIN members m ON m.id = mhm.member_id
            WHERE mhm.household_id = ?
        ");
        $stmt->execute([(int)$householdId]);
        $hasDigital = (int)$stmt->fetchColumn() === 1;

        $update = $this->db->prepare("
            UPDATE member_households
            SET digital_agreement_confirmed = ?, digital_agreement_synced_at = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $update->execute([
            $hasDigital ? 1 : 0,
            $hasDigital ? date('Y-m-d H:i:s') : null,
            (int)$householdId
        ]);
    }

    private function hasMorePages(array $pagination, $returnedCount, $perPage) {
        if (!empty($pagination['next_page'])) {
            return true;
        }

        $numResults = isset($pagination['num_results']) ? (int)$pagination['num_results'] : 0;
        $page = isset($pagination['page']) ? (int)$pagination['page'] : 1;
        if ($numResults > 0) {
            return ($page * $perPage) < $numResults;
        }

        return $returnedCount >= $perPage;
    }

    private function acquireSyncLock() {
        $this->syncLockKey = 'campo_churchsuite_directory_sync';
        $stmt = $this->db->prepare("SELECT GET_LOCK(?, 0)");
        $stmt->execute([$this->syncLockKey]);
        $acquired = (int)$stmt->fetchColumn();

        if ($acquired !== 1) {
            $this->syncLockKey = null;
            throw new RuntimeException('A ChurchSuite directory sync is already running. Please wait a moment and try again.');
        }
    }

    private function releaseSyncLock() {
        if (!$this->syncLockKey) {
            return;
        }
        try {
            $stmt = $this->db->prepare("SELECT RELEASE_LOCK(?)");
            $stmt->execute([$this->syncLockKey]);
        } catch (Exception $e) {
            error_log('ChurchSuite directory sync lock release failed key=' . $this->syncLockKey . ' error=' . $e->getMessage());
        }
        $this->syncLockKey = null;
    }

    private function loadSyncState($syncToken = null) {
        $path = $this->syncStatePath();
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        $state = $raw ? json_decode($raw, true) : null;
        if (!is_array($state)) {
            @unlink($path);
            return null;
        }

        $updatedAt = strtotime((string)($state['updated_at'] ?? ''));
        if ($updatedAt !== false && $updatedAt < (time() - self::SYNC_STATE_TTL)) {
            @unlink($path);
            return null;
        }

        if ($syncToken !== null && $syncToken !== '' && !hash_equals((string)($state['token'] ?? ''), (string)$syncToken)) {
            throw new RuntimeException('This ChurchSuite directory sync is no longer active. Please start it again.');
        }

        return $state;
    }

    private function createSyncState() {
        try {
            $token = bin2hex(random_bytes(12));
        } catch (Exception $e) {
            $token = bin2hex(openssl_random_pseudo_bytes(12));
        }

        return [
            'token' => $token,
            'stage' => 'contacts',
            'page' => 1,
            'per_page' => self::SYNC_CHUNK_SIZE,
            'processed' => 0,
            'sync_started_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'summary' => [
                'created' => 0,
                'updated' => 0,
                'reviewed' => 0,
                'skipped' => 0
            ],
            'relationship_links' => []
        ];
    }

    private function captureRelationshipLinks(array &$state, array $records) {
        $count = 0;
        foreach ($records as $record) {
            $childId = trim((string)($record['child_id'] ?? ''));
            $parentCarer = $record['parent_carer'] ?? null;
            $contactId = is_array($parentCarer) ? trim((string)($parentCarer['contact_id'] ?? '')) : '';

            if ($childId === '' || $contactId === '') {
                continue;
            }

            $childKey = MemberMatchingService::churchsuiteExternalKey('child', $childId);
            $contactKey = MemberMatchingService::churchsuiteExternalKey('contact', $contactId);
            if ($childKey === '' || $contactKey === '') {
                continue;
            }

            $state['relationship_links'][$childKey . '|' . $contactKey] = [
                'member_key' => $childKey,
                'related_key' => $contactKey,
                'type' => 'parent_child'
            ];
            $count++;
        }

        return $count;
    }

    private function saveSyncState(array $state) {
        $directory = dirname($this->syncStatePath());
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($this->syncStatePath(), json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function deleteSyncState() {
        $path = $this->syncStatePath();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function syncStatePath() {
        return __DIR__ . '/../data/runtime/churchsuite-directory-sync/state.json';
    }

    private function assertSchemaReady() {
        $requiredTables = [
            'member_households',
            'member_household_members',
            'member_relationships',
            'household_agreement_documents'
        ];
        foreach ($requiredTables as $table) {
            $stmt = $this->db->query("SHOW TABLES LIKE " . $this->db->quote($table));
            if (!$stmt || !$stmt->fetch(PDO::FETCH_NUM)) {
                throw new RuntimeException('Database migration required before ChurchSuite directory sync can run. Missing table: ' . $table);
            }
        }

        $requiredColumns = [
            'email',
            'mobile',
            'phone',
            'churchsuite_person_type',
            'churchsuite_person_id',
            'churchsuite_sync_status',
            'churchsuite_sync_note',
            'churchsuite_last_synced_at',
            'churchsuite_payload_json',
            'digital_agreement_confirmed'
        ];

        $missing = [];
        foreach ($requiredColumns as $column) {
            $stmt = $this->db->query("SHOW COLUMNS FROM `members` LIKE " . $this->db->quote($column));
            if (!$stmt || $stmt->rowCount() === 0) {
                $missing[] = $column;
            }
        }

        if ($missing) {
            throw new RuntimeException(
                'Database migration required before ChurchSuite directory sync can run. Missing member columns: ' .
                implode(', ', $missing)
            );
        }
    }

    private function extractFirstString(array $record, array $paths) {
        foreach ($paths as $path) {
            $value = $this->valueAtPath($record, $path);
            if ($value !== null) {
                $value = trim((string)$value);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    }

    private function valueAtPath(array $record, array $path) {
        $value = $record;
        foreach ($path as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
                continue;
            }
            return null;
        }
        return $value;
    }
}
