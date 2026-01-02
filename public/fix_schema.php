<?php
require_once __DIR__ . '/../src/Database.php';

try {
    $db = Database::connect();
    
    // Check if column exists
    $stmt = $db->query("SHOW COLUMNS FROM members LIKE 'site_fee_status'");
    if ($stmt->fetch()) {
        echo json_encode(['status' => 'exists', 'message' => 'Column site_fee_status already exists.']);
    } else {
        // Add column
        $db->exec("ALTER TABLE members ADD COLUMN site_fee_status ENUM('Paid','Unpaid','Overdue','Exempt','Unknown') DEFAULT 'Unknown'");
        echo json_encode(['status' => 'success', 'message' => 'Column site_fee_status added successfully.']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
