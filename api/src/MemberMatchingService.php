<?php

class MemberMatchingService {
    private $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public static function normalizeEmail($value) {
        return strtolower(trim((string)$value));
    }

    public static function normalizePhone($value) {
        $digits = preg_replace('/[^0-9+]/', '', (string)$value);
        if ($digits === null) {
            return '';
        }

        $digits = trim($digits);
        if ($digits === '') {
            return '';
        }

        if (strpos($digits, '00') === 0) {
            $digits = '+' . substr($digits, 2);
        }

        if (strpos($digits, '+61') === 0) {
            return '+61' . preg_replace('/[^0-9]/', '', substr($digits, 3));
        }

        $numeric = preg_replace('/[^0-9]/', '', $digits);
        if (strpos($numeric, '61') === 0) {
            return '+61' . substr($numeric, 2);
        }

        if (strpos($numeric, '0') === 0 && strlen($numeric) >= 9) {
            return '+61' . substr($numeric, 1);
        }

        return $digits[0] === '+' ? $digits : $numeric;
    }

    public static function churchsuiteExternalKey($type, $id) {
        $type = trim((string)$type);
        $id = trim((string)$id);
        if ($type === '' || $id === '') {
            return '';
        }
        return strtolower($type) . ':' . $id;
    }

    public function buildIndexes() {
        $rows = $this->db->query("
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
            ORDER BY id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $indexes = [
            'rows' => [],
            'external' => [],
            'email' => [],
            'mobile' => [],
            'name' => []
        ];

        foreach ($rows as $row) {
            $row['external_key'] = self::churchsuiteExternalKey($row['churchsuite_person_type'] ?? '', $row['churchsuite_person_id'] ?? '');
            $row['email_normalized'] = self::normalizeEmail($row['email'] ?? '');
            $row['mobile_normalized'] = self::normalizePhone($row['mobile'] ?? ($row['phone'] ?? ''));
            $row['name_key'] = $this->nameKey($row['first_name'] ?? '', $row['last_name'] ?? '');

            $indexes['rows'][(int)$row['id']] = $row;

            if ($row['external_key'] !== '') {
                $indexes['external'][$row['external_key']] = $row;
            }

            if ($row['email_normalized'] !== '') {
                if (!isset($indexes['email'][$row['email_normalized']])) {
                    $indexes['email'][$row['email_normalized']] = [];
                }
                $indexes['email'][$row['email_normalized']][] = $row;
            }

            if ($row['mobile_normalized'] !== '') {
                if (!isset($indexes['mobile'][$row['mobile_normalized']])) {
                    $indexes['mobile'][$row['mobile_normalized']] = [];
                }
                $indexes['mobile'][$row['mobile_normalized']][] = $row;
            }

            if ($row['name_key'] !== '|') {
                if (!isset($indexes['name'][$row['name_key']])) {
                    $indexes['name'][$row['name_key']] = [];
                }
                $indexes['name'][$row['name_key']][] = $row;
            }
        }

        return $indexes;
    }

    public function matchPerson(array $person, array $indexes) {
        $externalKey = self::churchsuiteExternalKey($person['churchsuite_person_type'] ?? '', $person['churchsuite_person_id'] ?? '');
        if ($externalKey !== '' && isset($indexes['external'][$externalKey])) {
            return [
                'status' => 'matched',
                'source' => 'churchsuite_id',
                'member' => $indexes['external'][$externalKey],
                'candidates' => [$indexes['external'][$externalKey]]
            ];
        }

        $email = self::normalizeEmail($person['email'] ?? '');
        if ($email !== '' && !empty($indexes['email'][$email])) {
            $matches = $this->uniqueRows($indexes['email'][$email]);
            if (count($matches) === 1) {
                return [
                    'status' => 'matched',
                    'source' => 'email',
                    'member' => $matches[0],
                    'candidates' => $matches
                ];
            }
            return [
                'status' => 'review',
                'source' => 'email',
                'member' => null,
                'candidates' => $matches
            ];
        }

        $mobile = self::normalizePhone($person['mobile'] ?? ($person['phone'] ?? ''));
        if ($mobile !== '' && !empty($indexes['mobile'][$mobile])) {
            $matches = $this->uniqueRows($indexes['mobile'][$mobile]);
            if (count($matches) === 1) {
                return [
                    'status' => 'matched',
                    'source' => 'mobile',
                    'member' => $matches[0],
                    'candidates' => $matches
                ];
            }
            return [
                'status' => 'review',
                'source' => 'mobile',
                'member' => null,
                'candidates' => $matches
            ];
        }

        $nameKey = $this->nameKey($person['first_name'] ?? '', $person['last_name'] ?? '');
        if ($nameKey !== '|' && !empty($indexes['name'][$nameKey])) {
            $matches = $this->uniqueRows($indexes['name'][$nameKey]);
            if (count($matches) === 1) {
                return [
                    'status' => 'matched',
                    'source' => 'name',
                    'member' => $matches[0],
                    'candidates' => $matches
                ];
            }
            return [
                'status' => 'review',
                'source' => 'name',
                'member' => null,
                'candidates' => $matches
            ];
        }

        return [
            'status' => 'new',
            'source' => 'new',
            'member' => null,
            'candidates' => []
        ];
    }

    public function nameKey($firstName, $lastName) {
        return strtolower(trim((string)$firstName)) . '|' . strtolower(trim((string)$lastName));
    }

    private function uniqueRows(array $rows) {
        $unique = [];
        foreach ($rows as $row) {
            $unique[(int)$row['id']] = $row;
        }
        return array_values($unique);
    }
}
