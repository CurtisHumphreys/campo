<?php

require_once __DIR__ . '/ChurchSuiteClientInterface.php';
require_once __DIR__ . '/MemberMatchingService.php';

class ChurchSuiteSyncService {
    private $db;
    private $client;
    private $syncLockKey = null;
    private const SYNC_CHUNK_SIZE = 25;
    private const SYNC_STATE_TTL = 1800;
    private const SIGNUP_STATUSES = ['active', 'reserved', 'cancelled', 'refunded'];

    public function __construct(PDO $db, ChurchSuiteClientInterface $client) {
        $this->db = $db;
        $this->client = $client;
    }

    public function syncCamp($campId, $syncToken = null) {
        $this->assertSchemaReady();

        $camp = $this->loadCamp($campId);
        if (!$camp) {
            throw new RuntimeException('Camp not found.');
        }

        $eventRef = $this->campEventReference($camp);
        if ($eventRef === '') {
            throw new RuntimeException('This camp is not linked to a ChurchSuite event.');
        }

        $this->acquireSyncLock((int)$campId);

        try {
            $state = null;
            if ($syncToken !== null && $syncToken !== '') {
                $state = $this->loadSyncState((int)$campId, $syncToken);
            } else {
                $this->deleteSyncState((int)$campId);
            }
            if (!$state) {
                $state = $this->createSyncState($camp, $eventRef);
            }

            $event = $state['event'];
            $eventId = (int)($state['event']['id'] ?? 0);
            if ($eventId <= 0) {
                throw new RuntimeException('ChurchSuite did not return a valid event id.');
            }

            if (empty($state['ticket_prices']) || !is_array($state['ticket_prices'])) {
                $state['ticket_prices'] = $this->loadTicketPriceIndex($eventId);
            }

            $statuses = $this->normalizeSignupStatuses($state['statuses'] ?? null);
            $statusIndex = min(max((int)($state['status_index'] ?? 0), 0), count($statuses) - 1);
            $currentStatus = $statuses[$statusIndex];
            $state['statuses'] = $statuses;
            $state['status_index'] = $statusIndex;

            $response = $this->client->listSignups($eventId, (int)$state['page'], (int)$state['per_page'], $currentStatus);
            $signups = $response['signups'] ?? [];
            $memberIndexes = $this->buildMemberIndexes();
            $existingRows = $this->loadExistingSyncedRows($campId);
            $now = $state['sync_started_at'];
            $summary = $state['summary'];
            $seenSourceRanks = $this->normalizeSeenSourceRanks($state['seen_source_ids'] ?? null);

            $this->db->beginTransaction();

            foreach ($signups as $signup) {
                $prepared = $this->prepareSignup($signup, $state['ticket_prices']);
                if ($prepared['source_record_id'] === '') {
                    $summary['skipped_unpaid']++;
                    continue;
                }

                $sourceRecordId = $prepared['source_record_id'];
                $currentRank = $this->rankPreparedSignup($prepared);
                $seenRank = (int)($seenSourceRanks[$sourceRecordId] ?? 0);
                if ($seenRank > 0 && $seenRank >= $currentRank) {
                    continue;
                }

                $existing = $existingRows[$sourceRecordId] ?? null;

                if ($prepared['source_amount'] <= 0) {
                    if ($existing) {
                        if ($this->shouldPreserveExistingAmount($prepared, $existing)) {
                            $payload = $this->buildPreservedAmountPayload($prepared, $existing, $memberIndexes, $now);
                            if ($payload['sync_state'] === 'warning') {
                                $summary['warnings']++;
                            }
                            $changed = $this->saveSyncedRow($campId, $payload, $existing);
                            $summary[$changed ? 'updated' : 'unchanged']++;
                            $this->refreshExistingRowCache($existingRows, $sourceRecordId, $existing, $payload, $campId);
                        } else {
                            $payload = $this->buildSyncedRowPayload($prepared, $existing, $memberIndexes, $now, 'ChurchSuite no longer reports a paid amount for this signup.');
                            if ($payload['sync_state'] === 'warning') {
                                $summary['warnings']++;
                            }
                            if ($this->saveSyncedRow($campId, $payload, $existing)) {
                                $summary['zeroed']++;
                            } else {
                                $summary['unchanged']++;
                            }
                            $this->refreshExistingRowCache($existingRows, $sourceRecordId, $existing, $payload, $campId);
                        }
                        $seenSourceRanks[$sourceRecordId] = max($seenRank, $currentRank);
                    } else {
                        $summary['skipped_unpaid']++;
                    }
                    continue;
                }

                $payload = $this->buildSyncedRowPayload($prepared, $existing, $memberIndexes, $now);
                if ($payload['sync_state'] === 'warning') {
                    $summary['warnings']++;
                }

                $changed = $this->saveSyncedRow($campId, $payload, $existing);
                if ($existing) {
                    $summary[$changed ? 'updated' : 'unchanged']++;
                } else {
                    $summary['created']++;
                }
                $this->refreshExistingRowCache($existingRows, $sourceRecordId, $existing, $payload, $campId);
                $seenSourceRanks[$sourceRecordId] = max($seenRank, $currentRank);
            }

            $this->db->commit();

            $pagination = $response['pagination'] ?? [];
            $returnedCount = count($signups);
            $processed = ((int)$state['page'] - 1) * (int)$state['per_page'] + $returnedCount;
            $totalResults = $this->extractTotalResults($pagination);
            $hasMore = $this->hasMoreSignupPages($pagination, $returnedCount, (int)$state['per_page'], $processed, $totalResults);

            $state['summary'] = $summary;
            $state['processed'] = $processed;
            $state['total_results'] = $totalResults;
            $state['seen_source_ids'] = $seenSourceRanks;
            $state['updated_at'] = date('Y-m-d H:i:s');

            if ($hasMore) {
                $state['page'] = !empty($pagination['next_page']) ? (int)$pagination['next_page'] : ((int)$state['page'] + 1);
            } elseif (($statusIndex + 1) < count($statuses)) {
                $state['status_index'] = $statusIndex + 1;
                $state['page'] = 1;
                $hasMore = true;
            }

            if ($hasMore) {
                $this->saveSyncState((int)$campId, $state);
                $camp = $this->writeCampSyncMetadata(
                    $campId,
                    $state['updated_at'],
                    'running',
                    $this->buildProgressMessage($state),
                    $event,
                    $camp
                );

                return [
                    'success' => true,
                    'in_progress' => true,
                    'sync_token' => $state['token'],
                    'summary' => $summary,
                    'camp' => $camp,
                    'progress' => [
                        'page' => (int)$state['page'],
                        'status' => $statuses[(int)($state['status_index'] ?? 0)] ?? $currentStatus,
                        'processed' => $processed,
                        'total_results' => $totalResults
                    ],
                    'last_sync_at' => $camp['churchsuite_last_sync_at'] ?? $state['updated_at'],
                    'last_sync_status' => $camp['churchsuite_last_sync_status'] ?? 'running',
                    'last_sync_message' => $camp['churchsuite_last_sync_message'] ?? $this->buildProgressMessage($state)
                ];
            }

            $final = $this->finalizeSync((int)$campId, $camp, $event, $state, $memberIndexes);
            $this->deleteSyncState((int)$campId);
            return $final;
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->recordSyncFailure($campId, date('Y-m-d H:i:s'), $e->getMessage());
            throw $e;
        } finally {
            $this->releaseSyncLock();
        }
    }

