<?php

require_once __DIR__ . '/MemberMatchingService.php';

class MemberHouseholdService {
    private $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    private function householdSourcePrioritySql($column = 'source_system') {
        return "CASE
            WHEN {$column} = 'manual' THEN 0
            WHEN {$column} = 'churchsuite' THEN 1
            ELSE 2
        END";
    }

    private function currentSiteSummaryJoinSql($memberColumn, $alias = 'ms') {
        return "
            LEFT JOIN (
                SELECT
                    sa.member_id,
                    COUNT(DISTINCT sa.site_id) AS current_site_count,
                    CASE
                        WHEN COUNT(DISTINCT sa.site_id) = 1 THEN MAX(s.id)
                        ELSE NULL
                    END AS site_id,
                    CASE
                        WHEN COUNT(DISTINCT sa.site_id) = 1 THEN MAX(s.site_number)
                        ELSE NULL
                    END AS site_number,
                    CASE
                        WHEN COUNT(DISTINCT sa.site_id) = 1 THEN MAX(s.site_type)
                        ELSE NULL
                    END AS site_type
                FROM site_allocations sa
                LEFT JOIN sites s ON s.id = sa.site_id
                WHERE sa.is_current = 1
                GROUP BY sa.member_id
            ) {$alias} ON {$alias}.member_id = {$memberColumn}
        ";
    }

