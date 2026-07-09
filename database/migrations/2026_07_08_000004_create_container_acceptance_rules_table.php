<?php

return new class {
    public function up(PDO $pdo): void
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->upSqlite($pdo);
            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS container_acceptance_rules (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            container_definition_id BIGINT UNSIGNED NOT NULL,
            rule_type VARCHAR(40) NOT NULL,
            reference_code VARCHAR(80) NOT NULL DEFAULT '',
            allow TINYINT(1) NOT NULL DEFAULT 1,
            priority INT NOT NULL DEFAULT 100,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_container_acceptance_rule (container_definition_id, rule_type, reference_code),
            KEY idx_container_acceptance_container_priority (container_definition_id, priority),
            KEY idx_container_acceptance_type_reference (rule_type, reference_code),
            CONSTRAINT fk_container_acceptance_definition FOREIGN KEY (container_definition_id) REFERENCES container_definitions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS container_acceptance_rules');
    }

    private function upSqlite(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS container_acceptance_rules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            container_definition_id INTEGER NOT NULL,
            rule_type TEXT NOT NULL,
            reference_code TEXT NOT NULL DEFAULT '',
            allow INTEGER NOT NULL DEFAULT 1,
            priority INTEGER NOT NULL DEFAULT 100,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NULL,
            UNIQUE (container_definition_id, rule_type, reference_code),
            FOREIGN KEY (container_definition_id) REFERENCES container_definitions(id) ON DELETE CASCADE
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_container_acceptance_container_priority ON container_acceptance_rules(container_definition_id, priority)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_container_acceptance_type_reference ON container_acceptance_rules(rule_type, reference_code)');
    }
};
