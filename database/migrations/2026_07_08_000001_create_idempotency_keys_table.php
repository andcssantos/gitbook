<?php

return new class {
    public function up(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS idempotency_keys (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            scope VARCHAR(120) NOT NULL,
            key_hash CHAR(64) NOT NULL,
            request_hash CHAR(64) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'processing',
            response_payload LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            expires_at DATETIME NOT NULL,
            UNIQUE KEY uniq_idempotency_scope_key (scope, key_hash),
            KEY idx_idempotency_expires_at (expires_at),
            KEY idx_idempotency_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS idempotency_keys');
    }
};
