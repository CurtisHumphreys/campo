<?php

class ChurchSuiteTokenStore {
    private $db;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->ensureTable();
    }

    public function load() {
        try {
            $stmt = $this->db->prepare("SELECT * FROM oauth_tokens WHERE provider = ? LIMIT 1");
            $stmt->execute(['churchsuite']);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    public function save(array $tokenData, $grantType = 'authorization_code') {
        $existing = $this->load();
        $expiresAt = null;
        if (isset($tokenData['expires_in']) && (int)$tokenData['expires_in'] > 0) {
            $expiresAt = date('Y-m-d H:i:s', time() + (int)$tokenData['expires_in']);
        } elseif (!empty($tokenData['expires_at'])) {
            $expiresAt = $tokenData['expires_at'];
        } elseif ($existing && !empty($existing['expires_at'])) {
            $expiresAt = $existing['expires_at'];
        }

        $refreshToken = trim((string)($tokenData['refresh_token'] ?? ''));
        if ($refreshToken === '' && $existing) {
            $refreshToken = (string)($existing['refresh_token'] ?? '');
        }

        $scope = $tokenData['scope'] ?? ($existing['scope'] ?? null);
        if (is_array($scope)) {
            $scope = implode(' ', $scope);
        }
        $scope = $scope !== null ? trim((string)$scope) : null;

        $payload = $tokenData;
        $payload['grant_type'] = $grantType;
        if ($expiresAt !== null) {
            $payload['expires_at'] = $expiresAt;
        }

        $stmt = $this->db->prepare("
            INSERT INTO oauth_tokens (
                provider, access_token, refresh_token, token_type, scope, expires_at, metadata
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                access_token = VALUES(access_token),
                refresh_token = VALUES(refresh_token),
                token_type = VALUES(token_type),
                scope = VALUES(scope),
                expires_at = VALUES(expires_at),
                metadata = VALUES(metadata),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            'churchsuite',
            trim((string)($tokenData['access_token'] ?? '')),
            $refreshToken !== '' ? $refreshToken : null,
            trim((string)($tokenData['token_type'] ?? 'Bearer')),
            $scope !== '' ? $scope : null,
            $expiresAt,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ]);
    }

    public function clear() {
        $stmt = $this->db->prepare("DELETE FROM oauth_tokens WHERE provider = ?");
        $stmt->execute(['churchsuite']);
    }

    private function ensureTable() {
        $sql = "CREATE TABLE IF NOT EXISTS oauth_tokens (
            provider VARCHAR(50) PRIMARY KEY,
            access_token TEXT NULL,
            refresh_token TEXT NULL,
            token_type VARCHAR(50) NULL,
            scope VARCHAR(255) NULL,
            expires_at DATETIME NULL,
            metadata TEXT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $this->db->exec($sql);
    }
}
