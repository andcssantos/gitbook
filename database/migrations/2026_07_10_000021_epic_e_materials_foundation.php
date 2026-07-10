<?php

return new class {
    public function up(PDO $pdo): void
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->upSqlite($pdo);
        } else {
            $this->upMysql($pdo);
        }

        $this->seedActions($pdo);
        $this->seedFamilyTabs($pdo);
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS player_material_stacks');

        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return;
        }

        $pdo->exec('ALTER TABLE material_families DROP COLUMN stash_tab');
    }

    private function upMysql(PDO $pdo): void
    {
        if (!$this->columnExists($pdo, 'material_families', 'stash_tab')) {
            $pdo->exec("ALTER TABLE material_families ADD COLUMN stash_tab VARCHAR(30) NULL DEFAULT 'fragments' AFTER description");
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS player_material_stacks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            player_id BIGINT UNSIGNED NOT NULL,
            material_family_id BIGINT UNSIGNED NOT NULL,
            material_origin_id BIGINT UNSIGNED NOT NULL,
            stash_tab VARCHAR(30) NOT NULL DEFAULT 'fragments',
            quantity BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_player_material_stack (player_id, material_family_id, material_origin_id),
            KEY idx_player_material_stacks_player_tab (player_id, stash_tab),
            CONSTRAINT fk_player_material_stacks_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
            CONSTRAINT fk_player_material_stacks_family FOREIGN KEY (material_family_id) REFERENCES material_families(id) ON DELETE RESTRICT,
            CONSTRAINT fk_player_material_stacks_origin FOREIGN KEY (material_origin_id) REFERENCES material_origins(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function upSqlite(PDO $pdo): void
    {
        if (!$this->columnExists($pdo, 'material_families', 'stash_tab')) {
            $pdo->exec("ALTER TABLE material_families ADD COLUMN stash_tab TEXT NULL DEFAULT 'fragments'");
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS player_material_stacks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            player_id INTEGER NOT NULL,
            material_family_id INTEGER NOT NULL,
            material_origin_id INTEGER NOT NULL,
            stash_tab TEXT NOT NULL DEFAULT 'fragments',
            quantity INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NULL,
            UNIQUE (player_id, material_family_id, material_origin_id),
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
            FOREIGN KEY (material_family_id) REFERENCES material_families(id),
            FOREIGN KEY (material_origin_id) REFERENCES material_origins(id)
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_player_material_stacks_player_tab ON player_material_stacks(player_id, stash_tab)');
    }

    private function seedActions(PDO $pdo): void
    {
        $existing = $pdo->prepare('SELECT id FROM item_action_definitions WHERE code = :code LIMIT 1');
        $existing->execute(['code' => 'DISMANTLE']);
        $actionId = $existing->fetchColumn();

        if (!$actionId) {
            $pdo->prepare('INSERT INTO item_action_definitions (code, name, description, requires_confirmation, is_destructive, status) VALUES (:code, :name, :description, :requires_confirmation, :is_destructive, :status)')
                ->execute([
                    'code' => 'DISMANTLE',
                    'name' => 'Desmanchar',
                    'description' => 'Quebra o item e envia os materiais para o inventario de materiais.',
                    'requires_confirmation' => 1,
                    'is_destructive' => 1,
                    'status' => 'active',
                ]);
            $actionId = (int) $pdo->lastInsertId();
        }

        $rule = $pdo->prepare('SELECT id FROM item_action_rules WHERE action_definition_id = :action_definition_id AND rule_type = :rule_type AND reference_code = :reference_code LIMIT 1');
        $rule->execute([
            'action_definition_id' => $actionId,
            'rule_type' => 'ALL_ITEMS',
            'reference_code' => '',
        ]);

        if (!$rule->fetchColumn()) {
            $pdo->prepare('INSERT INTO item_action_rules (action_definition_id, rule_type, reference_code, enabled, priority) VALUES (:action_definition_id, :rule_type, :reference_code, 1, 100)')
                ->execute([
                    'action_definition_id' => $actionId,
                    'rule_type' => 'ALL_ITEMS',
                    'reference_code' => '',
                ]);
        }
    }

    private function seedFamilyTabs(PDO $pdo): void
    {
        $map = [
            'metal' => 'metals',
            'currency_metal' => 'metals',
            'essence' => 'essences',
            'herb' => 'essences',
            'stone' => 'fragments',
            'wood' => 'fragments',
            'leather' => 'fragments',
        ];

        $stmt = $pdo->prepare('UPDATE material_families SET stash_tab = :stash_tab WHERE code = :code');
        foreach ($map as $code => $tab) {
            $stmt->execute(['code' => $code, 'stash_tab' => $tab]);
        }
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (($row['name'] ?? '') === $column) {
                    return true;
                }
            }

            return false;
        }

        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column LIMIT 1');
        $stmt->execute(['table' => $table, 'column' => $column]);

        return (bool) $stmt->fetchColumn();
    }
};
