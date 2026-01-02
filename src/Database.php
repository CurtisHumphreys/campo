<?php

class Database {
    private static $pdo = null;

    public static function connect() {
        if (self::$pdo === null) {
            try {
                require_once __DIR__ . '/../config/config.php';
                $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
                self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } catch (PDOException $e) {
                // In production, log this error and show a generic message. Avoid emitting raw
                // database errors which would be HTML when expecting JSON. Send a JSON
                // response with HTTP 500 so the frontend can handle it gracefully.
                if (!headers_sent()) {
                    http_response_code(500);
                    header('Content-Type: application/json');
                }
                echo json_encode(['success' => false, 'message' => 'Database connection failed']);
                exit;
            }
        }
        return self::$pdo;
    }
}
