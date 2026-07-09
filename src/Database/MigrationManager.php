<?php

namespace App\Database;

use App\Utils\Config;
use App\Utils\DB\Connection;
use PDO;
use RuntimeException;

class MigrationManager
{
    private PDO $pdo;
    private string $table;
    private string $path;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::Conn((string) Config::get('database.default', 'dev'));
        $this->table = (string) Config::get('database.migrations_table', 'gb_migrations');
        $this->path = (string) Config::get('database.paths.migrations', __DIR__ . '/../../database/migrations');
        $this->ensureTable();
    }

    public function pending(): array
    {
        return array_values(array_diff($this->migrationFiles(), $this->ran()));
    }

    public function ran(): array
    {
        $stmt = $this->pdo->query("SELECT migration FROM {$this->table} ORDER BY id ASC");

        return $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    }

    public function all(): array
    {
        $ran = array_flip($this->ran());

        return array_map(
            fn (string $file): array => [
                'migration' => $file,
                'ran' => isset($ran[$file]),
            ],
            $this->migrationFiles()
        );
    }

    public function up(): array
    {
        $pending = $this->pending();
        if ($pending === []) {
            return [];
        }

        $batch = $this->nextBatch();
        foreach ($pending as $migration) {
            $this->runInTransaction(function () use ($migration, $batch): void {
                $instance = $this->load($migration);
                $instance->up($this->pdo);
                $stmt = $this->pdo->prepare("INSERT INTO {$this->table} (migration, batch) VALUES (:migration, :batch)");
                $stmt->execute(['migration' => $migration, 'batch' => $batch]);
            });
        }

        return $pending;
    }

    public function rollback(): array
    {
        $stmt = $this->pdo->query("SELECT migration FROM {$this->table} WHERE batch = (SELECT MAX(batch) FROM {$this->table}) ORDER BY id DESC");
        $migrations = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];

        foreach ($migrations as $migration) {
            $this->runInTransaction(function () use ($migration): void {
                $instance = $this->load($migration);
                $instance->down($this->pdo);
                $delete = $this->pdo->prepare("DELETE FROM {$this->table} WHERE migration = :migration");
                $delete->execute(['migration' => $migration]);
            });
        }

        return $migrations;
    }

    public function make(string $name): string
    {
        $name = preg_replace('/[^a-zA-Z0-9_]/', '_', trim($name));
        if (!$name) {
            throw new RuntimeException('Informe o nome da migration.');
        }

        if (!is_dir($this->path)) {
            mkdir($this->path, 0755, true);
        }

        $file = date('Y_m_d_His') . '_' . $name . '.php';
        $path = $this->path . '/' . $file;
        file_put_contents($path, $this->stub());

        return $path;
    }

    private function migrationFiles(): array
    {
        if (!is_dir($this->path)) {
            return [];
        }

        $files = array_map('basename', glob($this->path . '/*.php') ?: []);
        sort($files);

        return $files;
    }

    private function load(string $migration): object
    {
        $path = $this->path . '/' . $migration;
        if (!is_file($path)) {
            throw new RuntimeException("Migration nao encontrada: {$migration}");
        }

        $instance = require $path;
        if (!is_object($instance) || !method_exists($instance, 'up') || !method_exists($instance, 'down')) {
            throw new RuntimeException("Migration invalida: {$migration}");
        }

        return $instance;
    }

    private function ensureTable(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->table} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(255) NOT NULL UNIQUE,
            batch INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function nextBatch(): int
    {
        $stmt = $this->pdo->query("SELECT MAX(batch) FROM {$this->table}");
        $max = $stmt ? (int) $stmt->fetchColumn() : 0;

        return $max + 1;
    }

    private function runInTransaction(callable $callback): void
    {
        $started = !$this->pdo->inTransaction();
        if ($started) {
            $this->pdo->beginTransaction();
        }

        try {
            $callback();
            if ($started) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($started && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    private function stub(): string
    {
        return <<<'PHP'
<?php

use PDO;

return new class {
    public function up(PDO $pdo): void
    {
        // $pdo->exec('CREATE TABLE example (...)');
    }

    public function down(PDO $pdo): void
    {
        // $pdo->exec('DROP TABLE IF EXISTS example');
    }
};
PHP;
    }
}
