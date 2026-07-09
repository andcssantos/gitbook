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
        $pdo->exec('DROP TABLE IF EXISTS item_action_rules');
        $pdo->exec('DROP TABLE IF EXISTS item_action_definitions');
    }

    private function upMysql(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS item_action_definitions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(40) NOT NULL,
            name VARCHAR(120) NOT NULL,
            description VARCHAR(255) NULL,
            requires_confirmation TINYINT(1) NOT NULL DEFAULT 0,
            is_destructive TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_item_action_definitions_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS item_action_rules (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            action_definition_id BIGINT UNSIGNED NOT NULL,
            rule_type VARCHAR(40) NOT NULL,
            reference_code VARCHAR(80) NOT NULL DEFAULT '',
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            priority INT NOT NULL DEFAULT 100,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_item_action_rule (action_definition_id, rule_type, reference_code),
            KEY idx_item_action_rules_priority (action_definition_id, priority),
            CONSTRAINT fk_item_action_rules_definition FOREIGN KEY (action_definition_id) REFERENCES item_action_definitions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function upSqlite(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS item_action_definitions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            description TEXT NULL,
            requires_confirmation INTEGER NOT NULL DEFAULT 0,
            is_destructive INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'active',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NULL
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS item_action_rules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            action_definition_id INTEGER NOT NULL,
            rule_type TEXT NOT NULL,
            reference_code TEXT NOT NULL DEFAULT '',
            enabled INTEGER NOT NULL DEFAULT 1,
            priority INTEGER NOT NULL DEFAULT 100,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NULL,
            UNIQUE (action_definition_id, rule_type, reference_code),
            FOREIGN KEY (action_definition_id) REFERENCES item_action_definitions(id) ON DELETE CASCADE
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_action_rules_priority ON item_action_rules(action_definition_id, priority)');
    }

    private function seed(PDO $pdo): void
    {
        $actions = [
            'DISCARD' => ['Jogar fora', 'Remove o item do inventario.', 1, 1],
            'INSPECT' => ['Inspecionar', 'Mostra detalhes do item.', 0, 0],
            'OPEN' => ['Abrir', 'Abre o container vinculado ao item.', 0, 0],
            'SELL' => ['Vender', 'Vende o item no mercado.', 1, 0],
            'SEAL' => ['Selar', 'Sela o item.', 1, 0],
            'EQUIP' => ['Equipar', 'Equipa o item.', 0, 0],
            'USE' => ['Usar', 'Usa o item.', 0, 0],
        ];

        foreach ($actions as $code => [$name, $description, $requiresConfirmation, $isDestructive]) {
            $existing = $pdo->prepare('SELECT id FROM item_action_definitions WHERE code = :code LIMIT 1');
            $existing->execute(['code' => $code]);
            $id = $existing->fetchColumn();

            if ($id === false) {
                $insert = $pdo->prepare('INSERT INTO item_action_definitions (code, name, description, requires_confirmation, is_destructive, status) VALUES (:code, :name, :description, :requires_confirmation, :is_destructive, :status)');
                $insert->execute([
                    'code' => $code,
                    'name' => $name,
                    'description' => $description,
                    'requires_confirmation' => $requiresConfirmation,
                    'is_destructive' => $isDestructive,
                    'status' => 'active',
                ]);
            }
        }

        $actionId = fn (string $code): int => (int) $pdo->query("SELECT id FROM item_action_definitions WHERE code = " . $pdo->quote($code))->fetchColumn();
        $upsertRule = function (string $actionCode, string $ruleType, string $referenceCode, bool $enabled, int $priority) use ($pdo, $actionId): void {
            $definitionId = $actionId($actionCode);
            $existing = $pdo->prepare('SELECT id FROM item_action_rules WHERE action_definition_id = :action_definition_id AND rule_type = :rule_type AND reference_code = :reference_code LIMIT 1');
            $existing->execute([
                'action_definition_id' => $definitionId,
                'rule_type' => $ruleType,
                'reference_code' => $referenceCode,
            ]);
            $id = $existing->fetchColumn();

            if ($id !== false) {
                $update = $pdo->prepare('UPDATE item_action_rules SET enabled = :enabled, priority = :priority WHERE id = :id');
                $update->execute([
                    'id' => $id,
                    'enabled' => $enabled ? 1 : 0,
                    'priority' => $priority,
                ]);
                return;
            }

            $insert = $pdo->prepare('INSERT INTO item_action_rules (action_definition_id, rule_type, reference_code, enabled, priority) VALUES (:action_definition_id, :rule_type, :reference_code, :enabled, :priority)');
            $insert->execute([
                'action_definition_id' => $definitionId,
                'rule_type' => $ruleType,
                'reference_code' => $referenceCode,
                'enabled' => $enabled ? 1 : 0,
                'priority' => $priority,
            ]);
        };

        $upsertRule('DISCARD', 'ALL_ITEMS', '', true, 100);
        $upsertRule('INSPECT', 'ALL_ITEMS', '', true, 100);
        $upsertRule('OPEN', 'IS_CONTAINER', '', true, 100);

        foreach (['SELL', 'SEAL', 'EQUIP', 'USE'] as $disabledAction) {
            $upsertRule($disabledAction, 'ALL_ITEMS', '', false, 100);
        }
    }
};
