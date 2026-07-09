<?php

namespace App\Console\Commands;

use App\Database\MigrationManager;

class MigrationCommand
{
    public function up(): int
    {
        $ran = (new MigrationManager())->up();
        echo $ran === [] ? "Nenhuma migration pendente.\n" : "Migrations executadas:\n - " . implode("\n - ", $ran) . "\n";

        return 0;
    }

    public function rollback(): int
    {
        $rolledBack = (new MigrationManager())->rollback();
        echo $rolledBack === [] ? "Nada para reverter.\n" : "Migrations revertidas:\n - " . implode("\n - ", $rolledBack) . "\n";

        return 0;
    }

    public function status(): int
    {
        foreach ((new MigrationManager())->all() as $row) {
            echo ($row['ran'] ? '[x] ' : '[ ] ') . $row['migration'] . "\n";
        }

        return 0;
    }

    public function make(string $name): int
    {
        echo "Migration criada: " . (new MigrationManager())->make($name) . "\n";

        return 0;
    }
}
