<?php
require_once __DIR__ . '/src/Database.php';

try {
    $db = Database::connect();
    $stmt = $db->query("DESCRIBE members");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Columns in 'members' table:\n";
    foreach ($columns as $col) {
        echo "- " . $col['Field'] . " (" . $col['Type'] . ")\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
