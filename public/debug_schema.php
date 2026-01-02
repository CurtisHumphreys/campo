<?php
require_once __DIR__ . '/../src/Database.php';

try {
    $db = Database::connect();
    $stmt = $db->query("DESCRIBE members");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($columns);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
