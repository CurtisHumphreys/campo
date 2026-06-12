<?php

require_once __DIR__ . '/../Database.php';

class MigrationController {
    public function migrate() {
        $db = Database::connect();

        echo "<pre>Starting Migration...\n";

        try {
            $this->migrateUsers($db);
            $this->migrateCampRates($db);
            $this->migrateCamps($db);
            $this->migrateMembers($db);
            $this->migratePrepayments($db);
            $this->migratePayments($db);
            $this->migrateHouseholds($db);
            $this->migrateSites($db);
            $this->migrateWaitlist($db);
            $this->migrateIntranet($db);
            $this->migrateOauthTokens($db);

            echo "Migration Complete.\n</pre>";
        } catch (Exception $e) {
            echo "Migration Error (Partial): " . $e->getMessage() . "\n</pre>";
            http_response_code(500);
        }
    }

    private function migrateUsers(PDO $db) {
        echo "Checking users table...\n";
        $sql = "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(255) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(50) NOT NULL DEFAULT 'admin',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $db->exec($sql);

        $this->safeModifyColumn($db, 'users', 'role', "VARCHAR(50) NOT NULL DEFAULT 'admin'");

        try {
            $db->exec("UPDATE users SET role = 'full_admin' WHERE role = 'admin'");
            $db->exec("UPDATE users SET role = 'admin' WHERE role = 'staff'");
            $db->exec("UPDATE users SET role = 'admin' WHERE role IS NULL OR role = ''");
        } catch (Exception $e) {
            echo "Warning: Failed to backfill user roles: " . $e->getMessage() . "\n";
        }
    }

    private function migrateCampRates(PDO $db) {
        echo "Checking camp_rates table...\n";
        $this->safeAddColumn($db, 'camp_rates', 'category', 'VARCHAR(50) AFTER camp_id');
        $this->safeAddColumn($db, 'camp_rates', 'item', 'VARCHAR(100) AFTER category');
        $this->safeAddColumn($db, 'camp_rates', 'user_type', 'VARCHAR(50) AFTER item');
    }

    private function migrateCamps(PDO $db) {
        echo "Checking camps table...\n";
        $this->safeAddColumn($db, 'camps', 'churchsuite_event_id', 'INT NULL AFTER status');
        $this->safeAddColumn($db, 'camps', 'churchsuite_event_identifier', 'VARCHAR(64) NULL AFTER churchsuite_event_id');
        $this->safeAddColumn($db, 'camps', 'churchsuite_event_name', 'VARCHAR(255) NULL AFTER churchsuite_event_identifier');
        $this->safeAddColumn($db, 'camps', 'churchsuite_last_sync_at', 'DATETIME NULL AFTER churchsuite_event_name');
        $this->safeAddColumn($db, 'camps', 'churchsuite_last_sync_status', 'VARCHAR(20) NULL AFTER churchsuite_last_sync_at');
        $this->safeAddColumn($db, 'camps', 'churchsuite_last_sync_message', 'TEXT NULL AFTER churchsuite_last_sync_status');
    }

    private function migrateMembers(PDO $db) {
        echo "Checking members table...\n";
        $this->safeAddColumn($db, 'members', 'email', 'VARCHAR(190) NULL AFTER last_name');
        $this->safeAddColumn($db, 'members', 'mobile', 'VARCHAR(50) NULL AFTER email');
        $this->safeAddColumn($db, 'members', 'phone', 'VARCHAR(50) NULL AFTER mobile');
        $this->safeAddColumn($db, 'members', 'churchsuite_person_type', 'VARCHAR(20) NULL AFTER phone');
        $this->safeAddColumn($db, 'members', 'churchsuite_person_id', 'VARCHAR(64) NULL AFTER churchsuite_person_type');
        $this->safeAddColumn($db, 'members', 'churchsuite_sync_status', "VARCHAR(20) NOT NULL DEFAULT 'local' AFTER churchsuite_person_id");
        $this->safeAddColumn($db, 'members', 'churchsuite_sync_note', 'TEXT NULL AFTER churchsuite_sync_status');
        $this->safeAddColumn($db, 'members', 'churchsuite_last_synced_at', 'DATETIME NULL AFTER churchsuite_sync_note');
        $this->safeAddColumn($db, 'members', 'churchsuite_payload_json', 'LONGTEXT NULL AFTER churchsuite_last_synced_at');
        $this->safeAddColumn($db, 'members', 'digital_agreement_confirmed', "TINYINT(1) NOT NULL DEFAULT 0 AFTER churchsuite_payload_json");
        $this->safeModifyColumn($db, 'members', 'email', 'VARCHAR(190) NULL');
        $this->safeAddIndex($db, 'members', 'idx_members_churchsuite_person', 'UNIQUE', ['churchsuite_person_type', 'churchsuite_person_id']);

        try {
            $db->exec("UPDATE members SET churchsuite_sync_status = 'local' WHERE churchsuite_sync_status IS NULL OR churchsuite_sync_status = ''");
            $db->exec("UPDATE members SET digital_agreement_confirmed = 0 WHERE digital_agreement_confirmed IS NULL");
        } catch (Exception $e) {
            echo "Warning: Failed to backfill member sync metadata: " . $e->getMessage() . "\n";
        }
    }

