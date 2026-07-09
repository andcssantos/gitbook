<?php

return new class {
    public function up(PDO $pdo): void
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->upSqlite($pdo);
            return;
        }

        $this->upMysql($pdo);
    }

    public function down(PDO $pdo): void
    {
        foreach ([
            'player_equipment',
            'container_items',
            'container_instances',
            'container_definitions',
            'item_material_composition',
            'item_instance_properties',
            'item_instances',
            'item_property_definitions',
            'item_definitions',
            'equipment_slots',
            'material_origins',
            'material_families',
            'item_categories',
            'players',
            'accounts',
        ] as $table) {
            $pdo->exec("DROP TABLE IF EXISTS {$table}");
        }
    }

    private function upMysql(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS accounts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(36) NOT NULL,
            display_name VARCHAR(80) NOT NULL,
            email VARCHAR(160) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_accounts_public_id (public_id),
            UNIQUE KEY uq_accounts_email (email),
            KEY idx_accounts_status (status),
            CHECK (status IN ('pending','active','suspended','deleted'))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS players (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(36) NOT NULL,
            account_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(40) NOT NULL,
            avatar_key VARCHAR(80) NULL,
            level INT UNSIGNED NOT NULL DEFAULT 1,
            experience BIGINT UNSIGNED NOT NULL DEFAULT 0,
            base_expedition_seconds INT UNSIGNED NOT NULL DEFAULT 60,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_players_public_id (public_id),
            UNIQUE KEY uq_players_name (name),
            KEY idx_players_account (account_id),
            KEY idx_players_status (status),
            CONSTRAINT fk_players_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
            CHECK (status IN ('active','disabled','deleted'))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS item_categories (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(60) NOT NULL,
            name VARCHAR(100) NOT NULL,
            description TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_item_categories_code (code),
            KEY idx_item_categories_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS material_families (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(60) NOT NULL,
            name VARCHAR(100) NOT NULL,
            description TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_material_families_code (code),
            KEY idx_material_families_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS material_origins (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(80) NOT NULL,
            name VARCHAR(100) NOT NULL,
            description TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_material_origins_code (code),
            KEY idx_material_origins_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS equipment_slots (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(40) NOT NULL,
            name VARCHAR(80) NOT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_equipment_slots_code (code),
            KEY idx_equipment_slots_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS item_definitions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(100) NOT NULL,
            name VARCHAR(120) NOT NULL,
            description TEXT NULL,
            category_id BIGINT UNSIGNED NOT NULL,
            material_family_id BIGINT UNSIGNED NULL,
            stackable TINYINT(1) NOT NULL DEFAULT 0,
            max_stack INT UNSIGNED NOT NULL DEFAULT 1,
            grid_w INT UNSIGNED NOT NULL DEFAULT 1,
            grid_h INT UNSIGNED NOT NULL DEFAULT 1,
            equip_slot_code VARCHAR(40) NULL,
            is_container TINYINT(1) NOT NULL DEFAULT 0,
            tradeable TINYINT(1) NOT NULL DEFAULT 1,
            base_config JSON NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_item_definitions_code (code),
            KEY idx_item_definitions_category (category_id),
            KEY idx_item_definitions_family (material_family_id),
            KEY idx_item_definitions_status (status),
            KEY idx_item_definitions_equip_slot (equip_slot_code),
            CONSTRAINT fk_item_definitions_category FOREIGN KEY (category_id) REFERENCES item_categories(id) ON DELETE RESTRICT,
            CONSTRAINT fk_item_definitions_family FOREIGN KEY (material_family_id) REFERENCES material_families(id) ON DELETE SET NULL,
            CHECK (max_stack >= 1),
            CHECK (grid_w >= 1),
            CHECK (grid_h >= 1)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS item_property_definitions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(80) NOT NULL,
            name VARCHAR(100) NOT NULL,
            value_type VARCHAR(20) NOT NULL DEFAULT 'numeric',
            unit VARCHAR(30) NULL,
            min_value DECIMAL(18,6) NULL,
            max_value DECIMAL(18,6) NULL,
            market_filterable TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_item_property_definitions_code (code),
            KEY idx_item_property_market_filterable (market_filterable),
            KEY idx_item_property_status (status),
            CHECK (value_type IN ('numeric','integer','text','boolean'))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS item_instances (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(36) NOT NULL,
            item_definition_id BIGINT UNSIGNED NOT NULL,
            owner_player_id BIGINT UNSIGNED NULL,
            quantity INT UNSIGNED NOT NULL DEFAULT 1,
            quality_value DECIMAL(8,3) NULL,
            quality_bucket VARCHAR(30) NULL,
            material_origin_id BIGINT UNSIGNED NULL,
            item_name VARCHAR(160) NULL,
            crafted_by_player_id BIGINT UNSIGNED NULL,
            crafting_event_id BIGINT UNSIGNED NULL,
            current_durability INT UNSIGNED NULL,
            max_durability INT UNSIGNED NULL,
            bind_type VARCHAR(30) NOT NULL DEFAULT 'none',
            state VARCHAR(30) NOT NULL DEFAULT 'available',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_item_instances_public_id (public_id),
            KEY idx_item_instances_owner (owner_player_id),
            KEY idx_item_instances_definition (item_definition_id),
            KEY idx_item_instances_owner_definition (owner_player_id, item_definition_id),
            KEY idx_item_instances_quality_bucket (quality_bucket),
            KEY idx_item_instances_origin (material_origin_id),
            KEY idx_item_instances_state (state),
            KEY idx_item_instances_crafter (crafted_by_player_id),
            KEY idx_item_instances_crafting_event (crafting_event_id),
            CONSTRAINT fk_item_instances_definition FOREIGN KEY (item_definition_id) REFERENCES item_definitions(id) ON DELETE RESTRICT,
            CONSTRAINT fk_item_instances_owner FOREIGN KEY (owner_player_id) REFERENCES players(id) ON DELETE SET NULL,
            CONSTRAINT fk_item_instances_origin FOREIGN KEY (material_origin_id) REFERENCES material_origins(id) ON DELETE SET NULL,
            CONSTRAINT fk_item_instances_crafter FOREIGN KEY (crafted_by_player_id) REFERENCES players(id) ON DELETE SET NULL,
            CHECK (quantity >= 1)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS item_instance_properties (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            item_instance_id BIGINT UNSIGNED NOT NULL,
            property_definition_id BIGINT UNSIGNED NOT NULL,
            numeric_value DECIMAL(18,6) NULL,
            integer_value BIGINT NULL,
            text_value VARCHAR(255) NULL,
            source VARCHAR(60) NOT NULL DEFAULT 'generated',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_item_instance_property_source (item_instance_id, property_definition_id, source),
            KEY idx_item_instance_properties_item (item_instance_id),
            KEY idx_item_instance_properties_definition (property_definition_id),
            KEY idx_item_property_definition_numeric (property_definition_id, numeric_value),
            CONSTRAINT fk_item_instance_properties_item FOREIGN KEY (item_instance_id) REFERENCES item_instances(id) ON DELETE CASCADE,
            CONSTRAINT fk_item_instance_properties_definition FOREIGN KEY (property_definition_id) REFERENCES item_property_definitions(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS item_material_composition (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            item_instance_id BIGINT UNSIGNED NOT NULL,
            material_family_id BIGINT UNSIGNED NOT NULL,
            material_origin_id BIGINT UNSIGNED NOT NULL,
            percentage DECIMAL(6,3) NOT NULL,
            average_quality DECIMAL(8,3) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_item_material_composition_item (item_instance_id),
            KEY idx_item_material_composition_family (material_family_id),
            KEY idx_item_material_composition_origin (material_origin_id),
            CONSTRAINT fk_item_material_composition_item FOREIGN KEY (item_instance_id) REFERENCES item_instances(id) ON DELETE CASCADE,
            CONSTRAINT fk_item_material_composition_family FOREIGN KEY (material_family_id) REFERENCES material_families(id) ON DELETE RESTRICT,
            CONSTRAINT fk_item_material_composition_origin FOREIGN KEY (material_origin_id) REFERENCES material_origins(id) ON DELETE RESTRICT,
            CHECK (percentage > 0 AND percentage <= 100)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS container_definitions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(80) NOT NULL,
            name VARCHAR(120) NOT NULL,
            container_type VARCHAR(40) NOT NULL,
            grid_columns INT UNSIGNED NOT NULL,
            grid_rows INT UNSIGNED NOT NULL,
            allow_container_items TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_container_definitions_code (code),
            KEY idx_container_definitions_type (container_type),
            KEY idx_container_definitions_status (status),
            CHECK (grid_columns >= 1),
            CHECK (grid_rows >= 1)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS container_instances (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(36) NOT NULL,
            container_definition_id BIGINT UNSIGNED NOT NULL,
            owner_player_id BIGINT UNSIGNED NOT NULL,
            source_item_instance_id BIGINT UNSIGNED NULL,
            name VARCHAR(120) NOT NULL,
            grid_columns INT UNSIGNED NOT NULL,
            grid_rows INT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_container_instances_public_id (public_id),
            KEY idx_container_instances_owner_status (owner_player_id, status),
            KEY idx_container_instances_definition (container_definition_id),
            KEY idx_container_instances_source_item (source_item_instance_id),
            CONSTRAINT fk_container_instances_definition FOREIGN KEY (container_definition_id) REFERENCES container_definitions(id) ON DELETE RESTRICT,
            CONSTRAINT fk_container_instances_owner FOREIGN KEY (owner_player_id) REFERENCES players(id) ON DELETE CASCADE,
            CONSTRAINT fk_container_instances_source_item FOREIGN KEY (source_item_instance_id) REFERENCES item_instances(id) ON DELETE SET NULL,
            CHECK (grid_columns >= 1),
            CHECK (grid_rows >= 1)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS container_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            container_instance_id BIGINT UNSIGNED NOT NULL,
            item_instance_id BIGINT UNSIGNED NOT NULL,
            grid_x INT UNSIGNED NOT NULL,
            grid_y INT UNSIGNED NOT NULL,
            grid_w INT UNSIGNED NOT NULL,
            grid_h INT UNSIGNED NOT NULL,
            rotated TINYINT(1) NOT NULL DEFAULT 0,
            locked TINYINT(1) NOT NULL DEFAULT 0,
            placement_version INT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_container_items_item_instance (item_instance_id),
            KEY idx_container_items_container_position (container_instance_id, grid_y, grid_x),
            KEY idx_container_items_placement_version (placement_version),
            CONSTRAINT fk_container_items_container FOREIGN KEY (container_instance_id) REFERENCES container_instances(id) ON DELETE CASCADE,
            CONSTRAINT fk_container_items_item FOREIGN KEY (item_instance_id) REFERENCES item_instances(id) ON DELETE CASCADE,
            CHECK (grid_w >= 1),
            CHECK (grid_h >= 1)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS player_equipment (
            player_id BIGINT UNSIGNED NOT NULL,
            equipment_slot_id BIGINT UNSIGNED NOT NULL,
            item_instance_id BIGINT UNSIGNED NOT NULL,
            equipped_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (player_id, equipment_slot_id),
            UNIQUE KEY uq_player_equipment_item (item_instance_id),
            CONSTRAINT fk_player_equipment_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
            CONSTRAINT fk_player_equipment_slot FOREIGN KEY (equipment_slot_id) REFERENCES equipment_slots(id) ON DELETE RESTRICT,
            CONSTRAINT fk_player_equipment_item FOREIGN KEY (item_instance_id) REFERENCES item_instances(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function upSqlite(PDO $pdo): void
    {
        $pdo->exec('PRAGMA foreign_keys = ON');

        $pdo->exec("CREATE TABLE IF NOT EXISTS accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            display_name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'active',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NULL,
            deleted_at TEXT NULL
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_accounts_status ON accounts(status)');

        $pdo->exec("CREATE TABLE IF NOT EXISTS players (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            account_id INTEGER NOT NULL,
            name TEXT NOT NULL UNIQUE,
            avatar_key TEXT NULL,
            level INTEGER NOT NULL DEFAULT 1,
            experience INTEGER NOT NULL DEFAULT 0,
            base_expedition_seconds INTEGER NOT NULL DEFAULT 60,
            status TEXT NOT NULL DEFAULT 'active',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NULL,
            FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_players_account ON players(account_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_players_status ON players(status)');

        $this->sqliteLookup($pdo, 'item_categories');
        $this->sqliteLookup($pdo, 'material_families');
        $this->sqliteLookup($pdo, 'material_origins');

        $pdo->exec("CREATE TABLE IF NOT EXISTS equipment_slots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'active',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NULL
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_equipment_slots_status ON equipment_slots(status)');

        $pdo->exec("CREATE TABLE IF NOT EXISTS item_definitions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            description TEXT NULL,
            category_id INTEGER NOT NULL,
            material_family_id INTEGER NULL,
            stackable INTEGER NOT NULL DEFAULT 0,
            max_stack INTEGER NOT NULL DEFAULT 1,
            grid_w INTEGER NOT NULL DEFAULT 1,
            grid_h INTEGER NOT NULL DEFAULT 1,
            equip_slot_code TEXT NULL,
            is_container INTEGER NOT NULL DEFAULT 0,
            tradeable INTEGER NOT NULL DEFAULT 1,
            base_config TEXT NULL,
            status TEXT NOT NULL DEFAULT 'active',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NULL,
            FOREIGN KEY (category_id) REFERENCES item_categories(id),
            FOREIGN KEY (material_family_id) REFERENCES material_families(id) ON DELETE SET NULL
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_definitions_category ON item_definitions(category_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_definitions_family ON item_definitions(material_family_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_definitions_status ON item_definitions(status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_definitions_equip_slot ON item_definitions(equip_slot_code)');

        $pdo->exec("CREATE TABLE IF NOT EXISTS item_property_definitions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            value_type TEXT NOT NULL DEFAULT 'numeric',
            unit TEXT NULL,
            min_value REAL NULL,
            max_value REAL NULL,
            market_filterable INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'active',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NULL
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_property_market_filterable ON item_property_definitions(market_filterable)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_property_status ON item_property_definitions(status)');

        $pdo->exec("CREATE TABLE IF NOT EXISTS item_instances (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            item_definition_id INTEGER NOT NULL,
            owner_player_id INTEGER NULL,
            quantity INTEGER NOT NULL DEFAULT 1,
            quality_value REAL NULL,
            quality_bucket TEXT NULL,
            material_origin_id INTEGER NULL,
            item_name TEXT NULL,
            crafted_by_player_id INTEGER NULL,
            crafting_event_id INTEGER NULL,
            current_durability INTEGER NULL,
            max_durability INTEGER NULL,
            bind_type TEXT NOT NULL DEFAULT 'none',
            state TEXT NOT NULL DEFAULT 'available',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NULL,
            FOREIGN KEY (item_definition_id) REFERENCES item_definitions(id),
            FOREIGN KEY (owner_player_id) REFERENCES players(id) ON DELETE SET NULL,
            FOREIGN KEY (material_origin_id) REFERENCES material_origins(id) ON DELETE SET NULL,
            FOREIGN KEY (crafted_by_player_id) REFERENCES players(id) ON DELETE SET NULL
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_instances_owner ON item_instances(owner_player_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_instances_definition ON item_instances(item_definition_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_instances_owner_definition ON item_instances(owner_player_id, item_definition_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_instances_quality_bucket ON item_instances(quality_bucket)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_instances_origin ON item_instances(material_origin_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_instances_state ON item_instances(state)');

        $pdo->exec("CREATE TABLE IF NOT EXISTS item_instance_properties (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_instance_id INTEGER NOT NULL,
            property_definition_id INTEGER NOT NULL,
            numeric_value REAL NULL,
            integer_value INTEGER NULL,
            text_value TEXT NULL,
            source TEXT NOT NULL DEFAULT 'generated',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (item_instance_id, property_definition_id, source),
            FOREIGN KEY (item_instance_id) REFERENCES item_instances(id) ON DELETE CASCADE,
            FOREIGN KEY (property_definition_id) REFERENCES item_property_definitions(id)
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_instance_properties_item ON item_instance_properties(item_instance_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_instance_properties_definition ON item_instance_properties(property_definition_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_property_definition_numeric ON item_instance_properties(property_definition_id, numeric_value)');

        $pdo->exec("CREATE TABLE IF NOT EXISTS item_material_composition (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_instance_id INTEGER NOT NULL,
            material_family_id INTEGER NOT NULL,
            material_origin_id INTEGER NOT NULL,
            percentage REAL NOT NULL,
            average_quality REAL NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (item_instance_id) REFERENCES item_instances(id) ON DELETE CASCADE,
            FOREIGN KEY (material_family_id) REFERENCES material_families(id),
            FOREIGN KEY (material_origin_id) REFERENCES material_origins(id)
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_material_composition_item ON item_material_composition(item_instance_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_material_composition_family ON item_material_composition(material_family_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_material_composition_origin ON item_material_composition(material_origin_id)');

        $pdo->exec("CREATE TABLE IF NOT EXISTS container_definitions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            container_type TEXT NOT NULL,
            grid_columns INTEGER NOT NULL,
            grid_rows INTEGER NOT NULL,
            allow_container_items INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'active',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NULL
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_container_definitions_type ON container_definitions(container_type)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_container_definitions_status ON container_definitions(status)');

        $pdo->exec("CREATE TABLE IF NOT EXISTS container_instances (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            container_definition_id INTEGER NOT NULL,
            owner_player_id INTEGER NOT NULL,
            source_item_instance_id INTEGER NULL,
            name TEXT NOT NULL,
            grid_columns INTEGER NOT NULL,
            grid_rows INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'active',
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NULL,
            FOREIGN KEY (container_definition_id) REFERENCES container_definitions(id),
            FOREIGN KEY (owner_player_id) REFERENCES players(id) ON DELETE CASCADE,
            FOREIGN KEY (source_item_instance_id) REFERENCES item_instances(id) ON DELETE SET NULL
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_container_instances_owner_status ON container_instances(owner_player_id, status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_container_instances_definition ON container_instances(container_definition_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_container_instances_source_item ON container_instances(source_item_instance_id)');

        $pdo->exec("CREATE TABLE IF NOT EXISTS container_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            container_instance_id INTEGER NOT NULL,
            item_instance_id INTEGER NOT NULL UNIQUE,
            grid_x INTEGER NOT NULL,
            grid_y INTEGER NOT NULL,
            grid_w INTEGER NOT NULL,
            grid_h INTEGER NOT NULL,
            rotated INTEGER NOT NULL DEFAULT 0,
            locked INTEGER NOT NULL DEFAULT 0,
            placement_version INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NULL,
            FOREIGN KEY (container_instance_id) REFERENCES container_instances(id) ON DELETE CASCADE,
            FOREIGN KEY (item_instance_id) REFERENCES item_instances(id) ON DELETE CASCADE
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_container_items_container_position ON container_items(container_instance_id, grid_y, grid_x)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_container_items_placement_version ON container_items(placement_version)');

        $pdo->exec("CREATE TABLE IF NOT EXISTS player_equipment (
            player_id INTEGER NOT NULL,
            equipment_slot_id INTEGER NOT NULL,
            item_instance_id INTEGER NOT NULL UNIQUE,
            equipped_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (player_id, equipment_slot_id),
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
            FOREIGN KEY (equipment_slot_id) REFERENCES equipment_slots(id),
            FOREIGN KEY (item_instance_id) REFERENCES item_instances(id)
        )");
    }

    private function sqliteLookup(PDO $pdo, string $table): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$table} (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            description TEXT NULL,
            status TEXT NOT NULL DEFAULT 'active',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NULL
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$table}_status ON {$table}(status)");
    }
};