    public function getMemberHouseholdId($memberId) {
        $stmt = $this->db->prepare("
            SELECT household_id
            FROM (
                SELECT
                    mhm.household_id,
                    mhm.source_system,
                    mhm.is_primary,
                    COALESCE(hc.member_count, 0) AS member_count
                FROM member_household_members mhm
                LEFT JOIN (
                    SELECT household_id, COUNT(*) AS member_count
                    FROM member_household_members
                    GROUP BY household_id
                ) hc ON hc.household_id = mhm.household_id
                WHERE mhm.member_id = ?
            ) ranked
            ORDER BY
                " . $this->householdSourcePrioritySql('source_system') . ",
                member_count DESC,
                is_primary DESC,
                household_id ASC
            LIMIT 1
        ");
        $stmt->execute([(int)$memberId]);
        $value = $stmt->fetchColumn();
        return $value ? (int)$value : null;
    }

    public function getHouseholdForMember($memberId) {
        $householdId = $this->getMemberHouseholdId($memberId);
        if (!$householdId) {
            return null;
        }
        return $this->getHouseholdDetail($householdId);
    }

    public function ensureLocalHouseholdForMember($memberId) {
        $memberId = (int)$memberId;
        $existingId = $this->getMemberHouseholdId($memberId);
        if ($existingId) {
            return $this->getHouseholdDetail($existingId);
        }

        $stmt = $this->db->prepare("SELECT id, first_name, last_name FROM members WHERE id = ? LIMIT 1");
        $stmt->execute([$memberId]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$member) {
            throw new RuntimeException('Member not found.');
        }

        $displayName = trim((string)($member['first_name'] ?? '') . ' ' . (string)($member['last_name'] ?? ''));
        if ($displayName === '') {
            $displayName = 'Member Household';
        }

        $insertHousehold = $this->db->prepare("
            INSERT INTO member_households (
                source_system,
                source_household_key,
                display_name,
                insurance_status,
                agreement_status
            ) VALUES ('local', ?, ?, 'Unknown', 'Not Signed')
        ");
        $insertHousehold->execute([
            'member:' . $memberId,
            $displayName
        ]);
        $householdId = (int)$this->db->lastInsertId();

        $insertMember = $this->db->prepare("
            INSERT INTO member_household_members (
                household_id,
                member_id,
                role_label,
                is_primary,
                source_system
            ) VALUES (?, ?, 'Member', 1, 'local')
        ");
        $insertMember->execute([$householdId, $memberId]);

        return $this->getHouseholdDetail($householdId);
    }

    public function removeNonChurchSuiteMembershipsForMember($memberId) {
        $stmt = $this->db->prepare("
            DELETE FROM member_household_members
            WHERE member_id = ?
              AND source_system <> 'churchsuite'
        ");
        $stmt->execute([(int)$memberId]);
        $this->cleanupOrphanNonChurchSuiteHouseholds();
    }

    public function assignMemberToHousehold($memberId, $householdId, array $options = []) {
        $memberId = (int)$memberId;
        $householdId = (int)$householdId;
        if ($memberId <= 0 || $householdId <= 0) {
            throw new RuntimeException('A valid member and household are required.');
        }

        $sourceSystem = trim((string)($options['source_system'] ?? 'manual'));
        if (!in_array($sourceSystem, ['manual', 'local'], true)) {
            $sourceSystem = 'manual';
        }

        if (!empty($options['clear_existing'])) {
            $this->removeNonChurchSuiteMembershipsForMember($memberId);
        }

        $memberCheck = $this->db->prepare("SELECT id FROM members WHERE id = ? LIMIT 1");
        $memberCheck->execute([$memberId]);
        if (!$memberCheck->fetchColumn()) {
            throw new RuntimeException('Member not found.');
        }

        $householdCheck = $this->db->prepare("SELECT id FROM member_households WHERE id = ? LIMIT 1");
        $householdCheck->execute([$householdId]);
        if (!$householdCheck->fetchColumn()) {
            throw new RuntimeException('Household not found.');
        }

        $roleLabel = trim((string)($options['role_label'] ?? 'Member')) ?: 'Member';
        $isPrimary = array_key_exists('is_primary', $options)
            ? (!empty($options['is_primary']) ? 1 : 0)
            : ($this->householdHasPrimaryMember($householdId) ? 0 : 1);

        $existingStmt = $this->db->prepare("
            SELECT id
            FROM member_household_members
            WHERE household_id = ? AND member_id = ? AND source_system = ?
            LIMIT 1
        ");
        $existingStmt->execute([$householdId, $memberId, $sourceSystem]);
        $existingId = $existingStmt->fetchColumn();

        if ($existingId) {
            $updateStmt = $this->db->prepare("
                UPDATE member_household_members
                SET role_label = ?, is_primary = ?
                WHERE id = ?
            ");
            $updateStmt->execute([$roleLabel, $isPrimary, (int)$existingId]);
        } else {
            $insertStmt = $this->db->prepare("
                INSERT INTO member_household_members (
                    household_id,
                    member_id,
                    role_label,
                    is_primary,
                    source_system
                ) VALUES (?, ?, ?, ?, ?)
            ");
            $insertStmt->execute([$householdId, $memberId, $roleLabel, $isPrimary, $sourceSystem]);
        }

        $this->cleanupOrphanNonChurchSuiteHouseholds();
        return $this->getHouseholdDetail($householdId);
    }

    public function createManualHousehold($displayName, $memberId = null, array $options = []) {
        $displayName = trim((string)$displayName);
        if ($displayName === '' && $memberId) {
            $memberStmt = $this->db->prepare("SELECT first_name, last_name FROM members WHERE id = ? LIMIT 1");
            $memberStmt->execute([(int)$memberId]);
            $member = $memberStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $displayName = trim((string)($member['first_name'] ?? '') . ' ' . (string)($member['last_name'] ?? ''));
        }
        if ($displayName === '') {
            $displayName = 'New Household';
        }

        $sourceHouseholdKey = trim((string)($options['source_household_key'] ?? ''));
        if ($sourceHouseholdKey === '') {
            $sourceHouseholdKey = 'manual:' . bin2hex(random_bytes(8));
        }

        $insertHousehold = $this->db->prepare("
            INSERT INTO member_households (
                source_system,
                source_household_key,
                display_name,
                insurance_status,
                agreement_status
            ) VALUES ('manual', ?, ?, 'Unknown', 'Not Signed')
        ");
        $insertHousehold->execute([$sourceHouseholdKey, $displayName]);
        $householdId = (int)$this->db->lastInsertId();

        if ($memberId) {
            $this->assignMemberToHousehold((int)$memberId, $householdId, [
                'clear_existing' => !empty($options['clear_existing']),
                'role_label' => $options['role_label'] ?? 'Member',
                'is_primary' => $options['is_primary'] ?? true,
                'source_system' => 'manual'
            ]);
        }

        return $this->getHouseholdDetail($householdId);
    }

    public function getHouseholdDetail($householdId) {
        $stmt = $this->db->prepare("
            SELECT *
            FROM member_households
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([(int)$householdId]);
        $household = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$household) {
            return null;
        }

        $membersStmt = $this->db->prepare("
            SELECT
                m.id,
                m.first_name,
                m.last_name,
                m.email,
                m.mobile,
                m.phone,
                m.churchsuite_sync_status,
                mhm.role_label,
                mhm.is_primary,
                NULL AS current_site_count,
                NULL AS site_id,
                NULL AS site_number,
                NULL AS site_type,
                NULL AS site_fee_paid_until
            FROM member_household_members mhm
            JOIN members m ON m.id = mhm.member_id
            WHERE mhm.household_id = ?
            ORDER BY mhm.is_primary DESC, m.last_name ASC, m.first_name ASC
        ");
        $membersStmt->execute([(int)$householdId]);
        $members = $membersStmt->fetchAll(PDO::FETCH_ASSOC);

        $documentsStmt = $this->db->prepare("
            SELECT id, file_path, original_name, mime_type, source_type, signed_at, uploaded_at
            FROM household_agreement_documents
            WHERE household_id = ? AND is_active = 1
            ORDER BY uploaded_at DESC, id DESC
        ");
        $documentsStmt->execute([(int)$householdId]);
        $documents = $documentsStmt->fetchAll(PDO::FETCH_ASSOC);

        $siteSummary = $this->deriveHouseholdSiteSummary($members);
        foreach ($siteSummary as $key => $value) {
            $household[$key] = $value;
        }

        return [
            'household' => $household,
            'members' => $members,
            'documents' => $documents
        ];
    }

    public function getHouseholdSummaryMap() {
        $rows = $this->db->query("
            SELECT
                mh.id,
                mh.display_name,
                mh.insurance_status,
                mh.agreement_status,
                mh.agreement_source,
                mh.agreement_signed_at,
                COUNT(mhm.member_id) AS member_count
            FROM member_households mh
            LEFT JOIN member_household_members mhm ON mhm.household_id = mh.id
            GROUP BY mh.id, mh.display_name, mh.insurance_status, mh.agreement_status, mh.agreement_source, mh.agreement_signed_at
        ")->fetchAll(PDO::FETCH_ASSOC);

        $mapped = [];
        foreach ($rows as $row) {
            $mapped[(int)$row['id']] = $row;
        }
        return $mapped;
    }

    public function getHouseholdAvailablePrepayments($memberId, $campId = null) {
        $householdId = $this->getMemberHouseholdId($memberId);
        if (!$householdId) {
            $sql = "
                SELECT
                    p.*,
                    owner.id AS owner_member_id,
                    owner.first_name AS owner_first_name,
                    owner.last_name AS owner_last_name
                FROM prepayments p
                LEFT JOIN members owner ON owner.id = p.matched_member_id
                WHERE p.matched_member_id = ?
                  AND COALESCE(p.amount, 0) > 0
            ";
            $params = [(int)$memberId];
            if ($campId) {
                $sql .= " AND p.camp_id = ?";
                $params[] = (int)$campId;
            }
            $sql .= " ORDER BY p.id DESC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $sql = "
            SELECT
                p.*,
                owner.id AS owner_member_id,
                owner.first_name AS owner_first_name,
                owner.last_name AS owner_last_name
            FROM member_household_members mhm
            JOIN members owner ON owner.id = mhm.member_id
            JOIN prepayments p ON p.matched_member_id = owner.id
            WHERE mhm.household_id = ?
              AND COALESCE(p.amount, 0) > 0
        ";
        $params = [(int)$householdId];

        if ($campId) {
            $sql .= " AND p.camp_id = ?";
            $params[] = (int)$campId;
        }

        $sql .= " ORDER BY CASE WHEN owner.id = ? THEN 0 ELSE 1 END, owner.last_name ASC, owner.first_name ASC, p.id DESC";
        $params[] = (int)$memberId;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function recalculateAgreementStatus($householdId) {
        $stmt = $this->db->prepare("
            SELECT
                MAX(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS has_docs
            FROM household_agreement_documents
            WHERE household_id = ?
        ");
        $stmt->execute([(int)$householdId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $householdStmt = $this->db->prepare("SELECT digital_agreement_confirmed, agreement_source FROM member_households WHERE id = ? LIMIT 1");
        $householdStmt->execute([(int)$householdId]);
        $household = $householdStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $hasDocs = !empty($row['has_docs']);
        $hasDigital = !empty($household['digital_agreement_confirmed']);
        $source = null;
        $status = 'Not Signed';

        if ($hasDocs && $hasDigital) {
            $status = 'Signed';
            $source = 'mixed';
        } elseif ($hasDocs) {
            $status = 'Signed';
            $source = 'paper';
        } elseif ($hasDigital) {
            $status = 'Signed';
            $source = 'digital';
        }

        $update = $this->db->prepare("
            UPDATE member_households
            SET agreement_status = ?, agreement_source = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $update->execute([$status, $source, (int)$householdId]);
    }

    public function rebuildChurchSuiteStructures(array $extraRelations = []) {
        $members = $this->db->query("
            SELECT
                id,
                first_name,
                last_name,
                churchsuite_person_type,
                churchsuite_person_id,
                churchsuite_payload_json
            FROM members
            WHERE churchsuite_person_id IS NOT NULL AND churchsuite_person_id <> ''
        ")->fetchAll(PDO::FETCH_ASSOC);

        $byExternal = [];
        foreach ($members as $member) {
            $externalKey = MemberMatchingService::churchsuiteExternalKey($member['churchsuite_person_type'] ?? '', $member['churchsuite_person_id'] ?? '');
            if ($externalKey !== '') {
                $member['external_key'] = $externalKey;
                $byExternal[$externalKey] = $member;
            }
        }

        $this->db->prepare("DELETE FROM member_relationships WHERE source_system = 'churchsuite'")->execute();
        $this->db->prepare("DELETE FROM member_household_members WHERE source_system = 'churchsuite'")->execute();

        if (!$byExternal) {
            return;
        }

        $adjacency = [];
        $edges = [];
        $householdSeedByMember = [];
        $membersByHouseholdSeed = [];

        foreach ($byExternal as $externalKey => $member) {
            $adjacency[$externalKey] = $adjacency[$externalKey] ?? [];
            $payload = json_decode((string)($member['churchsuite_payload_json'] ?? ''), true);
            if (!is_array($payload)) {
                continue;
            }

            $householdSeed = $this->extractHouseholdSourceKey($payload, $externalKey);
            if ($householdSeed !== '') {
                $householdSeedByMember[$externalKey] = $householdSeed;
                $membersByHouseholdSeed[$householdSeed] = $membersByHouseholdSeed[$householdSeed] ?? [];
                $membersByHouseholdSeed[$householdSeed][$externalKey] = true;
            }

            foreach ($this->extractRelationsFromPayload($payload, $externalKey) as $relation) {
                $relatedKey = $relation['related_key'];
                if ($relatedKey === '' || !isset($byExternal[$relatedKey])) {
                    continue;
                }
                $adjacency[$externalKey][$relatedKey] = true;
                $adjacency[$relatedKey][$externalKey] = true;

                $edgeKey = $externalKey . '|' . $relatedKey . '|' . $relation['type'];
                $edges[$edgeKey] = [
                    'member_id' => (int)$member['id'],
                    'related_member_id' => (int)$byExternal[$relatedKey]['id'],
                    'relationship_type' => $relation['type']
                ];
            }
        }

        foreach ($membersByHouseholdSeed as $seed => $groupMembers) {
            $externalKeys = array_values(array_unique(array_keys($groupMembers)));
            if (count($externalKeys) <= 1) {
                continue;
            }

            $anchor = array_shift($externalKeys);
            foreach ($externalKeys as $relatedKey) {
                $adjacency[$anchor][$relatedKey] = true;
                $adjacency[$relatedKey][$anchor] = true;
            }
        }

        foreach ($extraRelations as $relation) {
            $memberKey = trim((string)($relation['member_key'] ?? ''));
            $relatedKey = trim((string)($relation['related_key'] ?? ''));
            $type = trim((string)($relation['type'] ?? 'parent_child'));

            if ($memberKey === '' || $relatedKey === '') {
                continue;
            }
            if (!isset($byExternal[$memberKey]) || !isset($byExternal[$relatedKey])) {
                continue;
            }

            $adjacency[$memberKey] = $adjacency[$memberKey] ?? [];
            $adjacency[$relatedKey] = $adjacency[$relatedKey] ?? [];
            $adjacency[$memberKey][$relatedKey] = true;
            $adjacency[$relatedKey][$memberKey] = true;

            $edgeKey = $memberKey . '|' . $relatedKey . '|' . $type;
            $edges[$edgeKey] = [
                'member_id' => (int)$byExternal[$memberKey]['id'],
                'related_member_id' => (int)$byExternal[$relatedKey]['id'],
                'relationship_type' => $type
            ];
        }

        // Last-name heuristic: link isolated children to adults sharing the same last name.
        // Only fires when the child has no adjacency edges at all (truly orphaned in CS data).
        $lastNameByKey = [];
        foreach ($byExternal as $extKey => $member) {
            $ln = strtolower(trim((string)($member['last_name'] ?? '')));
            if ($ln !== '') {
                $lastNameByKey[$extKey] = $ln;
            }
        }
        $adultsByLastName = [];
        foreach ($byExternal as $extKey => $member) {
            if (($member['churchsuite_person_type'] ?? '') === 'contact' && isset($lastNameByKey[$extKey])) {
                $adultsByLastName[$lastNameByKey[$extKey]][] = $extKey;
            }
        }
        foreach ($byExternal as $extKey => $member) {
            if (($member['churchsuite_person_type'] ?? '') !== 'child') continue;
            if (!empty($adjacency[$extKey])) continue; // already connected
            $ln = $lastNameByKey[$extKey] ?? '';
            if ($ln === '' || empty($adultsByLastName[$ln])) continue;
            foreach ($adultsByLastName[$ln] as $adultKey) {
                $adjacency[$extKey][$adultKey] = true;
                $adjacency[$adultKey][$extKey] = true;
                $edgeKey = $extKey . '|' . $adultKey . '|parent_child';
                $edges[$edgeKey] = [
                    'member_id'        => (int)$member['id'],
                    'related_member_id' => (int)$byExternal[$adultKey]['id'],
                    'relationship_type' => 'parent_child'
                ];
            }
        }

        foreach ($edges as $edge) {
            $stmt = $this->db->prepare("
                INSERT INTO member_relationships (member_id, related_member_id, relationship_type, source_system)
                VALUES (?, ?, ?, 'churchsuite')
            ");
            $stmt->execute([
                (int)$edge['member_id'],
                (int)$edge['related_member_id'],
                $edge['relationship_type']
            ]);
        }

        $visited = [];
        foreach (array_keys($byExternal) as $externalKey) {
            if (isset($visited[$externalKey])) {
                continue;
            }

            $component = [];
            $queue = [$externalKey];
            while ($queue) {
                $current = array_shift($queue);
                if (isset($visited[$current])) {
                    continue;
                }
                $visited[$current] = true;
                $component[] = $current;

                foreach (array_keys($adjacency[$current] ?? []) as $neighbor) {
                    if (!isset($visited[$neighbor])) {
                        $queue[] = $neighbor;
                    }
                }
            }

            sort($component, SORT_STRING);
            $sourceHouseholdKey = $this->componentHouseholdKey($component, $householdSeedByMember);
            $householdId = $this->upsertChurchSuiteHousehold($sourceHouseholdKey, $component, $byExternal);

            $memberStmt = $this->db->prepare("
                INSERT INTO member_household_members (household_id, member_id, role_label, is_primary, source_system)
                VALUES (?, ?, ?, ?, 'churchsuite')
            ");

            $primaryMemberId = (int)$byExternal[$component[0]]['id'];
            foreach ($component as $componentKey) {
                $member = $byExternal[$componentKey];
                $memberStmt->execute([
                    (int)$householdId,
                    (int)$member['id'],
                    $this->roleLabelForMember($member),
                    (int)((int)$member['id'] === $primaryMemberId)
                ]);
            }
        }

        $this->db->exec("
            DELETE mh
            FROM member_households mh
            LEFT JOIN member_household_members mhm ON mhm.household_id = mh.id
            WHERE mh.source_system = 'churchsuite' AND mhm.id IS NULL
        ");
    }

    private function householdHasPrimaryMember($householdId) {
        $stmt = $this->db->prepare("
            SELECT id
            FROM member_household_members
            WHERE household_id = ? AND is_primary = 1
            LIMIT 1
        ");
        $stmt->execute([(int)$householdId]);
        return (bool)$stmt->fetchColumn();
    }

    private function cleanupOrphanNonChurchSuiteHouseholds() {
        $this->db->exec("
            DELETE mh
            FROM member_households mh
            LEFT JOIN member_household_members mhm ON mhm.household_id = mh.id
            WHERE mh.source_system <> 'churchsuite'
              AND mhm.id IS NULL
        ");
    }

    private function upsertChurchSuiteHousehold($sourceHouseholdKey, array $component, array $byExternal) {
        $label = $this->householdLabel($component, $byExternal);

        $stmt = $this->db->prepare("
            SELECT id
            FROM member_households
            WHERE source_system = 'churchsuite' AND source_household_key = ?
            LIMIT 1
        ");
        $stmt->execute([$sourceHouseholdKey]);
        $existingId = $stmt->fetchColumn();

        if ($existingId) {
            $update = $this->db->prepare("
                UPDATE member_households
                SET display_name = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $update->execute([$label, (int)$existingId]);
            return (int)$existingId;
        }

        $insert = $this->db->prepare("
            INSERT INTO member_households (
                source_system,
                source_household_key,
                display_name,
                insurance_status,
                agreement_status
            ) VALUES ('churchsuite', ?, ?, 'Unknown', 'Not Signed')
        ");
        $insert->execute([$sourceHouseholdKey, $label]);
        return (int)$this->db->lastInsertId();
    }

    private function householdLabel(array $component, array $byExternal) {
        $lead = $this->preferredHouseholdLead($component, $byExternal);
        if ($lead) {
            return $lead;
        }

        foreach ($component as $externalKey) {
            $member = $byExternal[$externalKey] ?? null;
            if (!$member) {
                continue;
            }
            $label = trim((string)($member['first_name'] ?? '') . ' ' . (string)($member['last_name'] ?? ''));
            if ($label !== '') {
                return $label;
            }
        }

        return 'ChurchSuite Household';
    }

    private function componentHouseholdKey(array $component, array $householdSeedByMember) {
        $seeds = [];
        foreach ($component as $externalKey) {
            if (!empty($householdSeedByMember[$externalKey])) {
                $seeds[] = $householdSeedByMember[$externalKey];
            }
        }
        if ($seeds) {
            sort($seeds, SORT_STRING);
            return 'household:' . $seeds[0];
        }
        return 'component:' . $component[0];
    }

    private function extractHouseholdSourceKey(array $payload, $fallbackExternalKey) {
        $candidates = [
            $payload['household_id'] ?? null,
            $payload['household_key'] ?? null,
            $payload['household_uid'] ?? null,
            $payload['linked_household_id'] ?? null,
            $payload['addressbook_household_id'] ?? null,
            $payload['address_book_household_id'] ?? null,
            $payload['household']['id'] ?? null,
            $payload['household']['identifier'] ?? null,
            $payload['household']['key'] ?? null,
            $payload['household']['slug'] ?? null,
            $payload['household']['uuid'] ?? null,
            $payload['family_id'] ?? null,
            $payload['family']['id'] ?? null
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    private function extractRelationsFromPayload(array $payload, $currentKey) {
        $relations = [];

        foreach ($this->extractRelatedKeys($payload['spouse'] ?? null, 'contact') as $key) {
            if ($key !== $currentKey) {
                $relations[] = ['type' => 'partner', 'related_key' => $key];
            }
        }
        foreach ($this->extractRelatedKeys($payload['spouse_id'] ?? null, 'contact') as $key) {
            if ($key !== $currentKey) {
                $relations[] = ['type' => 'partner', 'related_key' => $key];
            }
        }
        foreach ($this->extractRelatedKeys($payload['partner'] ?? null, 'contact') as $key) {
            if ($key !== $currentKey) {
                $relations[] = ['type' => 'partner', 'related_key' => $key];
            }
        }
        foreach ($this->extractRelatedKeys($payload['partner_id'] ?? null, 'contact') as $key) {
            if ($key !== $currentKey) {
                $relations[] = ['type' => 'partner', 'related_key' => $key];
            }
        }
        foreach ($this->extractRelatedKeys($payload['spouse_partner'] ?? null, 'contact') as $key) {
            if ($key !== $currentKey) {
                $relations[] = ['type' => 'partner', 'related_key' => $key];
            }
        }
        foreach ($this->extractRelatedKeys($payload['linked_contacts'] ?? ($payload['household_members'] ?? null), 'contact') as $key) {
            if ($key !== $currentKey) {
                $relations[] = ['type' => 'household', 'related_key' => $key];
            }
        }
        foreach ($this->extractRelatedKeys($payload['household']['members'] ?? ($payload['household']['contacts'] ?? null), 'contact') as $key) {
            if ($key !== $currentKey) {
                $relations[] = ['type' => 'household', 'related_key' => $key];
            }
        }
        foreach ($this->extractRelatedKeys($payload['children'] ?? ($payload['linked_children'] ?? null), 'child') as $key) {
            if ($key !== $currentKey) {
                $relations[] = ['type' => 'parent_child', 'related_key' => $key];
            }
        }
        foreach ($this->extractRelatedKeys($payload['parents'] ?? ($payload['carers'] ?? null), 'contact') as $key) {
            if ($key !== $currentKey) {
                $relations[] = ['type' => 'parent_child', 'related_key' => $key];
            }
        }
        foreach ($this->extractRelatedKeys($payload['parents_carers'] ?? ($payload['parent_carers'] ?? null), 'contact') as $key) {
            if ($key !== $currentKey) {
                $relations[] = ['type' => 'parent_child', 'related_key' => $key];
            }
        }
        foreach ($this->extractRelatedKeys($payload['other_parents'] ?? ($payload['other_parent_carers'] ?? null), 'contact') as $key) {
            if ($key !== $currentKey) {
                $relations[] = ['type' => 'parent_child', 'related_key' => $key];
            }
        }
        foreach ($this->extractRelatedKeys($payload['parent'] ?? null, 'contact') as $key) {
            if ($key !== $currentKey) {
                $relations[] = ['type' => 'parent_child', 'related_key' => $key];
            }
        }

        $unique = [];
        foreach ($relations as $relation) {
            $relationKey = $relation['type'] . '|' . $relation['related_key'];
            $unique[$relationKey] = $relation;
        }

        return array_values($unique);
    }

    private function extractRelatedKeys($value, $defaultType) {
        $keys = [];
        if ($value === null || $value === '') {
            return $keys;
        }

        if (is_array($value)) {
            $isAssoc = array_keys($value) !== range(0, count($value) - 1);
            if ($isAssoc) {
                $key = $this->relatedKeyFromNode($value, $defaultType);
                if ($key !== '') {
                    $keys[] = $key;
                }
                foreach ($value as $nested) {
                    foreach ($this->extractRelatedKeys($nested, $defaultType) as $nestedKey) {
                        $keys[] = $nestedKey;
                    }
                }
            } else {
                foreach ($value as $item) {
                    foreach ($this->extractRelatedKeys($item, $defaultType) as $nestedKey) {
                        $keys[] = $nestedKey;
                    }
                }
            }
        } elseif (is_numeric($value) || is_string($value)) {
            $key = MemberMatchingService::churchsuiteExternalKey($defaultType, $value);
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return array_values(array_unique(array_filter($keys)));
    }

    private function relatedKeyFromNode(array $node, $defaultType) {
        $type = strtolower(trim((string)($node['type'] ?? $node['module'] ?? $defaultType)));
        if ($type === '') {
            $type = $defaultType;
        }

        if (strpos($type, 'child') !== false) {
            $type = 'child';
        } elseif (strpos($type, 'contact') !== false || strpos($type, 'person') !== false || strpos($type, 'adult') !== false) {
            $type = 'contact';
        }

        $candidateId = $node['id'] ?? $node['contact_id'] ?? $node['child_id'] ?? $node['person_id'] ?? null;
        return MemberMatchingService::churchsuiteExternalKey($type, $candidateId);
    }

    private function roleLabelForMember(array $member) {
        $type = strtolower(trim((string)($member['churchsuite_person_type'] ?? '')));
        if ($type === 'child') {
            return 'Child';
        }
        return 'Member';
    }

    private function deriveHouseholdSiteSummary(array $members) {
        $sites = [];
        $hasMemberWithMultipleSites = false;
        $paidUntil = null;

        foreach ($members as $member) {
            if ((int)($member['current_site_count'] ?? 0) > 1) {
                $hasMemberWithMultipleSites = true;
            }
            $siteId = (int)($member['site_id'] ?? 0);
            if ($siteId > 0) {
                $sites[$siteId] = [
                    'site_number' => $member['site_number'] ?? null,
                    'site_type' => $member['site_type'] ?? null
                ];
            }

            $memberPaidUntil = trim((string)($member['site_fee_paid_until'] ?? ''));
            if ($memberPaidUntil !== '' && ($paidUntil === null || strcmp($memberPaidUntil, $paidUntil) > 0)) {
                $paidUntil = $memberPaidUntil;
            }
        }

        $siteCount = count($sites);
        if ($hasMemberWithMultipleSites) {
            $siteCount = max($siteCount, 2);
        }
        $singleSite = $siteCount === 1 ? array_values($sites)[0] : null;

        return [
            'site_count' => $siteCount,
            'has_multiple_sites' => $siteCount > 1 ? 1 : 0,
            'site_number' => $singleSite['site_number'] ?? null,
            'site_type' => $singleSite['site_type'] ?? null,
            'site_fee_paid_until' => $siteCount === 1 ? $paidUntil : null
        ];
    }

    private function preferredHouseholdLead(array $component, array $byExternal) {
        $preferred = [];

        foreach ($component as $externalKey) {
            $member = $byExternal[$externalKey] ?? null;
            if (!$member) {
                continue;
            }

            $payload = json_decode((string)($member['churchsuite_payload_json'] ?? ''), true);
            $personType = strtolower(trim((string)($member['churchsuite_person_type'] ?? '')));
            $sex = strtolower(trim((string)($payload['sex'] ?? '')));
            $title = strtolower(trim((string)($payload['title'] ?? '')));
            $fullName = trim((string)($member['first_name'] ?? '') . ' ' . (string)($member['last_name'] ?? ''));

            if ($fullName === '') {
                continue;
            }

            $score = 0;
            if ($personType === 'contact') {
                $score += 50;
            }
            if ($sex === 'male' || $sex === 'm') {
                $score += 40;
            }
            if (in_array($title, ['mr', 'bro'], true)) {
                $score += 10;
            }

            $preferred[] = [
                'score' => $score,
                'full_name' => $fullName,
                'member' => $member
            ];
        }

        if (!$preferred) {
            return null;
        }

        usort($preferred, function ($a, $b) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            $lastNameA = strtolower(trim((string)($a['member']['last_name'] ?? '')));
            $lastNameB = strtolower(trim((string)($b['member']['last_name'] ?? '')));
            if ($lastNameA !== $lastNameB) {
                return $lastNameA <=> $lastNameB;
            }

            $firstNameA = strtolower(trim((string)($a['member']['first_name'] ?? '')));
            $firstNameB = strtolower(trim((string)($b['member']['first_name'] ?? '')));
            return $firstNameA <=> $firstNameB;
        });

        return $preferred[0]['full_name'] ?? null;
    }
}