    private function migratePrepayments(PDO $db) {
        echo "Checking prepayments table...\n";
        $sql = "CREATE TABLE IF NOT EXISTS prepayments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            camp_id INT,
            imported_name VARCHAR(255),
            first_name VARCHAR(255),
            last_name VARCHAR(255),
            amount DECIMAL(10,2),
            source_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            transaction_id VARCHAR(255),
            date VARCHAR(50),
            matched_member_id INT NULL,
            original_data TEXT,
            status VARCHAR(50) DEFAULT 'Unmatched',
            source_system VARCHAR(50) NULL,
            source_record_id VARCHAR(64) NULL,
            source_currency VARCHAR(10) NULL,
            source_payment_status VARCHAR(50) NULL,
            source_arrival_date DATE NULL,
            source_departure_date DATE NULL,
            source_site_number VARCHAR(50) NULL,
            source_accommodation_type VARCHAR(100) NULL,
            source_party_size INT NULL,
            source_day_trip TINYINT(1) NULL,
            source_synced_at DATETIME NULL,
            sync_state VARCHAR(20) NOT NULL DEFAULT 'ok',
            sync_note TEXT NULL,
            UNIQUE KEY uniq_prepayment_source (camp_id, source_system, source_record_id),
            FOREIGN KEY (matched_member_id) REFERENCES members(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $db->exec($sql);

        $this->safeAddColumn($db, 'prepayments', 'first_name', 'VARCHAR(255) NULL AFTER imported_name');
        $this->safeAddColumn($db, 'prepayments', 'last_name', 'VARCHAR(255) NULL AFTER first_name');
        $this->safeAddColumn($db, 'prepayments', 'source_amount', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER amount');
        $this->safeAddColumn($db, 'prepayments', 'transaction_id', 'VARCHAR(255) NULL AFTER source_amount');
        $this->safeAddColumn($db, 'prepayments', 'date', 'VARCHAR(50) NULL AFTER transaction_id');
        $this->safeAddColumn($db, 'prepayments', 'original_data', 'TEXT NULL AFTER matched_member_id');
        $this->safeAddColumn($db, 'prepayments', 'source_system', 'VARCHAR(50) NULL AFTER status');
        $this->safeAddColumn($db, 'prepayments', 'source_record_id', 'VARCHAR(64) NULL AFTER source_system');
        $this->safeAddColumn($db, 'prepayments', 'source_currency', 'VARCHAR(10) NULL AFTER source_record_id');
        $this->safeAddColumn($db, 'prepayments', 'source_payment_status', 'VARCHAR(50) NULL AFTER source_currency');
        $this->safeAddColumn($db, 'prepayments', 'source_arrival_date', 'DATE NULL AFTER source_payment_status');
        $this->safeAddColumn($db, 'prepayments', 'source_departure_date', 'DATE NULL AFTER source_arrival_date');
        $this->safeAddColumn($db, 'prepayments', 'source_site_number', 'VARCHAR(50) NULL AFTER source_departure_date');
        $this->safeAddColumn($db, 'prepayments', 'source_accommodation_type', 'VARCHAR(100) NULL AFTER source_site_number');
        $this->safeAddColumn($db, 'prepayments', 'source_party_size', 'INT NULL AFTER source_accommodation_type');
        $this->safeAddColumn($db, 'prepayments', 'source_day_trip', 'TINYINT(1) NULL AFTER source_party_size');
        $this->safeAddColumn($db, 'prepayments', 'source_synced_at', 'DATETIME NULL AFTER source_day_trip');
        $this->safeAddColumn($db, 'prepayments', 'sync_state', "VARCHAR(20) NOT NULL DEFAULT 'ok' AFTER source_synced_at");
        $this->safeAddColumn($db, 'prepayments', 'sync_note', 'TEXT NULL AFTER sync_state');
        $this->safeAddColumn($db, 'prepayments', 'email', 'VARCHAR(190) NULL AFTER last_name');
        $this->safeAddColumn($db, 'prepayments', 'mobile', 'VARCHAR(50) NULL AFTER email');
        $this->safeAddColumn($db, 'prepayments', 'phone', 'VARCHAR(50) NULL AFTER mobile');
        $this->safeAddColumn($db, 'prepayments', 'source_person_type', 'VARCHAR(20) NULL AFTER source_record_id');
        $this->safeAddColumn($db, 'prepayments', 'source_person_id', 'VARCHAR(64) NULL AFTER source_person_type');
        $this->safeAddColumn($db, 'prepayments', 'match_source', 'VARCHAR(50) NULL AFTER matched_member_id');
        $this->safeAddColumn($db, 'prepayments', 'match_note', 'TEXT NULL AFTER match_source');
        $this->safeModifyColumn($db, 'prepayments', 'status', "VARCHAR(50) DEFAULT 'Unmatched'");
        $this->safeAddIndex($db, 'prepayments', 'uniq_prepayment_source', 'UNIQUE', ['camp_id', 'source_system', 'source_record_id']);
        $this->safeAddIndex($db, 'prepayments', 'idx_prepayment_source_person', 'INDEX', ['source_person_type', 'source_person_id']);

        try {
            $db->exec("UPDATE prepayments SET source_system = 'csv' WHERE source_system IS NULL OR source_system = ''");
            $db->exec("UPDATE prepayments SET source_amount = COALESCE(amount, 0) WHERE COALESCE(source_amount, 0) = 0 AND (source_system = 'csv' OR source_system IS NULL OR source_system = '')");
            $db->exec("UPDATE prepayments SET sync_state = 'ok' WHERE sync_state IS NULL OR sync_state = ''");
        } catch (Exception $e) {
            echo "Warning: Failed to backfill prepayment sync metadata: " . $e->getMessage() . "\n";
        }
    }

    private function migratePayments(PDO $db) {
        echo "Checking payments table...\n";
        $this->safeAddColumn($db, 'payments', 'camp_fee', 'DECIMAL(10,2)');
        $this->safeAddColumn($db, 'payments', 'site_fee', 'DECIMAL(10,2)');
        $this->safeAddColumn($db, 'payments', 'prepaid_applied', 'DECIMAL(10,2)');
        $this->safeAddColumn($db, 'payments', 'other_amount', 'DECIMAL(10,2)');
        $this->safeAddColumn($db, 'payments', 'headcount', 'INT');
        $this->safeAddColumn($db, 'payments', 'total', 'DECIMAL(10,2)');
        $this->safeAddColumn($db, 'payments', 'arrival_date', 'DATE NULL');
        $this->safeAddColumn($db, 'payments', 'departure_date', 'DATE NULL');
        $this->safeAddColumn($db, 'payments', 'tender_eftpos', 'DECIMAL(10,2) DEFAULT 0.00');
        $this->safeAddColumn($db, 'payments', 'tender_cash', 'DECIMAL(10,2) DEFAULT 0.00');
        $this->safeAddColumn($db, 'payments', 'tender_cheque', 'DECIMAL(10,2) DEFAULT 0.00');
        $this->safeAddColumn($db, 'payments', 'site_type', 'VARCHAR(50) DEFAULT NULL');
        $this->safeAddColumn($db, 'payments', 'concession', 'TINYINT(1) NOT NULL DEFAULT 0');
        $this->safeAddColumn($db, 'payments', 'site_fee_months', 'INT NULL');
        $this->safeAddColumn($db, 'payments', 'site_fee_paid_until', 'DATE NULL');
        $this->safeAddColumn($db, 'payments', 'source_checkin_id', 'INT NULL');
        $this->safeAddColumn($db, 'payments', 'created_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP');

        $db->exec("CREATE TABLE IF NOT EXISTS site_fee_audit_reviews (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id INT NOT NULL,
            site_id INT NULL,
            discrepancy_signature VARCHAR(64) NOT NULL,
            review_status VARCHAR(20) NOT NULL DEFAULT 'reviewed',
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_site_fee_audit_signature (discrepancy_signature),
            KEY idx_site_fee_audit_member (member_id),
            CONSTRAINT fk_site_fee_audit_reviews_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
            CONSTRAINT fk_site_fee_audit_reviews_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    private function migrateHouseholds(PDO $db) {
        echo "Checking household and agreement tables...\n";

        $db->exec("CREATE TABLE IF NOT EXISTS member_households (
            id INT AUTO_INCREMENT PRIMARY KEY,
            source_system VARCHAR(50) NULL,
            source_household_key VARCHAR(128) NULL,
            display_name VARCHAR(255) NOT NULL,
            insurance_status VARCHAR(20) NOT NULL DEFAULT 'Unknown',
            agreement_status VARCHAR(20) NOT NULL DEFAULT 'Not Signed',
            agreement_source VARCHAR(20) NULL,
            agreement_signed_at DATETIME NULL,
            digital_agreement_confirmed TINYINT(1) NOT NULL DEFAULT 0,
            digital_agreement_synced_at DATETIME NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_member_household_source (source_system, source_household_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS member_household_members (
            id INT AUTO_INCREMENT PRIMARY KEY,
            household_id INT NOT NULL,
            member_id INT NOT NULL,
            role_label VARCHAR(50) NULL,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            source_system VARCHAR(50) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_household_member (household_id, member_id),
            KEY idx_household_member_member (member_id),
            CONSTRAINT fk_member_household_members_household FOREIGN KEY (household_id) REFERENCES member_households(id) ON DELETE CASCADE,
            CONSTRAINT fk_member_household_members_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS member_relationships (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id INT NOT NULL,
            related_member_id INT NOT NULL,
            relationship_type VARCHAR(50) NOT NULL,
            source_system VARCHAR(50) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_member_relationship (member_id, related_member_id, relationship_type),
            CONSTRAINT fk_member_relationships_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
            CONSTRAINT fk_member_relationships_related_member FOREIGN KEY (related_member_id) REFERENCES members(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS household_agreement_documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            household_id INT NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NULL,
            mime_type VARCHAR(100) NULL,
            source_type VARCHAR(20) NOT NULL DEFAULT 'paper',
            signed_at DATETIME NULL,
            uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            CONSTRAINT fk_household_agreement_documents_household FOREIGN KEY (household_id) REFERENCES member_households(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS payment_prepayment_allocations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            payment_id INT NOT NULL,
            prepayment_id INT NOT NULL,
            source_member_id INT NULL,
            applied_to_member_id INT NULL,
            amount_applied DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_payment_prepayment_allocations_payment (payment_id),
            KEY idx_payment_prepayment_allocations_prepayment (prepayment_id),
            CONSTRAINT fk_payment_prepayment_allocations_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
            CONSTRAINT fk_payment_prepayment_allocations_prepayment FOREIGN KEY (prepayment_id) REFERENCES prepayments(id) ON DELETE CASCADE,
            CONSTRAINT fk_payment_prepayment_allocations_source_member FOREIGN KEY (source_member_id) REFERENCES members(id) ON DELETE SET NULL,
            CONSTRAINT fk_payment_prepayment_allocations_applied_member FOREIGN KEY (applied_to_member_id) REFERENCES members(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    private function migrateSites(PDO $db) {
        echo "Checking sites table for map coordinates...\n";
        $this->safeAddColumn($db, 'sites', 'map_x', 'DECIMAL(5,2) NULL');
        $this->safeAddColumn($db, 'sites', 'map_y', 'DECIMAL(5,2) NULL');
    }

    private function migrateWaitlist(PDO $db) {
        echo "Checking waitlist table...\n";
        $this->safeAddColumn($db, 'waitlist', 'phone', 'VARCHAR(50) AFTER last_name');
    }

    private function migrateIntranet(PDO $db) {
        echo "Checking camp_intranet_content table...\n";
        $sql = "CREATE TABLE IF NOT EXISTS camp_intranet_content (
            id INT AUTO_INCREMENT PRIMARY KEY,
            camp_id INT NOT NULL UNIQUE,
            program TEXT,
            program_schedule LONGTEXT NULL,
            program_image_path VARCHAR(255) NULL,
            notifications TEXT,
            events TEXT,
            theme_image_path VARCHAR(255) NULL,
            between_camps_mode TINYINT(1) NOT NULL DEFAULT 0,
            between_camps_checkout_url VARCHAR(255) NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (camp_id) REFERENCES camps(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        try {
            $db->exec($sql);
            echo "Ensured camp_intranet_content table exists.\n";
        } catch (Exception $e) {
            echo "Warning: Failed to create camp_intranet_content: " . $e->getMessage() . "\n";
        }

        $this->safeAddColumn($db, 'camp_intranet_content', 'program_schedule', 'LONGTEXT NULL AFTER program');
        $this->safeAddColumn($db, 'camp_intranet_content', 'program_image_path', 'VARCHAR(255) NULL AFTER program_schedule');
        $this->safeAddColumn($db, 'camp_intranet_content', 'theme_image_path', 'VARCHAR(255) NULL AFTER events');
        $this->safeAddColumn($db, 'camp_intranet_content', 'between_camps_mode', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER theme_image_path');
        $this->safeAddColumn($db, 'camp_intranet_content', 'between_camps_checkout_url', 'VARCHAR(255) NULL AFTER between_camps_mode');

        $db->exec("CREATE TABLE IF NOT EXISTS camp_intranet_checkins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            camp_id INT NOT NULL,
            site_number VARCHAR(30) NOT NULL,
            site_id INT NULL,
            submitter_name VARCHAR(120) NOT NULL,
            phone_number VARCHAR(60) NULL,
            email VARCHAR(190) NULL,
            arrival_date DATE NULL,
            departure_date DATE NULL,
            adults_count INT NOT NULL DEFAULT 0,
            kids_count INT NOT NULL DEFAULT 0,
            site_type VARCHAR(100) NULL,
            is_day_trip TINYINT(1) NOT NULL DEFAULT 0,
            matched_member_id INT NULL,
            matched_household_id INT NULL,
            applied_payment_id INT NULL,
            applied_member_id INT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'new',
            is_verified TINYINT(1) NOT NULL DEFAULT 0,
            verification_note VARCHAR(255) NULL,
            admin_notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_intranet_checkins_camp_status (camp_id, status, created_at),
            INDEX idx_intranet_checkins_household (matched_household_id, status),
            INDEX idx_intranet_checkins_member (matched_member_id, status),
            INDEX idx_intranet_checkins_site (site_id, status),
            CONSTRAINT fk_camp_intranet_checkins_camp FOREIGN KEY (camp_id) REFERENCES camps(id) ON DELETE CASCADE,
            CONSTRAINT fk_camp_intranet_checkins_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    private function migrateOauthTokens(PDO $db) {
        echo "Checking oauth_tokens table...\n";
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
        try {
            $db->exec($sql);
            echo "Ensured oauth_tokens table exists.\n";
        } catch (Exception $e) {
            echo "Warning: Failed to create oauth_tokens: " . $e->getMessage() . "\n";
        }
    }

    private function safeAddColumn(PDO $db, $table, $column, $definition) {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `$table` LIKE " . $db->quote($column));
            if ($stmt->rowCount() === 0) {
                $db->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
                echo "Added column '$column' to '$table'.\n";
            }
        } catch (Exception $e) {
            echo "Warning: Failed to add '$column' to '$table': " . $e->getMessage() . "\n";
        }
    }

    private function safeModifyColumn(PDO $db, $table, $column, $definition) {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `$table` LIKE " . $db->quote($column));
            if ($stmt->rowCount() > 0) {
                $db->exec("ALTER TABLE `$table` MODIFY COLUMN `$column` $definition");
                echo "Modified column '$column' on '$table'.\n";
            }
        } catch (Exception $e) {
            echo "Warning: Failed to modify '$column' on '$table': " . $e->getMessage() . "\n";
        }
    }

    private function safeAddIndex(PDO $db, $table, $indexName, $type, array $columns) {
        try {
            $stmt = $db->prepare("SHOW INDEX FROM `$table` WHERE Key_name = ?");
            $stmt->execute([$indexName]);
            if (!$stmt->fetch()) {
                $quotedColumns = array_map(function ($column) {
                    return "`$column`";
                }, $columns);
                $db->exec("ALTER TABLE `$table` ADD $type INDEX `$indexName` (" . implode(', ', $quotedColumns) . ")");
                echo "Added index '$indexName' to '$table'.\n";
            }
        } catch (Exception $e) {
            echo "Warning: Failed to add index '$indexName' to '$table': " . $e->getMessage() . "\n";
        }
    }
}
