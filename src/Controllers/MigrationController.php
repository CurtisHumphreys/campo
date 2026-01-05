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
            $this->safeAddColumn($db, 'prepayments', 'matched', "ENUM('Yes','No') DEFAULT 'No'");
            $this->safeAddColumn($db, 'prepayments', 'matched_member_id', 'INT NULL');

            // 3. payments
            $this->safeAddColumn($db, 'payments', 'camp_id', 'INT NULL');
            $this->safeAddColumn($db, 'payments', 'site_id', 'INT NULL');
            $this->safeAddColumn($db, 'payments', 'total', 'DECIMAL(10,2)');
            $this->safeAddColumn($db, 'payments', 'arrival_date', 'DATE NULL');
            $this->safeAddColumn($db, 'payments', 'departure_date', 'DATE NULL');
            
            // New Tender Columns
            $this->safeAddColumn($db, 'payments', 'tender_eftpos', 'DECIMAL(10,2) DEFAULT 0.00');
            $this->safeAddColumn($db, 'payments', 'tender_cash', 'DECIMAL(10,2) DEFAULT 0.00');
            $this->safeAddColumn($db, 'payments', 'tender_cheque', 'DECIMAL(10,2) DEFAULT 0.00');

            // New Site Type Column
            $this->safeAddColumn($db, 'payments', 'site_type', 'VARCHAR(50) DEFAULT NULL');

            // 4. sites (Map Coordinates)
            echo "Checking sites table for map coordinates...\n";
            $this->safeAddColumn($db, 'sites', 'map_x', 'DECIMAL(5,2) NULL');
            $this->safeAddColumn($db, 'sites', 'map_y', 'DECIMAL(5,2) NULL');

            // 5. Waitlist (Phone)
            echo "Checking waitlist table...\n";
            $this->safeAddColumn($db, 'waitlist', 'phone', 'VARCHAR(50) AFTER last_name');

            // 6. Camp Intranet Content
            echo "Checking camp_intranet_content table...\n";
            $this->safeCreateTable($db, 'camp_intranet_content', "
                CREATE TABLE IF NOT EXISTS camp_intranet_content (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    camp_id INT NOT NULL UNIQUE,
                    program TEXT NULL,
                    notifications TEXT NULL,
                    events TEXT NULL,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (camp_id) REFERENCES camps(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            echo "Migration Complete.\n</pre>";

        } catch (Exception $e) {
            echo "Migration Error (Partial): " . $e->getMessage() . "\n</pre>";
        }
    }

    private function safeCreateTable($db, $table, $createSql) {
        try {
            $db->exec($createSql);
            echo "Ensured table '$table' exists.\n";
        } catch (Exception $e) {
            echo "Warning: Failed to create/verify '$table': " . $e->getMessage() . "\n";
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