    private function acquireSyncLock($campId) {
        $this->syncLockKey = 'campo_churchsuite_sync_' . (int)$campId;
        $stmt = $this->db->prepare("SELECT GET_LOCK(?, 0)");
        $stmt->execute([$this->syncLockKey]);
        $acquired = (int)$stmt->fetchColumn();

        if ($acquired !== 1) {
            $this->syncLockKey = null;
            throw new RuntimeException('A ChurchSuite sync is already running for this camp. Please wait a moment and try again.');
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
            error_log('ChurchSuite sync lock release failed key=' . $this->syncLockKey . ' error=' . $e->getMessage());
        }

        $this->syncLockKey = null;
    }

    private function loadSyncState($campId, $syncToken = null) {
        $path = $this->syncStatePath($campId);
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

        if ((int)($state['camp_id'] ?? 0) !== (int)$campId) {
            @unlink($path);
            return null;
        }

        if ($syncToken !== null && $syncToken !== '' && !hash_equals((string)($state['token'] ?? ''), (string)$syncToken)) {
            throw new RuntimeException('This ChurchSuite sync is no longer active. Please start it again.');
        }

        return $state;
    }

    private function createSyncState(array $camp, $eventRef) {
        $eventId = !empty($camp['churchsuite_event_id']) ? (int)$camp['churchsuite_event_id'] : 0;
        if ($eventId > 0) {
            $event = [
                'id' => $eventId,
                'identifier' => $camp['churchsuite_event_identifier'] ?? null,
                'name' => $camp['churchsuite_event_name'] ?? null
            ];
        } else {
            $event = $this->client->getEvent($eventRef);
            $eventId = !empty($event['id']) ? (int)$event['id'] : 0;
            if ($eventId <= 0) {
                throw new RuntimeException('ChurchSuite did not return a valid event id.');
            }
        }

        try {
            $token = bin2hex(random_bytes(12));
        } catch (Exception $e) {
            $token = bin2hex(openssl_random_pseudo_bytes(12));
        }

        return [
            'token' => $token,
            'camp_id' => (int)$camp['id'],
            'event' => $event,
            'page' => 1,
            'per_page' => self::SYNC_CHUNK_SIZE,
            'processed' => 0,
            'total_results' => null,
            'statuses' => self::SIGNUP_STATUSES,
            'status_index' => 0,
            'ticket_prices' => [],
            'seen_source_ids' => [],
            'sync_started_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'summary' => [
                'created' => 0,
                'updated' => 0,
                'unchanged' => 0,
                'skipped_unpaid' => 0,
                'zeroed' => 0,
                'warnings' => 0
            ]
        ];
    }

    private function saveSyncState($campId, array $state) {
        $directory = dirname($this->syncStatePath($campId));
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        file_put_contents($this->syncStatePath($campId), json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function deleteSyncState($campId) {
        $path = $this->syncStatePath($campId);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function syncStatePath($campId) {
        return __DIR__ . '/../data/runtime/churchsuite-sync/camp-' . (int)$campId . '.json';
    }

    private function extractTotalResults(array $pagination) {
        foreach (['num_results', 'no_results', 'total', 'total_results'] as $key) {
            if (isset($pagination[$key]) && is_numeric($pagination[$key])) {
                return (int)$pagination[$key];
            }
        }
        return null;
    }

    private function hasMoreSignupPages(array $pagination, $returnedCount, $perPage, $processed, $totalResults) {
        if (!empty($pagination['next_page'])) {
            return true;
        }
        if ($totalResults !== null) {
            return $processed < $totalResults;
        }
        return (int)$returnedCount === (int)$perPage;
    }

    private function buildProgressMessage(array $state) {
        $processed = (int)($state['processed'] ?? 0);
        $totalResults = $state['total_results'] ?? null;
        $statuses = $this->normalizeSignupStatuses($state['statuses'] ?? null);
        $statusIndex = min(max((int)($state['status_index'] ?? 0), 0), count($statuses) - 1);
        $statusLabel = ucfirst($statuses[$statusIndex]);

        if ($totalResults !== null && $totalResults > 0) {
            return sprintf('ChurchSuite sync in progress (%s signups). %d of %d records processed so far.', $statusLabel, $processed, $totalResults);
        }

        return sprintf('ChurchSuite sync in progress (%s signups). %d records processed so far.', $statusLabel, $processed);
    }

    private function finalizeSync($campId, array $camp, array $event, array $state, array $memberIndexes) {
        $summary = $state['summary'];
        $syncStamp = $state['sync_started_at'];
        $existingRows = $this->loadExistingSyncedRows($campId);

        $this->db->beginTransaction();
        try {
            foreach ($existingRows as $existing) {
                if (($existing['source_synced_at'] ?? null) === $syncStamp) {
                    continue;
                }

                $payload = $this->buildMissingSignupPayload($existing, $memberIndexes, $syncStamp);
                if ($payload['sync_state'] === 'warning') {
                    $summary['warnings']++;
                }

                $changed = $this->saveSyncedRow($campId, $payload, $existing);
                $summary[$changed ? 'updated' : 'unchanged']++;
            }

            $syncStatus = $summary['warnings'] > 0 ? 'warning' : 'success';
            $message = $this->buildSuccessMessage($summary);
            $camp = $this->writeCampSyncMetadata($campId, date('Y-m-d H:i:s'), $syncStatus, $message, $event, $camp);

            $this->db->commit();

            return [
                'success' => true,
                'completed' => true,
                'summary' => $summary,
                'camp' => $camp,
                'last_sync_at' => $camp['churchsuite_last_sync_at'] ?? date('Y-m-d H:i:s'),
                'last_sync_status' => $camp['churchsuite_last_sync_status'] ?? $syncStatus,
                'last_sync_message' => $camp['churchsuite_last_sync_message'] ?? $message
            ];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function loadCamp($campId) {
        $stmt = $this->db->prepare("SELECT * FROM camps WHERE id = ?");
        $stmt->execute([(int)$campId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function assertSchemaReady() {
        $requiredColumns = [
            'first_name',
            'last_name',
            'email',
            'mobile',
            'phone',
            'source_amount',
            'transaction_id',
            'source_system',
            'source_record_id',
            'source_person_type',
            'source_person_id',
            'match_source',
            'match_note',
            'source_currency',
            'source_payment_status',
            'source_arrival_date',
            'source_departure_date',
            'source_site_number',
            'source_accommodation_type',
            'source_party_size',
            'source_day_trip',
            'source_synced_at',
            'sync_state',
            'sync_note'
        ];

        $missing = [];
        foreach ($requiredColumns as $column) {
            if (!$this->hasPrepaymentColumn($column)) {
                $missing[] = $column;
            }
        }

        if ($missing) {
            throw new RuntimeException(
                'Database migration required before ChurchSuite sync can run. Missing prepayments columns: ' .
                implode(', ', $missing) .
                '. Open /api/migrate once, then retry.'
            );
        }
    }

    private function campEventReference(array $camp) {
        if (!empty($camp['churchsuite_event_id'])) {
            return trim((string)$camp['churchsuite_event_id']);
        }

        return trim((string)($camp['churchsuite_event_identifier'] ?? ''));
    }

    private function loadAllSignups($eventId) {
        $page = 1;
        $perPage = 100;
        $all = [];

        while (true) {
            $response = $this->client->listSignups($eventId, $page, $perPage);
            $items = $response['signups'] ?? [];
            $all = array_merge($all, $items);

            $pagination = $response['pagination'] ?? [];
            $totalResults = isset($pagination['no_results']) ? (int)$pagination['no_results'] : null;
            $returnedCount = count($items);

            if ($returnedCount === 0) {
                break;
            }
            if ($totalResults !== null && count($all) >= $totalResults) {
                break;
            }
            if ($returnedCount < $perPage) {
                break;
            }

            $page++;
        }

        return $all;
    }

    private function hasPrepaymentColumn($column) {
        static $cache = [];

        if (array_key_exists($column, $cache)) {
            return $cache[$column];
        }

        $stmt = $this->db->query("SHOW COLUMNS FROM `prepayments` LIKE " . $this->db->quote($column));
        $cache[$column] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);

        return $cache[$column];
    }

    private function buildMemberIndexes() {
        return (new MemberMatchingService($this->db))->buildIndexes();
    }

    private function loadExistingSyncedRows($campId) {
        $stmt = $this->db->prepare("SELECT * FROM prepayments WHERE camp_id = ? AND source_system = 'churchsuite'");
        $stmt->execute([(int)$campId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $mapped = [];
        foreach ($rows as $row) {
            $mapped[(string)($row['source_record_id'] ?? '')] = $row;
        }
        return $mapped;
    }

    private function prepareSignup(array $signup, array $ticketPrices = []) {
        $firstName = trim((string)($signup['first_name'] ?? ($signup['person']['first_name'] ?? '')));
        $lastName = trim((string)($signup['last_name'] ?? ($signup['person']['last_name'] ?? '')));
        $importedName = trim($firstName . ' ' . $lastName);
        $email = trim((string)($signup['email'] ?? ($signup['person']['email'] ?? ($signup['person']['emails'][0]['email'] ?? ''))));
        $mobile = trim((string)($signup['mobile'] ?? ($signup['person']['mobile'] ?? ($signup['person']['phone_mobile'] ?? ''))));
        $phone = trim((string)($signup['phone'] ?? ($signup['person']['phone'] ?? ($signup['person']['telephone'] ?? ''))));

        $sourceRecordId = trim((string)($signup['id'] ?? ''));
        if ($sourceRecordId === '') {
            $sourceRecordId = trim((string)($signup['identifier'] ?? ''));
        }
        if ($sourceRecordId === '') {
            $sourceRecordId = trim((string)($signup['batch_id'] ?? ''));
        }

        $sourceCurrency = $signup['person']['ticket']['currency']['code'] ?? null;
        $sourceAmount = $this->deriveSignupPaidAmount($signup, $sourceCurrency, $ticketPrices);
        $paymentStatus = trim((string)($signup['payment_status'] ?? ($signup['person']['ticket']['paid_status'] ?? '')));
        $signupStatus = trim((string)($signup['status'] ?? ''));
        $transactionId = trim((string)($signup['batch_id'] ?? ($signup['identifier'] ?? $sourceRecordId)));
        $date = trim((string)($signup['created_at'] ?? ($signup['ctime'] ?? ($signup['modified_at'] ?? ($signup['mtime'] ?? '')))));
        $personType = $this->extractSignupPersonType($signup);
        $personId = $this->extractSignupPersonId($signup);
        $campDetails = $this->extractSignupCampDetails($signup);

        return [
            'source_record_id' => $sourceRecordId,
            'imported_name' => $importedName,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'mobile' => $mobile,
            'phone' => $phone,
            'transaction_id' => $transactionId,
            'date' => $date,
            'source_amount' => $sourceAmount,
            'source_currency' => $sourceCurrency,
            'source_payment_status' => $paymentStatus !== '' ? $paymentStatus : 'unknown',
            'source_signup_status' => $signupStatus !== '' ? $signupStatus : 'unknown',
            'source_person_type' => $personType,
            'source_person_id' => $personId,
            'source_arrival_date' => $campDetails['arrival_date'],
            'source_departure_date' => $campDetails['departure_date'],
            'source_site_number' => $campDetails['site_number'],
            'source_accommodation_type' => $campDetails['accommodation_type'],
            'source_party_size' => $campDetails['party_size'],
            'source_day_trip' => $campDetails['day_trip'],
            'original_data' => json_encode($signup, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ];
    }

    private function extractSignupPersonType(array $signup) {
        $type = strtolower(trim((string)($signup['person']['type'] ?? ($signup['person']['module'] ?? ($signup['person_type'] ?? 'contact')))));
        return strpos($type, 'child') !== false ? 'child' : 'contact';
    }

    private function extractSignupPersonId(array $signup) {
        $candidates = [
            $signup['person']['id'] ?? null,
            $signup['person']['contact_id'] ?? null,
            $signup['person']['child_id'] ?? null,
            $signup['person_id'] ?? null
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string)$candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function extractSignupCampDetails(array $signup) {
        $entries = [];
        $this->collectSignupFieldEntries($signup, '', $entries);

        $accommodationType = $this->findSignupStringValue($entries, [
            'accommodation type',
            'accommodation',
            'site type',
            'ticket name'
        ], [
            'payment status',
            'paid status'
        ]);

        $dayTrip = $this->findSignupBooleanValue($entries, ['day trip']);
        if ($dayTrip === null && $accommodationType !== null) {
            $dayTrip = strpos($this->normalizeLookupKey($accommodationType), 'day trip') !== false;
        }

        return [
            'arrival_date' => $this->findSignupDateValue($entries, [
                'arrival date',
                'arrival',
                'arrive',
                'check in',
                'check-in'
            ]),
            'departure_date' => $this->findSignupDateValue($entries, [
                'departure date',
                'departure',
                'depart',
                'check out',
                'check-out',
                'leaving'
            ]),
            'site_number' => $this->findSignupStringValue($entries, [
                'site #',
                'site number',
                'site no',
                'camp site'
            ]),
            'accommodation_type' => $accommodationType,
            'party_size' => $this->findSignupIntegerValue($entries, [
                'family members',
                'number of family members',
                'party size',
                'number attending',
                'attendees',
                'family size'
            ]),
            'day_trip' => $dayTrip
        ];
    }

    private function collectSignupFieldEntries($value, $path, array &$entries, $depth = 0) {
        if ($depth > 6 || count($entries) >= 400) {
            return;
        }

        if (is_array($value)) {
            if ($this->isAssocArray($value)) {
                $label = $this->firstScalarValue($value, [
                    'label',
                    'question',
                    'question_label',
                    'field',
                    'field_label',
                    'name',
                    'title',
                    'prompt',
                    'key'
                ]);
                $entryValue = $this->firstDisplayableValue($value, [
                    'value',
                    'answer',
                    'response',
                    'text',
                    'display_value',
                    'formatted_value',
                    'content',
                    'selected',
                    'selected_value',
                    'selected_option',
                    'option',
                    'choice'
                ]);

                if ($label !== null && $entryValue !== null) {
                    $this->appendSignupFieldEntry($entries, $path !== '' ? $path : $label, $label, $entryValue);
                }
            }

            foreach ($value as $key => $child) {
                $childPath = $path === '' ? (string)$key : ($path . '.' . (string)$key);
                $this->collectSignupFieldEntries($child, $childPath, $entries, $depth + 1);
            }
            return;
        }

        if (is_scalar($value) || $value === null) {
            $scalarValue = $this->stringifyFieldValue($value);
            if ($scalarValue !== null && $path !== '') {
                $this->appendSignupFieldEntry($entries, $path, $path, $scalarValue);
            }
        }
    }

    private function appendSignupFieldEntry(array &$entries, $path, $label, $value) {
        $path = trim((string)$path);
        $label = trim((string)$label);
        $value = $this->stringifyFieldValue($value);

        if ($value === null) {
            return;
        }

        $entries[] = [
            'path' => $path,
            'label' => $label !== '' ? $label : $path,
            'value' => $value,
            'normalized_path' => $this->normalizeLookupKey($path),
            'normalized_label' => $this->normalizeLookupKey($label !== '' ? $label : $path),
            'normalized_value' => $this->normalizeLookupKey($value)
        ];
    }

    private function findSignupStringValue(array $entries, array $keywords, array $excludeKeywords = []) {
        foreach ($entries as $entry) {
            if (!$this->entryMatchesKeywords($entry, $keywords, $excludeKeywords)) {
                continue;
            }

            $value = trim((string)($entry['value'] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function findSignupDateValue(array $entries, array $keywords, array $excludeKeywords = []) {
        foreach ($entries as $entry) {
            if (!$this->entryMatchesKeywords($entry, $keywords, $excludeKeywords)) {
                continue;
            }

            $date = $this->normalizeDateValue($entry['value'] ?? null);
            if ($date !== null) {
                return $date;
            }
        }

        return null;
    }

    private function findSignupIntegerValue(array $entries, array $keywords, array $excludeKeywords = []) {
        foreach ($entries as $entry) {
            if (!$this->entryMatchesKeywords($entry, $keywords, $excludeKeywords)) {
                continue;
            }

            $value = $this->parsePossibleHeadcount($entry['value'] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function findSignupBooleanValue(array $entries, array $keywords, array $excludeKeywords = []) {
        foreach ($entries as $entry) {
            if (!$this->entryMatchesKeywords($entry, $keywords, $excludeKeywords)) {
                continue;
            }

            $value = $this->parseBooleanLike($entry['value'] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function entryMatchesKeywords(array $entry, array $keywords, array $excludeKeywords = []) {
        $haystacks = [
            $entry['normalized_label'] ?? '',
            $entry['normalized_path'] ?? ''
        ];

        foreach ($excludeKeywords as $keyword) {
            $needle = $this->normalizeLookupKey($keyword);
            if ($needle === '') {
                continue;
            }

            foreach ($haystacks as $haystack) {
                if ($haystack !== '' && strpos($haystack, $needle) !== false) {
                    return false;
                }
            }
        }

        foreach ($keywords as $keyword) {
            $needle = $this->normalizeLookupKey($keyword);
            if ($needle === '') {
                continue;
            }

            foreach ($haystacks as $haystack) {
                if ($haystack !== '' && strpos($haystack, $needle) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalizeLookupKey($value) {
        $value = strtolower(trim((string)$value));
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
        return trim((string)$value);
    }

    private function stringifyFieldValue($value) {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_scalar($value)) {
            $string = trim((string)$value);
            return $string !== '' ? $string : null;
        }

        if (!is_array($value)) {
            return null;
        }

        if ($this->isAssocArray($value)) {
            foreach (['label', 'name', 'title', 'value', 'text'] as $key) {
                if (array_key_exists($key, $value)) {
                    $string = $this->stringifyFieldValue($value[$key]);
                    if ($string !== null) {
                        return $string;
                    }
                }
            }
            return null;
        }

        $parts = [];
        foreach ($value as $item) {
            $string = $this->stringifyFieldValue($item);
            if ($string !== null) {
                $parts[] = $string;
            }
        }

        return $parts ? implode(', ', array_unique($parts)) : null;
    }

    private function firstScalarValue(array $row, array $keys) {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }

            $value = $this->stringifyFieldValue($row[$key]);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function firstDisplayableValue(array $row, array $keys) {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }

            $value = $this->stringifyFieldValue($row[$key]);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function normalizeDateValue($value) {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }

    private function parsePossibleHeadcount($value) {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        preg_match_all('/\d+/', $value, $matches);
        $numbers = array_map('intval', $matches[0] ?? []);
        if (!$numbers) {
            return null;
        }

        if (count($numbers) === 1) {
            return $numbers[0];
        }

        $sum = array_sum($numbers);
        return $sum > 0 && $sum <= 20 ? $sum : $numbers[0];
    }

    private function parseBooleanLike($value) {
        $normalized = $this->normalizeLookupKey($value);
        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, ['yes', 'y', 'true', '1', 'day trip'], true)) {
            return true;
        }

        if (in_array($normalized, ['no', 'n', 'false', '0'], true)) {
            return false;
        }

        return null;
    }

    private function isAssocArray(array $value) {
        return array_keys($value) !== range(0, count($value) - 1);
    }

    private function deriveSignupPaidAmount(array $signup, &$currencyCode = null, array $ticketPrices = []) {
        $paymentStatus = $this->normalizeStatus($signup['payment_status'] ?? ($signup['person']['ticket']['paid_status'] ?? ''));
        $refundAmount = $this->fromMinorMoney($signup['refund_amount'] ?? null);

        if (array_key_exists('paid_amount', $signup)) {
            $paidAmount = $this->fromMinorMoney($signup['paid_amount']);
            if ($paidAmount !== null) {
                if ($refundAmount !== null) {
                    $paidAmount = max($paidAmount - $refundAmount, 0.0);
                }
                return round($paidAmount, 2);
            }
        }

        $payments = $signup['payments'] ?? [];
        $sum = 0.0;
        $foundAmount = false;

        if (is_array($payments)) {
            foreach ($payments as $payment) {
                $value = $this->extractAmountFromPayment($payment);
                if ($value !== null) {
                    $sum += $value;
                    $foundAmount = true;
                }
                if ($currencyCode === null && is_array($payment)) {
                    $currencyCode = $payment['currency']['code'] ?? ($payment['currency_code'] ?? $currencyCode);
                }
            }
        }

        if ($foundAmount) {
            return round($sum, 2);
        }

        $ticketPaid = $this->toMoney($signup['person']['ticket']['paid'] ?? null);
        if ($ticketPaid !== null) {
            $ticketCurrency = $signup['person']['ticket']['currency']['code'] ?? null;
            if ($currencyCode === null && $ticketCurrency) {
                $currencyCode = $ticketCurrency;
            }
            return round($ticketPaid, 2);
        }

        $ticketId = (int)($signup['ticket_id'] ?? ($signup['person']['ticket']['id'] ?? 0));
        $ticketPrice = $ticketId > 0 && array_key_exists($ticketId, $ticketPrices)
            ? (float)$ticketPrices[$ticketId]
            : null;
        if ($ticketPrice !== null && $ticketPrice > 0) {
            if (in_array($paymentStatus, ['paid', 'overpaid'], true)) {
                return round($ticketPrice, 2);
            }
            if ($paymentStatus === 'partial_refund' && $refundAmount !== null) {
                return round(max($ticketPrice - $refundAmount, 0.0), 2);
            }
        }

        return 0.0;
    }

    private function fromMinorMoney($value) {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            $clean = preg_replace('/[^0-9.\-]/', '', (string)$value);
            if ($clean === '' || !is_numeric($clean)) {
                return null;
            }
            $value = $clean;
        }

        return round(((float)$value) / 100, 2);
    }

    private function extractAmountFromPayment($payment) {
        if (is_numeric($payment)) {
            return (float)$payment;
        }
        if (!is_array($payment)) {
            return null;
        }

        foreach (['amount', 'paid', 'value', 'total', 'gross'] as $key) {
            $money = $this->toMoney($payment[$key] ?? null);
            if ($money !== null) {
                return $money;
            }
        }

        return null;
    }

    private function toMoney($value) {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (float)$value;
        }

        $clean = preg_replace('/[^0-9.\-]/', '', (string)$value);
        if ($clean === '' || !is_numeric($clean)) {
            return null;
        }

        return (float)$clean;
    }

    private function loadTicketPriceIndex($eventId) {
        $prices = [];
        $page = 1;
        $perPage = 100;

        try {
            while (true) {
                $response = $this->client->listTickets($eventId, $page, $perPage);
                $tickets = $response['tickets'] ?? [];

                foreach ($tickets as $ticket) {
                    $ticketId = (int)($ticket['id'] ?? 0);
                    if ($ticketId <= 0) {
                        continue;
                    }

                    $price = $this->fromMinorMoney($ticket['price'] ?? null);
                    if ($price !== null) {
                        $prices[$ticketId] = $price;
                    }
                }

                $pagination = $response['pagination'] ?? [];
                $returnedCount = count($tickets);
                $totalResults = $this->extractTotalResults($pagination);

                if ($returnedCount === 0) {
                    break;
                }
                if ($totalResults !== null && count($prices) >= $totalResults) {
                    break;
                }
                if ($returnedCount < $perPage && empty($pagination['next_page'])) {
                    break;
                }

                $page = !empty($pagination['next_page']) ? (int)$pagination['next_page'] : ($page + 1);
            }
        } catch (Exception $e) {
            error_log('ChurchSuite ticket price lookup failed event_id=' . (int)$eventId . ' error=' . $e->getMessage());
        }

        return $prices;
    }

    private function normalizeSignupStatuses($statuses) {
        if (!is_array($statuses) || !$statuses) {
            return self::SIGNUP_STATUSES;
        }

        $normalized = [];
        foreach ($statuses as $status) {
            $value = $this->normalizeStatus($status);
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return $normalized ?: self::SIGNUP_STATUSES;
    }

    private function normalizeSeenSourceRanks($seenSourceIds) {
        if (!is_array($seenSourceIds) || !$seenSourceIds) {
            return [];
        }

        $normalized = [];
        foreach ($seenSourceIds as $sourceRecordId => $rank) {
            $key = trim((string)$sourceRecordId);
            if ($key === '') {
                continue;
            }

            $normalized[$key] = max(0, (int)$rank);
        }

        return $normalized;
    }

    private function normalizeStatus($value) {
        return strtolower(trim((string)$value));
    }

    private function rankPreparedSignup(array $prepared) {
        $sourceAmount = round((float)($prepared['source_amount'] ?? 0), 2);
        if ($sourceAmount > 0) {
            return 3;
        }

        if ($this->isExplicitZeroStatus(
            $prepared['source_payment_status'] ?? '',
            $prepared['source_signup_status'] ?? ''
        )) {
            return 2;
        }

        return 1;
    }

    private function shouldPreserveExistingAmount(array $prepared, array $existing) {
        if ((float)($prepared['source_amount'] ?? 0) > 0) {
            return false;
        }

        $existingSourceAmount = round((float)($existing['source_amount'] ?? 0), 2);
        $existingAmount = round((float)($existing['amount'] ?? 0), 2);
        if ($existingSourceAmount <= 0 && $existingAmount <= 0) {
            return false;
        }

        return !$this->isExplicitZeroStatus(
            $prepared['source_payment_status'] ?? '',
            $prepared['source_signup_status'] ?? ''
        );
    }

    private function isExplicitZeroStatus($paymentStatus, $signupStatus) {
        $paymentStatus = $this->normalizeStatus($paymentStatus);
        $signupStatus = $this->normalizeStatus($signupStatus);

        if (in_array($paymentStatus, ['free', 'unpaid', 'failed', 'full_refund'], true)) {
            return true;
        }

        return $signupStatus === 'refunded';
    }

    private function buildPreservedAmountPayload(array $prepared, array $existing, array $memberIndexes, $now) {
        $preserved = $prepared;
        $preserved['source_amount'] = max(
            round((float)($existing['source_amount'] ?? 0), 2),
            round((float)($existing['amount'] ?? 0), 2)
        );

        $payload = $this->buildSyncedRowPayload($preserved, $existing, $memberIndexes, $now);
        $payload['sync_state'] = 'warning';
        $payload['sync_note'] = 'ChurchSuite returned this signup without a paid amount, so Campo kept the previous paid amount instead of zeroing it.';

        return $payload;
    }

    private function buildMissingSignupPayload(array $existing, array $memberIndexes, $now) {
        $prepared = [
            'source_record_id' => (string)($existing['source_record_id'] ?? ''),
            'imported_name' => $existing['imported_name'] ?? '',
            'first_name' => $existing['first_name'] ?? '',
            'last_name' => $existing['last_name'] ?? '',
            'email' => $existing['email'] ?? '',
            'mobile' => $existing['mobile'] ?? '',
            'phone' => $existing['phone'] ?? '',
            'transaction_id' => $existing['transaction_id'] ?? '',
            'date' => $existing['date'] ?? '',
            'source_amount' => (float)($existing['source_amount'] ?? $existing['amount'] ?? 0),
            'source_currency' => $existing['source_currency'] ?? null,
            'source_payment_status' => $existing['source_payment_status'] ?? 'missing',
            'source_signup_status' => 'missing',
            'source_person_type' => $existing['source_person_type'] ?? 'contact',
            'source_person_id' => (string)($existing['source_person_id'] ?? ''),
            'source_arrival_date' => $existing['source_arrival_date'] ?? null,
            'source_departure_date' => $existing['source_departure_date'] ?? null,
            'source_site_number' => $existing['source_site_number'] ?? null,
            'source_accommodation_type' => $existing['source_accommodation_type'] ?? null,
            'source_party_size' => $existing['source_party_size'] ?? null,
            'source_day_trip' => array_key_exists('source_day_trip', $existing) ? $existing['source_day_trip'] : null,
            'original_data' => $existing['original_data'] ?? null
        ];

        $payload = $this->buildSyncedRowPayload($prepared, $existing, $memberIndexes, $now);
        $payload['sync_state'] = 'warning';
        $payload['sync_note'] = 'ChurchSuite did not return this signup during sync, so Campo kept the previous paid amount for review instead of zeroing it.';

        return $payload;
    }

    private function buildSyncedRowPayload(array $prepared, $existing, array $memberIndexes, $now, $zeroNote = null) {
        $existingAmount = $existing ? (float)($existing['amount'] ?? 0) : 0.0;
        $existingSourceAmount = $existing ? (float)($existing['source_amount'] ?? 0) : 0.0;
        $alreadyApplied = max($existingSourceAmount - $existingAmount, 0.0);
        $newSourceAmount = round((float)$prepared['source_amount'], 2);
        $newRemaining = round(max($newSourceAmount - $alreadyApplied, 0.0), 2);

        $matchedMemberId = $existing && !empty($existing['matched_member_id'])
            ? (int)$existing['matched_member_id']
            : null;
        $baseStatus = 'Unmatched';
        $matchSource = $existing['match_source'] ?? null;
        $matchNote = $existing['match_note'] ?? null;

        if ($matchedMemberId) {
            $baseStatus = 'Matched';
        } else {
            $match = $this->autoMatchMember($prepared, $memberIndexes);
            if (!empty($match['matched_member_id'])) {
                $matchedMemberId = (int)$match['matched_member_id'];
            }
            $baseStatus = $match['status'];
            $matchSource = $match['match_source'];
            $matchNote = $match['match_note'];
        }

        $syncState = 'ok';
        $syncNote = null;

        if ($newSourceAmount < $alreadyApplied) {
            $syncState = 'warning';
            $syncNote = sprintf(
                'ChurchSuite now shows $%0.2f paid, but Campo has already applied $%0.2f from this pre-payment.',
                $newSourceAmount,
                $alreadyApplied
            );
            $newRemaining = 0.0;
        } elseif ($newSourceAmount <= 0 && $zeroNote) {
            $syncNote = $zeroNote;
        }

        return [
            'imported_name' => $prepared['imported_name'] !== '' ? $prepared['imported_name'] : ($existing['imported_name'] ?? ''),
            'first_name' => $prepared['first_name'],
            'last_name' => $prepared['last_name'],
            'email' => $prepared['email'] !== '' ? $prepared['email'] : ($existing['email'] ?? null),
            'mobile' => $prepared['mobile'] !== '' ? $prepared['mobile'] : ($existing['mobile'] ?? null),
            'phone' => $prepared['phone'] !== '' ? $prepared['phone'] : ($existing['phone'] ?? null),
            'amount' => $newRemaining,
            'source_amount' => $newSourceAmount,
            'transaction_id' => $prepared['transaction_id'] !== '' ? $prepared['transaction_id'] : ($existing['transaction_id'] ?? ''),
            'date' => $prepared['date'] !== '' ? $prepared['date'] : ($existing['date'] ?? ''),
            'matched_member_id' => $matchedMemberId,
            'match_source' => $matchSource,
            'match_note' => $matchNote,
            'original_data' => $prepared['original_data'],
            'status' => $this->deriveStatus($matchedMemberId, $baseStatus, $newRemaining, $alreadyApplied),
            'source_system' => 'churchsuite',
            'source_record_id' => $prepared['source_record_id'],
            'source_person_type' => $prepared['source_person_type'],
            'source_person_id' => $prepared['source_person_id'],
            'source_currency' => $prepared['source_currency'],
            'source_payment_status' => $prepared['source_payment_status'],
            'source_arrival_date' => $this->preferPreparedDate($prepared['source_arrival_date'] ?? null, $existing['source_arrival_date'] ?? null),
            'source_departure_date' => $this->preferPreparedDate($prepared['source_departure_date'] ?? null, $existing['source_departure_date'] ?? null),
            'source_site_number' => $this->preferPreparedString($prepared['source_site_number'] ?? null, $existing['source_site_number'] ?? null),
            'source_accommodation_type' => $this->preferPreparedString($prepared['source_accommodation_type'] ?? null, $existing['source_accommodation_type'] ?? null),
            'source_party_size' => $this->preferPreparedInteger($prepared['source_party_size'] ?? null, $existing['source_party_size'] ?? null),
            'source_day_trip' => $this->preferPreparedFlag($prepared['source_day_trip'] ?? null, $existing['source_day_trip'] ?? null),
            'source_synced_at' => $now,
            'sync_state' => $syncState,
            'sync_note' => $syncNote
        ];
    }

    private function refreshExistingRowCache(array &$existingRows, $sourceRecordId, $existing, array $payload, $campId) {
        $sourceRecordId = trim((string)$sourceRecordId);
        if ($sourceRecordId === '') {
            return;
        }

        if ($existing) {
            $existingRows[$sourceRecordId] = array_merge(
                $existingRows[$sourceRecordId] ?? $existing,
                $payload,
                [
                    'id' => $existing['id'] ?? ($existingRows[$sourceRecordId]['id'] ?? null),
                    'camp_id' => (int)$campId
                ]
            );
            return;
        }

        $existingRows[$sourceRecordId] = array_merge(
            $payload,
            [
                'id' => (int)$this->db->lastInsertId(),
                'camp_id' => (int)$campId
            ]
        );
    }

    private function preferPreparedString($preparedValue, $existingValue = null) {
        $prepared = trim((string)$preparedValue);
        if ($prepared !== '') {
            return $prepared;
        }

        $existing = trim((string)$existingValue);
        return $existing !== '' ? $existing : null;
    }

    private function preferPreparedDate($preparedValue, $existingValue = null) {
        $prepared = $this->normalizeDateValue($preparedValue);
        if ($prepared !== null) {
            return $prepared;
        }

        return $this->normalizeDateValue($existingValue);
    }

    private function preferPreparedInteger($preparedValue, $existingValue = null) {
        if ($preparedValue !== null && $preparedValue !== '') {
            return (int)$preparedValue;
        }

        if ($existingValue !== null && $existingValue !== '') {
            return (int)$existingValue;
        }

        return null;
    }

    private function preferPreparedFlag($preparedValue, $existingValue = null) {
        if ($preparedValue !== null && $preparedValue !== '') {
            return $preparedValue ? 1 : 0;
        }

        if ($existingValue !== null && $existingValue !== '') {
            return (int)$existingValue ? 1 : 0;
        }

        return null;
    }

    private function autoMatchMember(array $prepared, array $memberIndexes) {
        $match = (new MemberMatchingService($this->db))->matchPerson([
            'first_name' => $prepared['first_name'],
            'last_name' => $prepared['last_name'],
            'email' => $prepared['email'] ?? '',
            'mobile' => $prepared['mobile'] ?? '',
            'phone' => $prepared['phone'] ?? '',
            'churchsuite_person_type' => $prepared['source_person_type'] ?? '',
            'churchsuite_person_id' => $prepared['source_person_id'] ?? ''
        ], $memberIndexes);

        if ($match['status'] === 'matched' && !empty($match['member'])) {
            return [
                'matched_member_id' => (int)$match['member']['id'],
                'status' => 'Matched',
                'match_source' => $match['source'],
                'match_note' => null
            ];
        }

        if ($match['status'] === 'review') {
            $candidateNames = array_map(function ($row) {
                return trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? '')) . ' (ID ' . (int)$row['id'] . ')';
            }, $match['candidates'] ?? []);
            $candidateNames = array_filter(array_map('trim', $candidateNames));

            return [
                'matched_member_id' => null,
                'status' => 'Needs Review',
                'match_source' => $match['source'],
                'match_note' => $candidateNames
                    ? 'Multiple possible matches via ' . $match['source'] . ': ' . implode(', ', $candidateNames)
                    : 'Needs review before matching.'
            ];
        }

        return [
            'matched_member_id' => null,
            'status' => 'Unmatched',
            'match_source' => null,
            'match_note' => null
        ];
    }

    private function deriveStatus($matchedMemberId, $baseStatus, $remainingAmount, $alreadyApplied) {
        if ($remainingAmount <= 0 && $alreadyApplied > 0) {
            return 'Applied';
        }
        if ($remainingAmount > 0 && $alreadyApplied > 0) {
            return 'Partial';
        }
        if ($matchedMemberId) {
            return 'Matched';
        }
        return $baseStatus;
    }

    private function saveSyncedRow($campId, array $payload, $existing) {
        if ($existing) {
            if (!$this->payloadHasChanged($existing, $payload)) {
                return false;
            }

            $stmt = $this->db->prepare("
                UPDATE prepayments
                SET imported_name = ?, first_name = ?, last_name = ?, email = ?, mobile = ?, phone = ?, amount = ?, source_amount = ?,
                    transaction_id = ?, date = ?, matched_member_id = ?, match_source = ?, match_note = ?, original_data = ?, status = ?,
                    source_system = ?, source_record_id = ?, source_person_type = ?, source_person_id = ?, source_currency = ?, source_payment_status = ?,
                    source_arrival_date = ?, source_departure_date = ?, source_site_number = ?, source_accommodation_type = ?, source_party_size = ?, source_day_trip = ?,
                    source_synced_at = ?, sync_state = ?, sync_note = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $payload['imported_name'],
                $payload['first_name'],
                $payload['last_name'],
                $payload['email'],
                $payload['mobile'],
                $payload['phone'],
                $payload['amount'],
                $payload['source_amount'],
                $payload['transaction_id'],
                $payload['date'],
                $payload['matched_member_id'],
                $payload['match_source'],
                $payload['match_note'],
                $payload['original_data'],
                $payload['status'],
                $payload['source_system'],
                $payload['source_record_id'],
                $payload['source_person_type'],
                $payload['source_person_id'],
                $payload['source_currency'],
                $payload['source_payment_status'],
                $payload['source_arrival_date'],
                $payload['source_departure_date'],
                $payload['source_site_number'],
                $payload['source_accommodation_type'],
                $payload['source_party_size'],
                $payload['source_day_trip'],
                $payload['source_synced_at'],
                $payload['sync_state'],
                $payload['sync_note'],
                $existing['id']
            ]);
            return true;
        }

        $stmt = $this->db->prepare("
            INSERT INTO prepayments (
                camp_id, imported_name, first_name, last_name, email, mobile, phone, amount, source_amount, transaction_id, date,
                matched_member_id, match_source, match_note, original_data, status, source_system, source_record_id,
                source_person_type, source_person_id, source_currency, source_payment_status, source_arrival_date, source_departure_date,
                source_site_number, source_accommodation_type, source_party_size, source_day_trip, source_synced_at, sync_state, sync_note
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            (int)$campId,
            $payload['imported_name'],
            $payload['first_name'],
            $payload['last_name'],
            $payload['email'],
            $payload['mobile'],
            $payload['phone'],
            $payload['amount'],
            $payload['source_amount'],
            $payload['transaction_id'],
            $payload['date'],
            $payload['matched_member_id'],
            $payload['match_source'],
            $payload['match_note'],
            $payload['original_data'],
            $payload['status'],
            $payload['source_system'],
            $payload['source_record_id'],
            $payload['source_person_type'],
            $payload['source_person_id'],
            $payload['source_currency'],
            $payload['source_payment_status'],
            $payload['source_arrival_date'],
            $payload['source_departure_date'],
            $payload['source_site_number'],
            $payload['source_accommodation_type'],
            $payload['source_party_size'],
            $payload['source_day_trip'],
            $payload['source_synced_at'],
            $payload['sync_state'],
            $payload['sync_note']
        ]);
        return true;
    }

    private function payloadHasChanged(array $existing, array $payload) {
        $compareKeys = [
            'imported_name',
            'first_name',
            'last_name',
            'email',
            'mobile',
            'phone',
            'amount',
            'source_amount',
            'transaction_id',
            'date',
            'matched_member_id',
            'match_source',
            'match_note',
            'original_data',
            'status',
            'source_system',
            'source_record_id',
            'source_person_type',
            'source_person_id',
            'source_currency',
            'source_payment_status',
            'source_arrival_date',
            'source_departure_date',
            'source_site_number',
            'source_accommodation_type',
            'source_party_size',
            'source_day_trip',
            'source_synced_at',
            'sync_state',
            'sync_note'
        ];

        foreach ($compareKeys as $key) {
            $current = $existing[$key] ?? null;
            $next = $payload[$key] ?? null;

            if (in_array($key, ['amount', 'source_amount'], true)) {
                if (round((float)$current, 2) !== round((float)$next, 2)) {
                    return true;
                }
                continue;
            }

            if ((string)$current !== (string)$next) {
                return true;
            }
        }

        return false;
    }

    private function writeCampSyncMetadata($campId, $timestamp, $status, $message, array $event, array $camp) {
        $eventId = !empty($event['id']) ? (int)$event['id'] : null;
        $identifier = trim((string)($event['identifier'] ?? ($camp['churchsuite_event_identifier'] ?? '')));
        $eventName = trim((string)($event['name'] ?? ($camp['churchsuite_event_name'] ?? '')));

        $stmt = $this->db->prepare("
            UPDATE camps
            SET churchsuite_event_id = ?, churchsuite_event_identifier = ?, churchsuite_event_name = ?,
                churchsuite_last_sync_at = ?, churchsuite_last_sync_status = ?, churchsuite_last_sync_message = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $eventId,
            $identifier !== '' ? $identifier : null,
            $eventName !== '' ? $eventName : null,
            $timestamp,
            $status,
            $message,
            (int)$campId
        ]);

        $camp['churchsuite_event_id'] = $eventId;
        $camp['churchsuite_event_identifier'] = $identifier !== '' ? $identifier : null;
        $camp['churchsuite_event_name'] = $eventName !== '' ? $eventName : null;
        $camp['churchsuite_last_sync_at'] = $timestamp;
        $camp['churchsuite_last_sync_status'] = $status;
        $camp['churchsuite_last_sync_message'] = $message;

        return $camp;
    }

    private function buildSuccessMessage(array $summary) {
        return sprintf(
            'ChurchSuite sync finished. %d created, %d updated, %d unchanged, %d unpaid skipped, %d zeroed.',
            $summary['created'],
            $summary['updated'],
            $summary['unchanged'],
            $summary['skipped_unpaid'],
            $summary['zeroed']
        );
    }

    private function recordSyncFailure($campId, $timestamp, $message, $event = null) {
        $message = trim((string)$message);
        if ($message === '') {
            $message = 'ChurchSuite sync failed.';
        }

        try {
            $params = [
                $timestamp,
                'error',
                $message,
                (int)$campId
            ];

            if (is_array($event) && !empty($event['id'])) {
                $stmt = $this->db->prepare("
                    UPDATE camps
                    SET churchsuite_event_id = ?, churchsuite_event_identifier = ?, churchsuite_event_name = ?,
                        churchsuite_last_sync_at = ?, churchsuite_last_sync_status = ?, churchsuite_last_sync_message = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    (int)$event['id'],
                    trim((string)($event['identifier'] ?? '')) ?: null,
                    trim((string)($event['name'] ?? '')) ?: null,
                    $timestamp,
                    'error',
                    $message,
                    (int)$campId
                ]);
                return;
            }

            $stmt = $this->db->prepare("
                UPDATE camps
                SET churchsuite_last_sync_at = ?, churchsuite_last_sync_status = ?, churchsuite_last_sync_message = ?
                WHERE id = ?
            ");
            $stmt->execute($params);
        } catch (Exception $updateError) {
            error_log('ChurchSuite sync metadata update failed campId=' . (int)$campId . ' error=' . $updateError->getMessage());
        }
    }
}
