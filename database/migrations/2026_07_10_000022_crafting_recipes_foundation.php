<?php

return new class {
    public function up(PDO $pdo): void
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->upSqlite($pdo);
        } else {
            $this->upMysql($pdo);
        }
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS crafting_recipe_first_discoveries');
        $pdo->exec('DROP TABLE IF EXISTS crafting_recipe_discoveries');
    }

    private function upMysql(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS crafting_recipe_discoveries (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            recipe_code VARCHAR(80) NOT NULL,
            player_id BIGINT UNSIGNED NOT NULL,
            visibility VARCHAR(20) NOT NULL DEFAULT 'private',
            discovered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_crafting_recipe_player (recipe_code, player_id),
            KEY idx_crafting_recipe_discoveries_player (player_id),
            CONSTRAINT fk_crafting_recipe_discoveries_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS crafting_recipe_first_discoveries (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            recipe_code VARCHAR(80) NOT NULL,
            player_id BIGINT UNSIGNED NOT NULL,
            discovered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_crafting_recipe_first (recipe_code),
            CONSTRAINT fk_crafting_recipe_first_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function upSqlite(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS crafting_recipe_discoveries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            recipe_code TEXT NOT NULL,
            player_id INTEGER NOT NULL,
            visibility TEXT NOT NULL DEFAULT 'private',
            discovered_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (recipe_code, player_id),
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_crafting_recipe_discoveries_player ON crafting_recipe_discoveries(player_id)');

        $pdo->exec("CREATE TABLE IF NOT EXISTS crafting_recipe_first_discoveries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            recipe_code TEXT NOT NULL UNIQUE,
            player_id INTEGER NOT NULL,
            discovered_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
        )");
    }
};
