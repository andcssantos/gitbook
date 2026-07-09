<?php

return new class {
    public function up(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS game_audit_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            action VARCHAR(160) NOT NULL,
            actor VARCHAR(160) NULL,
            ip VARCHAR(64) NULL,
            method VARCHAR(12) NOT NULL,
            path VARCHAR(255) NOT NULL,
            context_json JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_game_audit_action_created (action, created_at),
            KEY idx_game_audit_actor_created (actor, created_at),
            KEY idx_game_audit_ip_created (ip, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS game_audit_logs');
    }
};
