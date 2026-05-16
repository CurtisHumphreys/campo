<?php

class SiteMapOccupantService {
    private $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function buildBySite() {
        try {
            $stmt = $this->db->query(
                "SELECT sa.site_id, h.name AS household_name
                 FROM site_allocations sa
                 JOIN households h ON h.id = sa.household_id
                 WHERE h.name != ''
                 ORDER BY sa.site_id"
            );
            if (!$stmt) return [];
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }

        $mapped = [];
        foreach ($rows as $row) {
            $siteId = (int)($row['site_id'] ?? 0);
            if ($siteId <= 0) continue;
            $name = trim((string)($row['household_name'] ?? ''));
            if ($name === '') continue;
            $mapped[$siteId] = [
                'occupants'           => $name,
                'map_occupants'       => $name,
                'occupants_list'      => [['name' => $name]],
                'map_occupants_list'  => [['name' => $name]],
            ];
        }
        return $mapped;
    }
}
