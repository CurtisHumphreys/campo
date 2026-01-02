<?php
require_once __DIR__ . '/../Database.php';

class MigrationController {
    public function migrate() {
        $db = Database::connect();
        
        echo "<pre>Starting Migration...\n";

        try {
            // 1. camp_rates
            $this->safeAddColumn($db, 'camp_rates', 'category', 'VARCHAR(50) AFTER camp_id');
            $this->safeAddColumn($db, 'camp_rates', 'item', 'VARCHAR(100) AFTER category');
            $this->safeAddColumn($db, 'camp_rates', 'user_type', 'VARCHAR(50) AFTER item');

            // 2. prepayments
            echo "Checking prepayments table...\n";
            $sql = "CREATE TABLE IF NOT EXISTS prepayments (
              id INT AUTO_INCREMENT PRIMARY KEY,
              camp_id INT,
              imported_name VARCHAR(255),
              amount DECIMAL(10,2),
              date VARCHAR(50), 
              matched_member_id INT NULL,
              original_data TEXT, 
              status ENUM('Matched','Needs Review','Unmatched') DEFAULT 'Unmatched',
              FOREIGN KEY (matched_member_id) REFERENCES members(id)
            )";
            $db->exec($sql);
            
            $this->safeAddColumn($db, 'prepayments', 'date', 'VARCHAR(50)');
            $this->safeAddColumn($db, 'prepayments', 'original_data', 'TEXT');

            // 3. payments
            echo "Checking payments table...\n";
            $this->safeAddColumn($db, 'payments', 'camp_fee', 'DECIMAL(10,2)');
            $this->safeAddColumn($db, 'payments', 'site_fee', 'DECIMAL(10,2)');
            $this->safeAddColumn($db, 'payments', 'prepaid_applied', 'DECIMAL(10,2)');
            $this->safeAddColumn($db, 'payments', 'other_amount', 'DECIMAL(10,2)');
            $this->safeAddColumn($db, 'payments', 'headcount', 'INT');
            $this->safeAddColumn($db, 'payments', 'total', 'DECIMAL(10,2)');
            $this->safeAddColumn($db, 'payments', 'arrival_date', 'DATE NULL');
            $this->safeAddColumn($db, 'payments', 'departure_date', 'DATE NULL');

            // 4. sites (Map Coordinates)
            echo "Checking sites table for map coordinates...\n";
            $this->safeAddColumn($db, 'sites', 'map_x', 'DECIMAL(5,2) NULL');
            $this->safeAddColumn($db, 'sites', 'map_y', 'DECIMAL(5,2) NULL');

            echo "Migration Complete.\n</pre>";

        } catch (Exception $e) {
            echo "Migration Error (Partial): " . $e->getMessage() . "\n</pre>";
            http_response_code(500);
        }
    }

    private function safeAddColumn($db, $table, $column, $definition) {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM $table LIKE '$column'");
            if ($stmt->rowCount() == 0) {
                $db->exec("ALTER TABLE $table ADD COLUMN $column $definition");
                echo "Added column '$column' to '$table'.\n";
            }
        } catch (Exception $e) {
            echo "Warning: Failed to add '$column' to '$table': " . $e->getMessage() . "\n";
        }
    }
}