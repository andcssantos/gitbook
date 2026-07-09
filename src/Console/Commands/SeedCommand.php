<?php

namespace App\Console\Commands;

use App\Utils\Config;
use App\Utils\DB\Connection;
use PDO;
use RuntimeException;

class SeedCommand
{
    public function run(?string $name = null): int
    {
        $path = (string) Config::get('database.paths.seeds', __DIR__ . '/../../../database/seeds');
        $files = $name ? [$path . '/' . $name] : (glob($path . '/*.php') ?: []);
        $pdo = Connection::Conn((string) Config::get('database.default', 'dev'));

        foreach ($files as $file) {
            if (!str_ends_with($file, '.php')) {
                $file .= '.php';
            }

            $seed = require $file;
            if (!is_callable($seed)) {
                throw new RuntimeException("Seed invalida: {$file}");
            }

            $started = !$pdo->inTransaction();
            if ($started) {
                $pdo->beginTransaction();
            }

            try {
                $seed($pdo);
                if ($started) {
                    $pdo->commit();
                }
            } catch (\Throwable $e) {
                if ($started && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $e;
            }

            echo "Seed executada: " . basename($file) . "\n";
        }

        return 0;
    }

    public function make(string $name): int
    {
        $name = preg_replace('/[^a-zA-Z0-9_]/', '_', trim($name));
        if (!$name) {
            throw new RuntimeException('Informe o nome da seed.');
        }

        $path = (string) Config::get('database.paths.seeds', __DIR__ . '/../../../database/seeds');
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $file = $path . '/' . $name . '.php';
        file_put_contents($file, <<<'PHP'
<?php

use PDO;

return function (PDO $pdo): void {
    // $pdo->prepare('INSERT INTO table_name (name) VALUES (:name)')->execute(['name' => 'Example']);
};
PHP);

        echo "Seed criada: {$file}\n";
        return 0;
    }
}
