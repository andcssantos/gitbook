<?php

return new class {
    public function up(PDO $pdo): void
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->upSqlite($pdo);
            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS item_sets (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(80) NOT NULL,
            name VARCHAR(120) NOT NULL,
            description VARCHAR(255) NULL,
            aura_color VARCHAR(20) NOT NULL DEFAULT '#55c58a',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_item_sets_code (code),
            KEY idx_item_sets_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS item_set_pieces (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            item_set_id BIGINT UNSIGNED NOT NULL,
            item_definition_id BIGINT UNSIGNED NOT NULL,
            piece_key VARCHAR(80) NOT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 100,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_item_set_piece_definition (item_set_id, item_definition_id),
            KEY idx_item_set_pieces_definition (item_definition_id),
            CONSTRAINT fk_item_set_pieces_set FOREIGN KEY (item_set_id) REFERENCES item_sets(id) ON DELETE CASCADE,
            CONSTRAINT fk_item_set_pieces_definition FOREIGN KEY (item_definition_id) REFERENCES item_definitions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS item_set_bonuses (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            item_set_id BIGINT UNSIGNED NOT NULL,
            required_pieces INT UNSIGNED NOT NULL,
            property_definition_id BIGINT UNSIGNED NOT NULL,
            numeric_value DECIMAL(18,6) NULL,
            integer_value BIGINT NULL,
            description VARCHAR(180) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_item_set_bonus (item_set_id, required_pieces, property_definition_id),
            KEY idx_item_set_bonus_property (property_definition_id),
            CONSTRAINT fk_item_set_bonuses_set FOREIGN KEY (item_set_id) REFERENCES item_sets(id) ON DELETE CASCADE,
            CONSTRAINT fk_item_set_bonuses_property FOREIGN KEY (property_definition_id) REFERENCES item_property_definitions(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS item_set_bonuses');
        $pdo->exec('DROP TABLE IF EXISTS item_set_pieces');
        $pdo->exec('DROP TABLE IF EXISTS item_sets');
    }

    private function upSqlite(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS item_sets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            description TEXT NULL,
            aura_color TEXT NOT NULL DEFAULT '#55c58a',
            status TEXT NOT NULL DEFAULT 'active',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NULL
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_sets_status ON item_sets(status)');

        $pdo->exec("CREATE TABLE IF NOT EXISTS item_set_pieces (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_set_id INTEGER NOT NULL,
            item_definition_id INTEGER NOT NULL,
            piece_key TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 100,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (item_set_id, item_definition_id),
            FOREIGN KEY (item_set_id) REFERENCES item_sets(id) ON DELETE CASCADE,
            FOREIGN KEY (item_definition_id) REFERENCES item_definitions(id) ON DELETE CASCADE
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_set_pieces_definition ON item_set_pieces(item_definition_id)');

        $pdo->exec("CREATE TABLE IF NOT EXISTS item_set_bonuses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_set_id INTEGER NOT NULL,
            required_pieces INTEGER NOT NULL,
            property_definition_id INTEGER NOT NULL,
            numeric_value REAL NULL,
            integer_value INTEGER NULL,
            description TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (item_set_id, required_pieces, property_definition_id),
            FOREIGN KEY (item_set_id) REFERENCES item_sets(id) ON DELETE CASCADE,
            FOREIGN KEY (property_definition_id) REFERENCES item_property_definitions(id)
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_set_bonus_property ON item_set_bonuses(property_definition_id)');
    }
};
