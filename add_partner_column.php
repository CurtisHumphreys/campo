<?php
require_once __DIR__ . '/src/Database.php';

try {
    $db = Database::connect();
    
    // Check if column exists
    $stmt = $db->query("DESCRIBE members");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('partner_id', $columns)) {
        echo "Adding partner_id column...\n";
        $db->exec("ALTER TABLE members ADD COLUMN partner_id INT NULL DEFAULT NULL");
        echo "Column added successfully.\n";
    } else {
        echo "Column 'partner_id' already exists.\n";
    }
    
    // Add Foreign Key ideally, but SQLite/MySQL syntax differs slightly on ALTER. 
    // For now, loose coupling is fine, but let's try to index it.
    // $db->exec("CREATE INDEX idx_partner ON members(partner_id)");

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
