<?php

return new class {
    public function up(PDO $pdo): void
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->upSqlite($pdo);
        } else {
            $this->upMysql($pdo);
        }

        $this->seed($pdo);
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS item_upgrade_events');
        $pdo->exec('DROP TABLE IF EXISTS item_socketed_gems');
        $pdo->exec('DROP TABLE IF EXISTS item_instance_sockets');
        $pdo->exec('DROP TABLE IF EXISTS item_instance_affixes');
        $pdo->exec('DROP TABLE IF EXISTS item_affix_definitions');
    }

    private function upMysql(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS item_affix_definitions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(80) NOT NULL,
            name VARCHAR(120) NOT NULL,
            affix_type VARCHAR(20) NOT NULL,
            property_definition_id BIGINT UNSIGNED NOT NULL,
            min_value DECIMAL(18,6) NOT NULL,
            max_value DECIMAL(18,6) NOT NULL,
            rarity_weight INT UNSIGNED NOT NULL DEFAULT 100,
            min_item_level INT UNSIGNED NOT NULL DEFAULT 1,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_item_affix_definitions_code (code),
            KEY idx_item_affix_property (property_definition_id),
            KEY idx_item_affix_type_status (affix_type, status),
            CONSTRAINT fk_item_affix_property FOREIGN KEY (property_definition_id) REFERENCES item_property_definitions(id) ON DELETE RESTRICT,
            CHECK (affix_type IN ('prefix','suffix','implicit','gem','upgrade'))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS item_instance_affixes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            item_instance_id BIGINT UNSIGNED NOT NULL,
            affix_definition_id BIGINT UNSIGNED NOT NULL,
            rolled_value DECIMAL(18,6) NOT NULL,
            source VARCHAR(40) NOT NULL DEFAULT 'generated',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_item_instance_affix_source (item_instance_id, affix_definition_id, source),
            KEY idx_item_instance_affixes_item (item_instance_id),
            KEY idx_item_instance_affixes_affix (affix_definition_id),
            CONSTRAINT fk_item_instance_affixes_item FOREIGN KEY (item_instance_id) REFERENCES item_instances(id) ON DELETE CASCADE,
            CONSTRAINT fk_item_instance_affixes_affix FOREIGN KEY (affix_definition_id) REFERENCES item_affix_definitions(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS item_instance_sockets (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            item_instance_id BIGINT UNSIGNED NOT NULL,
            socket_index INT UNSIGNED NOT NULL,
            socket_type VARCHAR(40) NOT NULL DEFAULT 'generic',
            status VARCHAR(20) NOT NULL DEFAULT 'empty',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_item_socket_index (item_instance_id, socket_index),
            KEY idx_item_socket_item_status (item_instance_id, status),
            CONSTRAINT fk_item_socket_item FOREIGN KEY (item_instance_id) REFERENCES item_instances(id) ON DELETE CASCADE,
            CHECK (status IN ('empty','filled','locked'))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS item_socketed_gems (
            socket_id BIGINT UNSIGNED NOT NULL,
            gem_item_instance_id BIGINT UNSIGNED NOT NULL,
            inserted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (socket_id),
            UNIQUE KEY uq_item_socketed_gem_item (gem_item_instance_id),
            CONSTRAINT fk_item_socketed_gems_socket FOREIGN KEY (socket_id) REFERENCES item_instance_sockets(id) ON DELETE CASCADE,
            CONSTRAINT fk_item_socketed_gems_gem FOREIGN KEY (gem_item_instance_id) REFERENCES item_instances(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS item_upgrade_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            item_instance_id BIGINT UNSIGNED NOT NULL,
            player_id BIGINT UNSIGNED NOT NULL,
            from_level INT UNSIGNED NOT NULL,
            to_level INT UNSIGNED NOT NULL,
            success TINYINT(1) NOT NULL DEFAULT 1,
            cost_currency_code VARCHAR(40) NULL,
            cost_amount BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_item_upgrade_item (item_instance_id, created_at),
            KEY idx_item_upgrade_player (player_id, created_at),
            CONSTRAINT fk_item_upgrade_item FOREIGN KEY (item_instance_id) REFERENCES item_instances(id) ON DELETE CASCADE,
            CONSTRAINT fk_item_upgrade_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function upSqlite(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS item_affix_definitions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            affix_type TEXT NOT NULL,
            property_definition_id INTEGER NOT NULL,
            min_value REAL NOT NULL,
            max_value REAL NOT NULL,
            rarity_weight INTEGER NOT NULL DEFAULT 100,
            min_item_level INTEGER NOT NULL DEFAULT 1,
            status TEXT NOT NULL DEFAULT 'active',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NULL,
            FOREIGN KEY (property_definition_id) REFERENCES item_property_definitions(id)
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_affix_property ON item_affix_definitions(property_definition_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_affix_type_status ON item_affix_definitions(affix_type, status)');

        $pdo->exec("CREATE TABLE IF NOT EXISTS item_instance_affixes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_instance_id INTEGER NOT NULL,
            affix_definition_id INTEGER NOT NULL,
            rolled_value REAL NOT NULL,
            source TEXT NOT NULL DEFAULT 'generated',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (item_instance_id, affix_definition_id, source),
            FOREIGN KEY (item_instance_id) REFERENCES item_instances(id) ON DELETE CASCADE,
            FOREIGN KEY (affix_definition_id) REFERENCES item_affix_definitions(id)
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS item_instance_sockets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_instance_id INTEGER NOT NULL,
            socket_index INTEGER NOT NULL,
            socket_type TEXT NOT NULL DEFAULT 'generic',
            status TEXT NOT NULL DEFAULT 'empty',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NULL,
            UNIQUE (item_instance_id, socket_index),
            FOREIGN KEY (item_instance_id) REFERENCES item_instances(id) ON DELETE CASCADE
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS item_socketed_gems (
            socket_id INTEGER NOT NULL PRIMARY KEY,
            gem_item_instance_id INTEGER NOT NULL UNIQUE,
            inserted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (socket_id) REFERENCES item_instance_sockets(id) ON DELETE CASCADE,
            FOREIGN KEY (gem_item_instance_id) REFERENCES item_instances(id)
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS item_upgrade_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_instance_id INTEGER NOT NULL,
            player_id INTEGER NOT NULL,
            from_level INTEGER NOT NULL,
            to_level INTEGER NOT NULL,
            success INTEGER NOT NULL DEFAULT 1,
            cost_currency_code TEXT NULL,
            cost_amount INTEGER NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (item_instance_id) REFERENCES item_instances(id) ON DELETE CASCADE,
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_upgrade_item ON item_upgrade_events(item_instance_id, created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_upgrade_player ON item_upgrade_events(player_id, created_at)');
    }

    private function seed(PDO $pdo): void
    {
        $this->seedPropertyDefinitions($pdo);
        $this->seedAffixes($pdo);
        $this->enableEquipAction($pdo);
    }

    private function seedPropertyDefinitions(PDO $pdo): void
    {
        $properties = [
            ['attack_power', 'Poder de ataque', 'integer', null, 1, 999999, 1],
            ['armor', 'Armadura', 'integer', null, 1, 999999, 1],
            ['max_health', 'Vida maxima', 'integer', null, 1, 999999, 1],
            ['critical_chance', 'Chance critica', 'numeric', '%', 0, 100, 1],
            ['fire_damage', 'Dano de fogo', 'integer', null, 1, 999999, 1],
            ['cold_resistance', 'Resistencia a gelo', 'numeric', '%', 0, 100, 1],
            ['socket_count', 'Engastes', 'integer', null, 0, 12, 1],
            ['upgrade_level', 'Nivel de melhoria', 'integer', null, 0, 15, 1],
        ];

        foreach ($properties as [$code, $name, $valueType, $unit, $min, $max, $marketFilterable]) {
            $existing = $pdo->prepare('SELECT id FROM item_property_definitions WHERE code = :code LIMIT 1');
            $existing->execute(['code' => $code]);
            $id = $existing->fetchColumn();

            if ($id) {
                $stmt = $pdo->prepare('UPDATE item_property_definitions SET name = :name, value_type = :value_type, unit = :unit, min_value = :min_value, max_value = :max_value, market_filterable = :market_filterable, status = :status WHERE id = :id');
                $stmt->execute([
                    'id' => $id,
                    'name' => $name,
                    'value_type' => $valueType,
                    'unit' => $unit,
                    'min_value' => $min,
                    'max_value' => $max,
                    'market_filterable' => $marketFilterable,
                    'status' => 'active',
                ]);
                continue;
            }

            $stmt = $pdo->prepare('INSERT INTO item_property_definitions (code, name, value_type, unit, min_value, max_value, market_filterable, status) VALUES (:code, :name, :value_type, :unit, :min_value, :max_value, :market_filterable, :status)');
            $stmt->execute([
                'code' => $code,
                'name' => $name,
                'value_type' => $valueType,
                'unit' => $unit,
                'min_value' => $min,
                'max_value' => $max,
                'market_filterable' => $marketFilterable,
                'status' => 'active',
            ]);
        }
    }

    private function seedAffixes(PDO $pdo): void
    {
        $propertyId = fn (string $code): int => (int) $pdo->query('SELECT id FROM item_property_definitions WHERE code = ' . $pdo->quote($code) . ' LIMIT 1')->fetchColumn();
        $affixes = [
            ['sharp', 'Afiado', 'prefix', 'attack_power', 3, 12, 100, 1],
            ['guarded', 'Guardado', 'prefix', 'armor', 5, 18, 100, 1],
            ['ember', 'Flamejante', 'prefix', 'fire_damage', 4, 16, 65, 3],
            ['frostward', 'da Geada', 'suffix', 'cold_resistance', 3, 12, 70, 2],
            ['vitality', 'da Vitalidade', 'suffix', 'max_health', 10, 45, 85, 1],
            ['precision', 'da Precisao', 'suffix', 'critical_chance', 1, 6, 55, 4],
        ];

        foreach ($affixes as [$code, $name, $type, $propertyCode, $min, $max, $weight, $level]) {
            $existing = $pdo->prepare('SELECT id FROM item_affix_definitions WHERE code = :code LIMIT 1');
            $existing->execute(['code' => $code]);
            $id = $existing->fetchColumn();
            $data = [
                'code' => $code,
                'name' => $name,
                'affix_type' => $type,
                'property_definition_id' => $propertyId($propertyCode),
                'min_value' => $min,
                'max_value' => $max,
                'rarity_weight' => $weight,
                'min_item_level' => $level,
                'status' => 'active',
            ];

            if ($id) {
                $stmt = $pdo->prepare('UPDATE item_affix_definitions SET name = :name, affix_type = :affix_type, property_definition_id = :property_definition_id, min_value = :min_value, max_value = :max_value, rarity_weight = :rarity_weight, min_item_level = :min_item_level, status = :status WHERE id = :id');
                $stmt->execute($data + ['id' => $id]);
                continue;
            }

            $stmt = $pdo->prepare('INSERT INTO item_affix_definitions (code, name, affix_type, property_definition_id, min_value, max_value, rarity_weight, min_item_level, status) VALUES (:code, :name, :affix_type, :property_definition_id, :min_value, :max_value, :rarity_weight, :min_item_level, :status)');
            $stmt->execute($data);
        }
    }

    private function enableEquipAction(PDO $pdo): void
    {
        $actionId = $pdo->query("SELECT id FROM item_action_definitions WHERE code = 'EQUIP' LIMIT 1")->fetchColumn();
        if (!$actionId) {
            return;
        }

        $existing = $pdo->prepare('SELECT id FROM item_action_rules WHERE action_definition_id = :action_definition_id AND rule_type = :rule_type AND reference_code = :reference_code LIMIT 1');
        $existing->execute([
            'action_definition_id' => $actionId,
            'rule_type' => 'HAS_EQUIP_SLOT',
            'reference_code' => '',
        ]);
        $id = $existing->fetchColumn();

        if ($id) {
            $stmt = $pdo->prepare('UPDATE item_action_rules SET enabled = 1, priority = 20 WHERE id = :id');
            $stmt->execute(['id' => $id]);
            return;
        }

        $stmt = $pdo->prepare('INSERT INTO item_action_rules (action_definition_id, rule_type, reference_code, enabled, priority) VALUES (:action_definition_id, :rule_type, :reference_code, 1, 20)');
        $stmt->execute([
            'action_definition_id' => $actionId,
            'rule_type' => 'HAS_EQUIP_SLOT',
            'reference_code' => '',
        ]);
    }
};
