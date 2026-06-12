<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../MemberHouseholdService.php';
require_once __DIR__ . '/../SiteFeeService.php';

class MemberController {
    private function tableExists(PDO $db, $table) {
        $stmt = $db->query("SHOW TABLES LIKE " . $db->quote($table));
        return $stmt ? (bool)$stmt->fetch(PDO::FETCH_NUM) : false;
    }

    private function columnExists(PDO $db, $table, $column) {
        $stmt = $db->query("SHOW COLUMNS FROM `" . str_replace('`', '``', $table) . "` LIKE " . $db->quote($column));
        return $stmt ? (bool)$stmt->fetch(PDO::FETCH_ASSOC) : false;
    }

    private function householdsEnabled(PDO $db) {
        return $this->tableExists($db, 'member_households') && $this->tableExists($db, 'member_household_members');
    }

    private function normalizeConcession($value) {
        return in_array(strtolower(trim((string)$value)), ['yes', 'y', '1', 'true'], true) ? 'Yes' : 'No';
    }

    private function householdSourcePrioritySql($column = 'mhm.source_system') {
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

    private function upsertSiteFeePaidUntil(PDO $db, $memberId, $paidUntil, $deleteWhenBlank = false) {
        $memberId = (int)$memberId;
        $paidUntil = trim((string)$paidUntil);

        if ($paidUntil === '') {
            if ($deleteWhenBlank) {
                $db->prepare("DELETE FROM site_fee_accounts WHERE member_id = ?")->execute([$memberId]);
            }
            return;
        }

        $paidUntil = substr($paidUntil, 0, 10);
        $check = $db->prepare("SELECT id FROM site_fee_accounts WHERE member_id = ? ORDER BY paid_until DESC LIMIT 1");
        $check->execute([$memberId]);
        $existingId = $check->fetchColumn();

        if ($existingId) {
            $upd = $db->prepare("UPDATE site_fee_accounts SET paid_until = ? WHERE id = ?");
            $upd->execute([$paidUntil, $existingId]);
            return;
        }

        $ins = $db->prepare("INSERT INTO site_fee_accounts (member_id, paid_until, status) VALUES (?, ?, 'Paid')");
        $ins->execute([$memberId, $paidUntil]);
    }

    private function findSiteById(PDO $db, $siteId) {
        $stmt = $db->prepare("SELECT * FROM sites WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$siteId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function findSiteByNumber(PDO $db, $siteNumber) {
        $stmt = $db->prepare("SELECT * FROM sites WHERE LOWER(site_number) = LOWER(?) LIMIT 1");
        $stmt->execute([trim((string)$siteNumber)]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function refreshSiteStatus(PDO $db, $siteId) {
        $siteId = (int)$siteId;
        if ($siteId <= 0) {
            return;
        }

        $countStmt = $db->prepare("SELECT COUNT(*) FROM site_allocations WHERE site_id = ? AND is_current = 1");
        $countStmt->execute([$siteId]);
        $hasOccupants = (int)$countStmt->fetchColumn() > 0;

        $statusStmt = $db->prepare("UPDATE sites SET status = ? WHERE id = ?");
        $statusStmt->execute([$hasOccupants ? 'Allocated' : 'Available', $siteId]);
    }

    private function clearDuplicateCurrentAllocations(PDO $db, $memberId) {
        $memberId = (int)$memberId;
        if ($memberId <= 0) {
            return;
        }

        $stmt = $db->prepare("
            SELECT id, site_id
            FROM site_allocations
            WHERE member_id = ? AND is_current = 1
            ORDER BY id DESC
        ");
        $stmt->execute([$memberId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $keptSiteIds = [];
        foreach ($rows as $row) {
            $allocationId = (int)($row['id'] ?? 0);
            $siteId = (int)($row['site_id'] ?? 0);
            if ($allocationId <= 0 || $siteId <= 0) {
                continue;
            }

            if (!isset($keptSiteIds[$siteId])) {
                $keptSiteIds[$siteId] = true;
                continue;
            }

            $clearStmt = $db->prepare("
                UPDATE site_allocations
                SET is_current = 0, end_date = COALESCE(end_date, CURDATE())
                WHERE id = ?
            ");
            $clearStmt->execute([$allocationId]);
        }
    }

    private function replaceMemberCurrentSite(PDO $db, $memberId, $siteId) {
        $memberId = (int)$memberId;
        $siteId = (int)$siteId;
        if ($memberId <= 0 || $siteId <= 0) {
            return;
        }

        $this->clearDuplicateCurrentAllocations($db, $memberId);

        $currentStmt = $db->prepare("
            SELECT site_id
            FROM site_allocations
            WHERE member_id = ? AND is_current = 1
        ");
        $currentStmt->execute([$memberId]);
        $currentSiteIds = array_map('intval', $currentStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

        $alreadyCurrent = false;
        foreach ($currentSiteIds as $currentSiteId) {
            if ($currentSiteId === $siteId) {
                $alreadyCurrent = true;
                continue;
            }

            $clearStmt = $db->prepare("
                UPDATE site_allocations
                SET is_current = 0, end_date = NOW()
                WHERE member_id = ? AND site_id = ? AND is_current = 1
            ");
            $clearStmt->execute([$memberId, $currentSiteId]);
            $this->refreshSiteStatus($db, $currentSiteId);
        }

        if (!$alreadyCurrent) {
            $insertStmt = $db->prepare("
                INSERT INTO site_allocations (site_id, member_id, start_date, is_current)
                VALUES (?, ?, CURDATE(), 1)
            ");
            $insertStmt->execute([$siteId, $memberId]);
        }

        $this->refreshSiteStatus($db, $siteId);
    }

    private function syncMemberHousehold(PDO $db, $memberId, array $data) {
        if (!$this->householdsEnabled($db)) {
            return;
        }

        $mode = trim((string)($data['household_mode'] ?? ''));
        if ($mode === '' || $mode === 'keep' || $mode === 'none') {
            return;
        }

        $service = new MemberHouseholdService($db);
        if ($mode === 'existing') {
            $householdId = isset($data['household_id']) ? (int)$data['household_id'] : 0;
            if ($householdId <= 0) {
                throw new InvalidArgumentException('Please choose a household.');
            }

            $service->assignMemberToHousehold((int)$memberId, $householdId, [
                'clear_existing' => true,
                'role_label' => 'Member',
                'source_system' => 'manual'
            ]);
            return;
        }

        if ($mode === 'new') {
            $displayName = trim((string)($data['household_name'] ?? ''));
            $service->createManualHousehold($displayName, (int)$memberId, [
                'clear_existing' => true,
                'role_label' => 'Member',
                'is_primary' => true
            ]);
            return;
        }

        throw new InvalidArgumentException('Unknown household option selected.');
    }

    private function syncMemberSite(PDO $db, $memberId, array $data) {
        $mode = trim((string)($data['site_mode'] ?? ''));
        if ($mode === '' || $mode === 'keep' || $mode === 'none') {
            return;
        }

        if ($mode === 'existing') {
            $siteId = isset($data['site_id']) ? (int)$data['site_id'] : 0;
            if ($siteId <= 0) {
                throw new InvalidArgumentException('Please choose an existing site.');
            }

            $site = $this->findSiteById($db, $siteId);
            if (!$site) {
                throw new InvalidArgumentException('The selected site could not be found.');
            }

            $this->replaceMemberCurrentSite($db, (int)$memberId, $siteId);
            return;
        }

        if ($mode === 'new') {
            $siteNumber = trim((string)($data['site_number'] ?? ''));
            if ($siteNumber === '') {
                throw new InvalidArgumentException('Please enter a site number for the new site.');
            }

            if ($this->findSiteByNumber($db, $siteNumber)) {
                throw new InvalidArgumentException('That site already exists. Choose it from existing sites instead.');
            }

            $siteSection = trim((string)($data['site_section'] ?? ''));
            $siteType = trim((string)($data['site_type'] ?? '')) ?: 'Powered';

            $insertSite = $db->prepare("
                INSERT INTO sites (site_number, section, site_type, status)
                VALUES (?, ?, ?, 'Available')
            ");
            $insertSite->execute([$siteNumber, $siteSection, $siteType]);
            $siteId = (int)$db->lastInsertId();

            $this->replaceMemberCurrentSite($db, (int)$memberId, $siteId);
            return;
        }

        throw new InvalidArgumentException('Unknown site option selected.');
    }

    private function fetchSiteFeePaidUntil(PDO $db, $memberId) {
        $stmt = $db->prepare("SELECT MAX(paid_until) AS paid_until FROM site_fee_accounts WHERE member_id = ?");
        $stmt->execute([(int)$memberId]);
        return $stmt->fetchColumn() ?: null;
    }

    private function fetchPendingCheckIn(PDO $db, $memberId, $campId, $householdDetail = null) {
        if (!$campId || !$this->tableExists($db, 'camp_intranet_checkins')) {
            return null;
        }

        $memberId = (int)$memberId;
        $campId = (int)$campId;
        $householdId = !empty($householdDetail['household']['id']) ? (int)$householdDetail['household']['id'] : null;
        $siteIds = [];
        foreach (($householdDetail['members'] ?? []) as $householdMember) {
            $siteId = (int)($householdMember['site_id'] ?? 0);
            if ($siteId > 0) {
                $siteIds[$siteId] = true;
            }
        }
        $siteIds = array_keys($siteIds);
        $singleSiteId = count($siteIds) === 1 ? (int)$siteIds[0] : null;

        $where = ['matched_member_id = ?'];
        $params = [$campId, $memberId];
        if ($householdId) {
            $where[] = 'matched_household_id = ?';
            $params[] = $householdId;
        }
        if ($singleSiteId) {
            $where[] = 'site_id = ?';
            $params[] = $singleSiteId;
        }

        $sql = "
            SELECT *
            FROM camp_intranet_checkins
            WHERE camp_id = ?
              AND status IN ('new', 'in_progress')
              AND (" . implode(' OR ', $where) . ")
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) {
            return null;
        }

        usort($rows, function ($a, $b) use ($memberId, $householdId, $singleSiteId) {
            $rank = function ($row) use ($memberId, $householdId, $singleSiteId) {
                if ((int)($row['matched_member_id'] ?? 0) === $memberId) {
                    return 0;
                }
                if ($householdId && (int)($row['matched_household_id'] ?? 0) === $householdId) {
                    return 1;
                }
                if ($singleSiteId && (int)($row['site_id'] ?? 0) === $singleSiteId) {
                    return 2;
                }
                return 3;
            };

            $rankA = $rank($a);
            $rankB = $rank($b);
            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }

            $updatedA = strtotime((string)($a['updated_at'] ?? '')) ?: 0;
            $updatedB = strtotime((string)($b['updated_at'] ?? '')) ?: 0;
            if ($updatedA !== $updatedB) {
                return $updatedB <=> $updatedA;
            }

            return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
        });

        return $rows[0];
    }

    private function loadMemberById(PDO $db, int $memberId): ?array {
        $stmt = $db->prepare("SELECT * FROM members WHERE id = ? LIMIT 1");
        $stmt->execute([$memberId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function isBlankValue($value): bool {
        if ($value === null) {
            return true;
        }

        $normalized = trim((string)$value);
        return $normalized === '' || $normalized === '0000-00-00' || $normalized === '0000-00-00 00:00:00';
    }

    private function mergeMemberProfile(PDO $db, array $source, array $target): void {
        $resolved = [
            'first_name' => $this->isBlankValue($target['first_name'] ?? null) ? ($source['first_name'] ?? null) : $target['first_name'],
            'last_name' => $this->isBlankValue($target['last_name'] ?? null) ? ($source['last_name'] ?? null) : $target['last_name'],
            'email' => $this->isBlankValue($target['email'] ?? null) ? ($source['email'] ?? null) : $target['email'],
            'mobile' => $this->isBlankValue($target['mobile'] ?? null) ? ($source['mobile'] ?? null) : $target['mobile'],
            'phone' => $this->isBlankValue($target['phone'] ?? null) ? ($source['phone'] ?? null) : $target['phone'],
            'fellowship' => $this->isBlankValue($target['fellowship'] ?? null) ? ($source['fellowship'] ?? null) : $target['fellowship'],
            'concession' => (($target['concession'] ?? 'No') === 'Yes' || ($source['concession'] ?? 'No') === 'Yes') ? 'Yes' : 'No',
            'site_fee_status' => (($target['site_fee_status'] ?? 'Unknown') !== 'Unknown')
                ? $target['site_fee_status']
                : (($source['site_fee_status'] ?? 'Unknown') ?: 'Unknown'),
            'churchsuite_person_type' => $this->isBlankValue($target['churchsuite_person_type'] ?? null) ? ($source['churchsuite_person_type'] ?? null) : $target['churchsuite_person_type'],
            'churchsuite_person_id' => $this->isBlankValue($target['churchsuite_person_id'] ?? null) ? ($source['churchsuite_person_id'] ?? null) : $target['churchsuite_person_id'],
            'churchsuite_sync_status' => (($target['churchsuite_sync_status'] ?? 'local') !== 'local')
                ? $target['churchsuite_sync_status']
                : (($source['churchsuite_sync_status'] ?? 'local') ?: 'local'),
            'churchsuite_sync_note' => $this->isBlankValue($target['churchsuite_sync_note'] ?? null) ? ($source['churchsuite_sync_note'] ?? null) : $target['churchsuite_sync_note'],
            'churchsuite_last_synced_at' => $this->isBlankValue($target['churchsuite_last_synced_at'] ?? null) ? ($source['churchsuite_last_synced_at'] ?? null) : $target['churchsuite_last_synced_at'],
            'churchsuite_payload_json' => $this->isBlankValue($target['churchsuite_payload_json'] ?? null) ? ($source['churchsuite_payload_json'] ?? null) : $target['churchsuite_payload_json'],
            'digital_agreement_confirmed' => max((int)($target['digital_agreement_confirmed'] ?? 0), (int)($source['digital_agreement_confirmed'] ?? 0)),
        ];

        $stmt = $db->prepare("
            UPDATE members
            SET
                first_name = ?,
                last_name = ?,
                email = ?,
                mobile = ?,
                phone = ?,
                fellowship = ?,
                concession = ?,
                site_fee_status = ?,
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
            $resolved['first_name'],
            $resolved['last_name'],
            $resolved['email'],
            $resolved['mobile'],
            $resolved['phone'],
            $resolved['fellowship'],
            $resolved['concession'],
            $resolved['site_fee_status'],
            $resolved['churchsuite_person_type'],
            $resolved['churchsuite_person_id'],
            $resolved['churchsuite_sync_status'],
            $resolved['churchsuite_sync_note'],
            $resolved['churchsuite_last_synced_at'],
            $resolved['churchsuite_payload_json'],
            $resolved['digital_agreement_confirmed'],
            (int)$target['id'],
        ]);
    }

    private function reassignMemberReferences(PDO $db, string $table, string $column, int $sourceId, int $targetId): void {
        if (!$this->tableExists($db, $table) || !$this->columnExists($db, $table, $column)) {
            return;
        }

        $sql = "UPDATE `" . str_replace('`', '``', $table) . "` SET `" . str_replace('`', '``', $column) . "` = ? WHERE `" . str_replace('`', '``', $column) . "` = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$targetId, $sourceId]);
    }

    private function relationshipConflictExists(PDO $db, int $memberId, int $relatedMemberId, string $relationshipType, $sourceSystem, int $excludeId): bool {
        $sql = "
            SELECT id
            FROM member_relationships
            WHERE member_id = ?
              AND related_member_id = ?
              AND relationship_type = ?
              AND id <> ?
              AND ";
        $params = [$memberId, $relatedMemberId, $relationshipType, $excludeId];

        if ($sourceSystem === null || $sourceSystem === '') {
            $sql .= "source_system IS NULL";
        } else {
            $sql .= "source_system = ?";
            $params[] = $sourceSystem;
        }

        $sql .= " LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function transferRelationshipsForMerge(PDO $db, int $sourceId, int $targetId): void {
        if (!$this->tableExists($db, 'member_relationships')) {
            return;
        }

        $stmt = $db->prepare("
            SELECT id, member_id, related_member_id, relationship_type, source_system
            FROM member_relationships
            WHERE member_id = ? OR related_member_id = ?
            ORDER BY id ASC
        ");
        $stmt->execute([$sourceId, $sourceId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $rowId = (int)($row['id'] ?? 0);
            $newMemberId = (int)($row['member_id'] ?? 0) === $sourceId ? $targetId : (int)($row['member_id'] ?? 0);
            $newRelatedId = (int)($row['related_member_id'] ?? 0) === $sourceId ? $targetId : (int)($row['related_member_id'] ?? 0);

            if ($newMemberId <= 0 || $newRelatedId <= 0 || $newMemberId === $newRelatedId) {
                $db->prepare("DELETE FROM member_relationships WHERE id = ?")->execute([$rowId]);
                continue;
            }

            if ($this->relationshipConflictExists(
                $db,
                $newMemberId,
                $newRelatedId,
                (string)($row['relationship_type'] ?? ''),
                $row['source_system'] ?? null,
                $rowId
            )) {
                $db->prepare("DELETE FROM member_relationships WHERE id = ?")->execute([$rowId]);
                continue;
            }

            $update = $db->prepare("
                UPDATE member_relationships
                SET member_id = ?, related_member_id = ?
                WHERE id = ?
            ");
            $update->execute([$newMemberId, $newRelatedId, $rowId]);
        }
    }

    private function transferHouseholdMembershipsForMerge(PDO $db, int $sourceId, int $targetId): void {
        if (!$this->householdsEnabled($db)) {
            return;
        }

        $targetCountStmt = $db->prepare("SELECT COUNT(*) FROM member_household_members WHERE member_id = ?");
        $targetCountStmt->execute([$targetId]);
        $targetHasMemberships = (int)$targetCountStmt->fetchColumn() > 0;

        if ($targetHasMemberships) {
            $db->prepare("DELETE FROM member_household_members WHERE member_id = ?")->execute([$sourceId]);
            return;
        }

        $stmt = $db->prepare("
            SELECT id, household_id, role_label, is_primary, source_system
            FROM member_household_members
            WHERE member_id = ?
            ORDER BY id ASC
        ");
        $stmt->execute([$sourceId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $params = [(int)$row['household_id'], $targetId];
            $sql = "
                SELECT id, role_label, is_primary
                FROM member_household_members
                WHERE household_id = ?
                  AND member_id = ?
                  AND ";

            if (($row['source_system'] ?? null) === null || $row['source_system'] === '') {
                $sql .= "source_system IS NULL";
            } else {
                $sql .= "source_system = ?";
                $params[] = $row['source_system'];
            }

            $sql .= " LIMIT 1";
            $existingStmt = $db->prepare($sql);
            $existingStmt->execute($params);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($existing) {
                $roleLabel = $existing['role_label'] ?: ($row['role_label'] ?? null);
                $isPrimary = max((int)($existing['is_primary'] ?? 0), (int)($row['is_primary'] ?? 0));
                $db->prepare("
                    UPDATE member_household_members
                    SET role_label = ?, is_primary = ?
                    WHERE id = ?
                ")->execute([$roleLabel, $isPrimary, (int)$existing['id']]);

                $db->prepare("DELETE FROM member_household_members WHERE id = ?")->execute([(int)$row['id']]);
                continue;
            }

            $db->prepare("UPDATE member_household_members SET member_id = ? WHERE id = ?")
                ->execute([$targetId, (int)$row['id']]);
        }
    }

    private function transferSiteAllocationsForMerge(PDO $db, int $sourceId, int $targetId): void {
        if (!$this->tableExists($db, 'site_allocations')) {
            return;
        }

        $siteStmt = $db->prepare("SELECT DISTINCT site_id FROM site_allocations WHERE member_id IN (?, ?) AND site_id IS NOT NULL");
        $siteStmt->execute([$sourceId, $targetId]);
        $siteIds = array_map('intval', $siteStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

        $targetCurrentCountStmt = $db->prepare("SELECT COUNT(*) FROM site_allocations WHERE member_id = ? AND is_current = 1");
        $targetCurrentCountStmt->execute([$targetId]);
        $targetHasCurrentSite = (int)$targetCurrentCountStmt->fetchColumn() > 0;

        if ($targetHasCurrentSite) {
            $db->prepare("
                UPDATE site_allocations
                SET member_id = ?
                WHERE member_id = ?
                  AND (is_current = 0 OR is_current IS NULL)
            ")->execute([$targetId, $sourceId]);

            $db->prepare("
                UPDATE site_allocations
                SET member_id = ?, is_current = 0, end_date = COALESCE(end_date, CURDATE())
                WHERE member_id = ?
                  AND is_current = 1
            ")->execute([$targetId, $sourceId]);
        } else {
            $db->prepare("UPDATE site_allocations SET member_id = ? WHERE member_id = ?")
                ->execute([$targetId, $sourceId]);
        }

        $this->clearDuplicateCurrentAllocations($db, $targetId);
        foreach ($siteIds as $siteId) {
            if ($siteId > 0) {
                $this->refreshSiteStatus($db, $siteId);
            }
        }
    }

    private function deleteEmptyHouseholds(PDO $db): void {
        if (!$this->householdsEnabled($db)) {
            return;
        }

        $db->exec("
            DELETE mh
            FROM member_households mh
            LEFT JOIN member_household_members mhm ON mhm.household_id = mh.id
            WHERE mhm.id IS NULL
        ");
    }

    public function merge($id) {
        $sourceId = (int)$id;
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $targetId = (int)($data['target_member_id'] ?? 0);

        if ($sourceId <= 0 || $targetId <= 0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Choose both the source and target member records.']);
            return;
        }

        if ($sourceId === $targetId) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Choose two different member records to merge.']);
            return;
        }

        $db = Database::connect();
        $db->beginTransaction();
        try {
            $source = $this->loadMemberById($db, $sourceId);
            $target = $this->loadMemberById($db, $targetId);

            if (!$source || !$target) {
                throw new InvalidArgumentException('One of the selected member records could not be found.');
            }

            $sourceChurchSuiteId = trim((string)($source['churchsuite_person_id'] ?? ''));
            $targetChurchSuiteId = trim((string)($target['churchsuite_person_id'] ?? ''));
            $sourceChurchSuiteType = trim((string)($source['churchsuite_person_type'] ?? ''));
            $targetChurchSuiteType = trim((string)($target['churchsuite_person_type'] ?? ''));

            if (
                $sourceChurchSuiteId !== '' &&
                $targetChurchSuiteId !== '' &&
                ($sourceChurchSuiteId !== $targetChurchSuiteId || $sourceChurchSuiteType !== $targetChurchSuiteType)
            ) {
                throw new InvalidArgumentException('These records are linked to different ChurchSuite people and cannot be merged safely.');
            }

            $this->mergeMemberProfile($db, $source, $target);
            $this->reassignMemberReferences($db, 'payments', 'member_id', $sourceId, $targetId);
            $this->reassignMemberReferences($db, 'prepayments', 'matched_member_id', $sourceId, $targetId);
            $this->reassignMemberReferences($db, 'camp_intranet_checkins', 'matched_member_id', $sourceId, $targetId);
            $this->reassignMemberReferences($db, 'camp_intranet_checkins', 'applied_member_id', $sourceId, $targetId);
            $this->reassignMemberReferences($db, 'site_fee_audit_reviews', 'member_id', $sourceId, $targetId);

            if ($this->tableExists($db, 'payment_prepayment_allocations')) {
                foreach (['owner_member_id', 'beneficiary_member_id', 'source_member_id', 'applied_to_member_id'] as $column) {
                    $this->reassignMemberReferences($db, 'payment_prepayment_allocations', $column, $sourceId, $targetId);
                }
            }

            $this->transferSiteAllocationsForMerge($db, $sourceId, $targetId);
            $this->transferHouseholdMembershipsForMerge($db, $sourceId, $targetId);
            $this->transferRelationshipsForMerge($db, $sourceId, $targetId);

            if ($this->tableExists($db, 'site_fee_accounts')) {
                $db->prepare("DELETE FROM site_fee_accounts WHERE member_id = ?")->execute([$sourceId]);
            }

            $siteFeeService = new SiteFeeService($db);
            if ($siteFeeService->tableExists('payments')) {
                $siteFeeService->recalculateMemberAccountFromPayments($targetId);
            }

            $db->prepare("DELETE FROM members WHERE id = ?")->execute([$sourceId]);
            $this->deleteEmptyHouseholds($db);

            $db->commit();
            echo json_encode([
                'success' => true,
                'message' => 'Member records merged successfully.',
                'target_member_id' => $targetId,
                'source_member_id' => $sourceId
            ]);
        } catch (InvalidArgumentException $e) {
            $db->rollBack();
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function index() {
        $db = Database::connect();
        $householdsEnabled = $this->householdsEnabled($db);

        $sql = "
            SELECT
                m.*,
                sfa.paid_until AS site_fee_paid_until,
                ms.current_site_count,
                ms.site_number,
                ms.site_type
        ";

        if ($householdsEnabled) {
            $sql .= ",
                hhs.household_id,
                hhs.household_name,
                hhs.insurance_status,
                hhs.agreement_status,
                hhs.agreement_source,
                hhs.digital_agreement_confirmed,
                hhs.household_member_names,
                hhs.household_site_count,
                hhs.household_site_number,
                hhs.household_site_type,
                hhs.household_site_fee_paid_until,
                CASE
                    WHEN COALESCE(hhs.household_site_count, 0) = 1 THEN hhs.household_site_number
                    WHEN COALESCE(hhs.household_site_count, 0) > 1 THEN 'Multiple'
                    WHEN COALESCE(ms.current_site_count, 0) > 1 THEN 'Multiple'
                    ELSE ms.site_number
                END AS display_site_number,
                CASE
                    WHEN COALESCE(hhs.household_site_count, 0) = 1 THEN hhs.household_site_type
                    WHEN COALESCE(hhs.household_site_count, 0) > 1 THEN 'Multiple household sites'
                    WHEN COALESCE(ms.current_site_count, 0) > 1 THEN 'Multiple current sites'
                    ELSE ms.site_type
                END AS display_site_type,
                CASE
                    WHEN COALESCE(hhs.household_site_count, 0) = 1 THEN hhs.household_site_fee_paid_until
                    ELSE sfa.paid_until
                END AS display_site_fee_paid_until
            ";
        } else {
            $sql .= ",
                NULL AS household_id,
                NULL AS household_name,
                NULL AS insurance_status,
                NULL AS agreement_status,
                NULL AS agreement_source,
                0 AS digital_agreement_confirmed,
                NULL AS household_member_names,
                0 AS household_site_count,
                NULL AS household_site_number,
                NULL AS household_site_type,
                NULL AS household_site_fee_paid_until,
                CASE
                    WHEN COALESCE(ms.current_site_count, 0) > 1 THEN 'Multiple'
                    ELSE ms.site_number
                END AS display_site_number,
                CASE
                    WHEN COALESCE(ms.current_site_count, 0) > 1 THEN 'Multiple current sites'
                    ELSE ms.site_type
                END AS display_site_type,
                sfa.paid_until AS display_site_fee_paid_until
            ";
        }

        $sql .= "
            FROM members m
            LEFT JOIN (
                SELECT member_id, MAX(paid_until) AS paid_until
                FROM site_fee_accounts
                GROUP BY member_id
            ) sfa ON sfa.member_id = m.id
        ";

        $sql .= $this->currentSiteSummaryJoinSql('m.id', 'ms');

        if ($householdsEnabled) {
            $sql .= "
                LEFT JOIN (
                    SELECT
                        pref.member_id,
                        hh.id AS household_id,
                        hh.display_name AS household_name,
                        hh.insurance_status,
                        hh.agreement_status,
                        hh.agreement_source,
                        hh.digital_agreement_confirmed,
                        COUNT(DISTINCT CASE WHEN hs.id IS NOT NULL THEN hs.id END) AS household_site_count,
                        CASE
                            WHEN COUNT(DISTINCT CASE WHEN hs.id IS NOT NULL THEN hs.id END) = 1 THEN MAX(hs.site_number)
                            ELSE NULL
                        END AS household_site_number,
                        CASE
                            WHEN COUNT(DISTINCT CASE WHEN hs.id IS NOT NULL THEN hs.id END) = 1 THEN MAX(hs.site_type)
                            ELSE NULL
                        END AS household_site_type,
                        CASE
                            WHEN COUNT(DISTINCT CASE WHEN hs.id IS NOT NULL THEN hs.id END) = 1 THEN MAX(hsfa.paid_until)
                            ELSE NULL
                        END AS household_site_fee_paid_until,
                        GROUP_CONCAT(
                            DISTINCT CASE
                                WHEN hm2.id IS NULL OR hm2.id = pref.member_id THEN NULL
                                ELSE CONCAT(TRIM(COALESCE(hm2.first_name, '')), ' ', TRIM(COALESCE(hm2.last_name, '')))
                            END
                            ORDER BY hm2.last_name, hm2.first_name
                            SEPARATOR ' | '
                        ) AS household_member_names
                    FROM (
                        SELECT
                            mhm.member_id,
                            CAST(SUBSTRING_INDEX(
                                GROUP_CONCAT(
                                    mhm.household_id
                                    ORDER BY
                                        " . $this->householdSourcePrioritySql('mhm.source_system') . ",
                                        COALESCE(hc.member_count, 0) DESC,
                                        mhm.is_primary DESC,
                                        mhm.household_id ASC
                                ),
                                ',',
                                1
                            ) AS UNSIGNED) AS household_id
                        FROM member_household_members mhm
                        LEFT JOIN (
                            SELECT household_id, COUNT(*) AS member_count
                            FROM member_household_members
                            GROUP BY household_id
                        ) hc ON hc.household_id = mhm.household_id
                        GROUP BY mhm.member_id
                    ) pref
                    JOIN member_households hh ON hh.id = pref.household_id
                    LEFT JOIN member_household_members mhm2 ON mhm2.household_id = hh.id
                    LEFT JOIN members hm2 ON hm2.id = mhm2.member_id
                    LEFT JOIN site_allocations hsa ON hsa.member_id = hm2.id AND hsa.is_current = 1
                    LEFT JOIN sites hs ON hs.id = hsa.site_id
                    LEFT JOIN (
                        SELECT member_id, MAX(paid_until) AS paid_until
                        FROM site_fee_accounts
                        GROUP BY member_id
                    ) hsfa ON hsfa.member_id = hm2.id
                    GROUP BY
                        pref.member_id,
                        hh.id,
                        hh.display_name,
                        hh.insurance_status,
                        hh.agreement_status,
                        hh.agreement_source,
                        hh.digital_agreement_confirmed
                ) hhs ON hhs.member_id = m.id
            ";
        }

        $sql .= " ORDER BY m.last_name, m.first_name";

        $stmt = $db->query($sql);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function store() {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $db = Database::connect();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                INSERT INTO members (
                    first_name,
                    last_name,
                    email,
                    mobile,
                    phone,
                    fellowship,
                    concession,
                    site_fee_status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                trim((string)($data['first_name'] ?? '')),
                trim((string)($data['last_name'] ?? '')),
                trim((string)($data['email'] ?? '')) ?: null,
                trim((string)($data['mobile'] ?? '')) ?: null,
                trim((string)($data['phone'] ?? '')) ?: null,
                trim((string)($data['fellowship'] ?? '')),
                $this->normalizeConcession($data['concession'] ?? 'No'),
                trim((string)($data['site_fee_status'] ?? 'Unknown')) ?: 'Unknown'
            ]);
            $newId = (int)$db->lastInsertId();

            $this->upsertSiteFeePaidUntil($db, $newId, $data['site_fee_paid_until'] ?? '', false);
            $this->syncMemberHousehold($db, $newId, $data);
            $this->syncMemberSite($db, $newId, $data);

            $db->commit();
            echo json_encode(['success' => true, 'id' => $newId]);
        } catch (InvalidArgumentException $e) {
            $db->rollBack();
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function update($id) {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $db = Database::connect();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                UPDATE members
                SET first_name = ?, last_name = ?, email = ?, mobile = ?, phone = ?, fellowship = ?, concession = ?, site_fee_status = ?
                WHERE id = ?
            ");
            $stmt->execute([
                trim((string)($data['first_name'] ?? '')),
                trim((string)($data['last_name'] ?? '')),
                trim((string)($data['email'] ?? '')) ?: null,
                trim((string)($data['mobile'] ?? '')) ?: null,
                trim((string)($data['phone'] ?? '')) ?: null,
                trim((string)($data['fellowship'] ?? '')),
                $this->normalizeConcession($data['concession'] ?? 'No'),
                trim((string)($data['site_fee_status'] ?? 'Unknown')) ?: 'Unknown',
                (int)$id
            ]);

            if (array_key_exists('site_fee_paid_until', $data)) {
                $this->upsertSiteFeePaidUntil($db, (int)$id, $data['site_fee_paid_until'] ?? '', true);
            }

            $this->syncMemberHousehold($db, (int)$id, $data);
            $this->syncMemberSite($db, (int)$id, $data);

            $db->commit();
            echo json_encode(['success' => true]);
        } catch (InvalidArgumentException $e) {
            $db->rollBack();
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function history($id) {
        $db = Database::connect();
        $householdService = $this->householdsEnabled($db) ? new MemberHouseholdService($db) : null;
        $campId = isset($_GET['camp_id']) && $_GET['camp_id'] !== '' ? (int)$_GET['camp_id'] : null;

        $memberStmt = $db->prepare("
            SELECT
                m.*,
                sfa.paid_until AS site_fee_paid_until,
                ms.current_site_count,
                ms.site_number,
                ms.site_type
            FROM members m
            LEFT JOIN (
                SELECT member_id, MAX(paid_until) AS paid_until
                FROM site_fee_accounts
                GROUP BY member_id
            ) sfa ON sfa.member_id = m.id
            " . $this->currentSiteSummaryJoinSql('m.id', 'ms') . "
            WHERE m.id = ?
            LIMIT 1
        ");
        $memberStmt->execute([(int)$id]);
        $member = $memberStmt->fetch(PDO::FETCH_ASSOC);

        if (!$member) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Member not found.']);
            return;
        }

        $stmt = $db->prepare("
            SELECT p.*, c.name as camp_name
            FROM payments p
            LEFT JOIN camps c ON p.camp_id = c.id
            WHERE p.member_id = ?
            ORDER BY p.payment_date DESC
        ");
        $stmt->execute([(int)$id]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $db->prepare("
            SELECT sa.*, s.site_number, s.site_type
            FROM site_allocations sa
            JOIN sites s ON sa.site_id = s.id
            WHERE sa.member_id = ?
            ORDER BY sa.start_date DESC
        ");
        $stmt->execute([(int)$id]);
        $allocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $db->prepare("
            SELECT p.*, owner.first_name AS member_first_name, owner.last_name AS member_last_name
            FROM prepayments p
            LEFT JOIN members owner ON owner.id = p.matched_member_id
            WHERE p.matched_member_id = ?
            ORDER BY p.id DESC
        ");
        $stmt->execute([(int)$id]);
        $prepayments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $householdDetail = $householdService ? $householdService->getHouseholdForMember((int)$id) : null;
        $householdPrepayments = $householdService ? $householdService->getHouseholdAvailablePrepayments((int)$id) : [];
        $pendingCheckIn = $this->fetchPendingCheckIn($db, (int)$id, $campId, $householdDetail);

        $member['display_site_number'] = $member['site_number'] ?? null;
        $member['display_site_type'] = $member['site_type'] ?? null;
        $member['display_site_fee_paid_until'] = $this->fetchSiteFeePaidUntil($db, (int)$id);
        if (!empty($householdDetail['household'])) {
            $household = $householdDetail['household'];
            $householdSiteCount = (int)($household['site_count'] ?? 0);
            if ($householdSiteCount === 1 && !empty($household['site_number'])) {
                $member['display_site_number'] = $household['site_number'];
                $member['display_site_type'] = $household['site_type'] ?? null;
                $member['display_site_fee_paid_until'] = $household['site_fee_paid_until'] ?? $member['display_site_fee_paid_until'];
            } elseif ($householdSiteCount > 1) {
                $member['display_site_number'] = 'Multiple';
                $member['display_site_type'] = 'Multiple household sites';
            }
        } elseif ((int)($member['current_site_count'] ?? 0) > 1) {
            $member['display_site_number'] = 'Multiple';
            $member['display_site_type'] = 'Multiple current sites';
        }

        echo json_encode([
            'member' => $member,
            'payments' => $payments,
            'allocations' => $allocations,
            'prepayments' => $prepayments,
            'site_fee_paid_until' => $member['display_site_fee_paid_until'],
            'household' => $householdDetail,
            'household_prepayments' => $householdPrepayments,
            'pending_checkin' => $pendingCheckIn
        ]);
    }

    public function delete($id) {
        $db = Database::connect();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("DELETE FROM site_allocations WHERE member_id = ?");
            $stmt->execute([(int)$id]);

            $stmt = $db->prepare("DELETE FROM site_fee_accounts WHERE member_id = ?");
            $stmt->execute([(int)$id]);

            if ($this->tableExists($db, 'payment_prepayment_allocations')) {
                $stmt = $db->prepare("DELETE FROM payment_prepayment_allocations WHERE source_member_id = ? OR applied_to_member_id = ?");
                $stmt->execute([(int)$id, (int)$id]);
            }

            $stmt = $db->prepare("SELECT id FROM payments WHERE member_id = ?");
            $stmt->execute([(int)$id]);
            $paymentIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if ($paymentIds) {
                $placeholders = implode(',', array_fill(0, count($paymentIds), '?'));
                $db->prepare("DELETE FROM payment_tenders WHERE payment_id IN ($placeholders)")->execute($paymentIds);
            }

            $stmt = $db->prepare("DELETE FROM payments WHERE member_id = ?");
            $stmt->execute([(int)$id]);

            $stmt = $db->prepare("
                UPDATE prepayments
                SET matched_member_id = NULL, status = 'Unmatched', match_source = NULL, match_note = 'Member deleted'
                WHERE matched_member_id = ?
            ");
            $stmt->execute([(int)$id]);

            $stmt = $db->prepare("DELETE FROM members WHERE id = ?");
            $stmt->execute([(int)$id]);

            if ($this->householdsEnabled($db)) {
                $db->exec("
                    DELETE mh
                    FROM member_households mh
                    LEFT JOIN member_household_members mhm ON mhm.household_id = mh.id
                    WHERE mhm.id IS NULL
                ");
            }

            $db->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function deleteAll() {
        $db = Database::connect();
        $db->beginTransaction();
        try {
            if ($this->tableExists($db, 'payment_prepayment_allocations')) {
                $db->exec("DELETE FROM payment_prepayment_allocations");
            }
            if ($this->tableExists($db, 'household_agreement_documents')) {
                $db->exec("DELETE FROM household_agreement_documents");
            }
            if ($this->tableExists($db, 'member_relationships')) {
                $db->exec("DELETE FROM member_relationships");
            }
            if ($this->tableExists($db, 'member_household_members')) {
                $db->exec("DELETE FROM member_household_members");
            }
            if ($this->tableExists($db, 'member_households')) {
                $db->exec("DELETE FROM member_households");
            }

            $db->exec("DELETE FROM site_allocations");
            $db->exec("DELETE FROM site_fee_accounts");
            $db->exec("DELETE FROM payment_tenders");
            $db->exec("DELETE FROM payments");

            $resetSql = "UPDATE prepayments SET matched_member_id = NULL, status = 'Unmatched'";
            if ($this->columnExists($db, 'prepayments', 'match_source')) {
                $resetSql .= ", match_source = NULL";
            }
            if ($this->columnExists($db, 'prepayments', 'match_note')) {
                $resetSql .= ", match_note = NULL";
            }
            $db->exec($resetSql);

            $db->exec("DELETE FROM members");

            $db->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
