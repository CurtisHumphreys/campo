<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only maintenance script
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/src/Database.php';

$db = Database::connect();

$db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS email VARCHAR(255) NOT NULL DEFAULT '' AFTER username");
$db->exec("ALTER TABLE users MODIFY COLUMN password_hash VARCHAR(255) NULL");
$db->exec("CREATE TABLE IF NOT EXISTS user_tokens (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    token      CHAR(64) NOT NULL,
    type       ENUM('activation','password_reset') NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at    DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ut_token (token),
    INDEX idx_ut_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

echo "Migration complete.\n";
