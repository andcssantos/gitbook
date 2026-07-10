<?php

return new class {
    public function up(PDO $pdo): void
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->upSqlite($pdo);
        } else {
            $this->upMysql($pdo);
        }

        $this->alterItemDefinitions($pdo);
        $this->seedCurrencies($pdo);
    }

    public function down(PDO $pdo): void
    {
        foreach ([
            'market_transactions',
            'market_listings',
            'market_price_history',
            'market_supply_demand',
            'affix_demand_weights',
            'market_base_prices',
            'player_currency_ledger',
            'player_currency_wallets',
            'currency_definitions',
        ] as $table) {
            $pdo->exec("DROP TABLE IF EXISTS {$table}");
        }
    }

    private function alterItemDefinitions(PDO $pdo): void
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $columns = $pdo->query('PRAGMA table_info(item_definitions)')->fetchAll(PDO::FETCH_ASSOC);
            $names = array_column($columns, 'name');
            if (!in_array('is_collectible', $names, true)) {
                $pdo->exec('ALTER TABLE item_definitions ADD COLUMN is_collectible INTEGER NOT NULL DEFAULT 0');
            }
            if (!in_array('is_event_item', $names, true)) {
                $pdo->exec('ALTER TABLE item_definitions ADD COLUMN is_event_item INTEGER NOT NULL DEFAULT 0');
            }

            return;
        }

        $pdo->exec('ALTER TABLE item_definitions
            ADD COLUMN IF NOT EXISTS is_collectible TINYINT(1) NOT NULL DEFAULT 0 AFTER tradeable');
        $pdo->exec('ALTER TABLE item_definitions
            ADD COLUMN IF NOT EXISTS is_event_item TINYINT(1) NOT NULL DEFAULT 0 AFTER is_collectible');
    }

    private function upMysql(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS currency_definitions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(40) NOT NULL,
            name VARCHAR(80) NOT NULL,
            symbol VARCHAR(12) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_currency_definitions_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS player_currency_wallets (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            player_id BIGINT UNSIGNED NOT NULL,
            currency_code VARCHAR(40) NOT NULL,
            balance BIGINT NOT NULL DEFAULT 0,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_player_currency_wallet (player_id, currency_code),
            CONSTRAINT fk_player_currency_wallets_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS player_currency_ledger (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            player_id BIGINT UNSIGNED NOT NULL,
            currency_code VARCHAR(40) NOT NULL,
            amount BIGINT NOT NULL,
            balance_after BIGINT NOT NULL,
            reason_code VARCHAR(60) NOT NULL,
            reference_type VARCHAR(40) NULL,
            reference_id VARCHAR(64) NULL,
            metadata_json TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_player_currency_ledger_player (player_id, currency_code, created_at),
            CONSTRAINT fk_player_currency_ledger_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS market_base_prices (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            category_code VARCHAR(60) NOT NULL,
            quality_bucket VARCHAR(40) NOT NULL,
            upgrade_tier TINYINT UNSIGNED NOT NULL DEFAULT 0,
            base_price BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_market_base_prices_profile (category_code, quality_bucket, upgrade_tier)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS affix_demand_weights (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            affix_code VARCHAR(80) NOT NULL,
            demand_weight DECIMAL(8,4) NOT NULL DEFAULT 1.0000,
            tier_weight DECIMAL(8,4) NOT NULL DEFAULT 1.0000,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_affix_demand_weights_code (affix_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS market_supply_demand (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            profile_key VARCHAR(128) NOT NULL,
            similar_listings_count INT UNSIGNED NOT NULL DEFAULT 0,
            recent_search_count INT UNSIGNED NOT NULL DEFAULT 0,
            recent_sale_count INT UNSIGNED NOT NULL DEFAULT 0,
            demand_factor DECIMAL(8,4) NOT NULL DEFAULT 1.0000,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_market_supply_demand_profile (profile_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS market_price_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            item_instance_id BIGINT UNSIGNED NOT NULL,
            profile_key VARCHAR(128) NOT NULL,
            market_value BIGINT UNSIGNED NOT NULL,
            npc_value BIGINT UNSIGNED NOT NULL,
            breakdown_json TEXT NULL,
            recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_market_price_history_item (item_instance_id, recorded_at),
            KEY idx_market_price_history_profile (profile_key, recorded_at),
            CONSTRAINT fk_market_price_history_item FOREIGN KEY (item_instance_id) REFERENCES item_instances(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS market_listings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(36) NOT NULL,
            seller_player_id BIGINT UNSIGNED NOT NULL,
            item_instance_id BIGINT UNSIGNED NOT NULL,
            profile_key VARCHAR(128) NOT NULL,
            price_premium BIGINT UNSIGNED NOT NULL,
            listing_fee_premium BIGINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            listed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sold_at DATETIME NULL,
            cancelled_at DATETIME NULL,
            buyer_player_id BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_market_listings_public_id (public_id),
            UNIQUE KEY uq_market_listings_item (item_instance_id),
            KEY idx_market_listings_status (status, listed_at),
            KEY idx_market_listings_profile (profile_key, status),
            CONSTRAINT fk_market_listings_seller FOREIGN KEY (seller_player_id) REFERENCES players(id) ON DELETE CASCADE,
            CONSTRAINT fk_market_listings_item FOREIGN KEY (item_instance_id) REFERENCES item_instances(id) ON DELETE CASCADE,
            CONSTRAINT fk_market_listings_buyer FOREIGN KEY (buyer_player_id) REFERENCES players(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS market_transactions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(36) NOT NULL,
            listing_id BIGINT UNSIGNED NOT NULL,
            buyer_player_id BIGINT UNSIGNED NOT NULL,
            seller_player_id BIGINT UNSIGNED NOT NULL,
            item_instance_id BIGINT UNSIGNED NOT NULL,
            price_premium BIGINT UNSIGNED NOT NULL,
            seller_fee_premium BIGINT UNSIGNED NOT NULL DEFAULT 0,
            seller_net_premium BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_market_transactions_public_id (public_id),
            KEY idx_market_transactions_listing (listing_id),
            CONSTRAINT fk_market_transactions_listing FOREIGN KEY (listing_id) REFERENCES market_listings(id) ON DELETE CASCADE,
            CONSTRAINT fk_market_transactions_buyer FOREIGN KEY (buyer_player_id) REFERENCES players(id) ON DELETE CASCADE,
            CONSTRAINT fk_market_transactions_seller FOREIGN KEY (seller_player_id) REFERENCES players(id) ON DELETE CASCADE,
            CONSTRAINT fk_market_transactions_item FOREIGN KEY (item_instance_id) REFERENCES item_instances(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function upSqlite(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS currency_definitions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            symbol TEXT NULL,
            status TEXT NOT NULL DEFAULT 'active',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS player_currency_wallets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            player_id INTEGER NOT NULL,
            currency_code TEXT NOT NULL,
            balance INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NULL,
            UNIQUE (player_id, currency_code),
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS player_currency_ledger (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            player_id INTEGER NOT NULL,
            currency_code TEXT NOT NULL,
            amount INTEGER NOT NULL,
            balance_after INTEGER NOT NULL,
            reason_code TEXT NOT NULL,
            reference_type TEXT NULL,
            reference_id TEXT NULL,
            metadata_json TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_player_currency_ledger_player ON player_currency_ledger(player_id, currency_code, created_at)');

        $pdo->exec("CREATE TABLE IF NOT EXISTS market_base_prices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category_code TEXT NOT NULL,
            quality_bucket TEXT NOT NULL,
            upgrade_tier INTEGER NOT NULL DEFAULT 0,
            base_price INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'active',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NULL,
            UNIQUE (category_code, quality_bucket, upgrade_tier)
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS affix_demand_weights (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            affix_code TEXT NOT NULL UNIQUE,
            demand_weight REAL NOT NULL DEFAULT 1.0,
            tier_weight REAL NOT NULL DEFAULT 1.0,
            status TEXT NOT NULL DEFAULT 'active',
            updated_at TEXT NULL
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS market_supply_demand (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            profile_key TEXT NOT NULL UNIQUE,
            similar_listings_count INTEGER NOT NULL DEFAULT 0,
            recent_search_count INTEGER NOT NULL DEFAULT 0,
            recent_sale_count INTEGER NOT NULL DEFAULT 0,
            demand_factor REAL NOT NULL DEFAULT 1.0,
            updated_at TEXT NULL
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS market_price_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_instance_id INTEGER NOT NULL,
            profile_key TEXT NOT NULL,
            market_value INTEGER NOT NULL,
            npc_value INTEGER NOT NULL,
            breakdown_json TEXT NULL,
            recorded_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (item_instance_id) REFERENCES item_instances(id) ON DELETE CASCADE
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_market_price_history_item ON market_price_history(item_instance_id, recorded_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_market_price_history_profile ON market_price_history(profile_key, recorded_at)');

        $pdo->exec("CREATE TABLE IF NOT EXISTS market_listings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            seller_player_id INTEGER NOT NULL,
            item_instance_id INTEGER NOT NULL UNIQUE,
            profile_key TEXT NOT NULL,
            price_premium INTEGER NOT NULL,
            listing_fee_premium INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'active',
            listed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sold_at TEXT NULL,
            cancelled_at TEXT NULL,
            buyer_player_id INTEGER NULL,
            FOREIGN KEY (seller_player_id) REFERENCES players(id) ON DELETE CASCADE,
            FOREIGN KEY (item_instance_id) REFERENCES item_instances(id) ON DELETE CASCADE,
            FOREIGN KEY (buyer_player_id) REFERENCES players(id) ON DELETE SET NULL
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_market_listings_status ON market_listings(status, listed_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_market_listings_profile ON market_listings(profile_key, status)');

        $pdo->exec("CREATE TABLE IF NOT EXISTS market_transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            listing_id INTEGER NOT NULL,
            buyer_player_id INTEGER NOT NULL,
            seller_player_id INTEGER NOT NULL,
            item_instance_id INTEGER NOT NULL,
            price_premium INTEGER NOT NULL,
            seller_fee_premium INTEGER NOT NULL DEFAULT 0,
            seller_net_premium INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (listing_id) REFERENCES market_listings(id) ON DELETE CASCADE,
            FOREIGN KEY (buyer_player_id) REFERENCES players(id) ON DELETE CASCADE,
            FOREIGN KEY (seller_player_id) REFERENCES players(id) ON DELETE CASCADE,
            FOREIGN KEY (item_instance_id) REFERENCES item_instances(id) ON DELETE CASCADE
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_market_transactions_listing ON market_transactions(listing_id)');
    }

    private function seedCurrencies(PDO $pdo): void
    {
        foreach ([
            ['gold', 'Ouro', 'G'],
            ['premium', 'Eter Cristal', '💎'],
        ] as [$code, $name, $symbol]) {
            $existing = $pdo->prepare('SELECT id FROM currency_definitions WHERE code = :code LIMIT 1');
            $existing->execute(['code' => $code]);
            if ($existing->fetchColumn()) {
                continue;
            }

            $stmt = $pdo->prepare('INSERT INTO currency_definitions (code, name, symbol, status) VALUES (:code, :name, :symbol, :status)');
            $stmt->execute([
                'code' => $code,
                'name' => $name,
                'symbol' => $symbol,
                'status' => 'active',
            ]);
        }
    }
};
