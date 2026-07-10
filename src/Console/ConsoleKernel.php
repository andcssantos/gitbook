<?php

namespace App\Console;

use App\Console\Commands\CacheCommand;
use App\Console\Commands\MarketRecalculateCommand;
use App\Console\Commands\MigrationCommand;
use App\Console\Commands\ModuleCommand;
use App\Console\Commands\RouteCommand;
use App\Console\Commands\SeedCommand;
use Throwable;

class ConsoleKernel
{
    public function handle(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $args = array_slice($argv, 2);

        try {
            return match ($command) {
                'cache:clean' => (new CacheCommand())->clean(),
                'cache:clear' => (new CacheCommand())->clear(),
                'route:cache' => (new RouteCommand())->cache(),
                'route:clear' => (new RouteCommand())->clear(),
                'migrate' => (new MigrationCommand())->up(),
                'migrate:rollback' => (new MigrationCommand())->rollback(),
                'migrate:status' => (new MigrationCommand())->status(),
                'make:migration' => (new MigrationCommand())->make($args[0] ?? ''),
                'db:seed' => (new SeedCommand())->run($args[0] ?? null),
                'make:seed' => (new SeedCommand())->make($args[0] ?? ''),
                'module:list' => (new ModuleCommand())->list(),
                'module:validate' => (new ModuleCommand())->validate(),
                'module:clear' => (new ModuleCommand())->clear(),
                'module:inspect' => (new ModuleCommand())->inspect($args[0] ?? ''),
                'module:build' => (new ModuleCommand())->build(),
                'make:module' => (new ModuleCommand())->make($args[0] ?? ''),
                'make:component' => (new ModuleCommand())->makeComponent($args[0] ?? ''),
                'market:recalculate' => (new MarketRecalculateCommand())->run(),
                default => $this->help(),
            };
        } catch (Throwable $e) {
            fwrite(STDERR, "Erro: {$e->getMessage()}\n");
            return 1;
        }
    }

    private function help(): int
    {
        echo "GitBook Framework CLI\n\n";
        echo "Comandos:\n";
        echo "  cache:clean          Remove caches expirados/corrompidos\n";
        echo "  cache:clear          Remove todo o diretorio de cache\n";
        echo "  route:cache          Gera cache de rotas\n";
        echo "  route:clear          Remove cache de rotas\n";
        echo "  migrate              Executa migrations pendentes\n";
        echo "  migrate:rollback     Reverte o ultimo batch\n";
        echo "  migrate:status       Lista status das migrations\n";
        echo "  make:migration nome  Cria uma migration\n";
        echo "  db:seed [nome]       Executa seeds\n";
        echo "  make:seed nome       Cria uma seed\n";
        echo "  module:list          Lista modulos encontrados\n";
        echo "  module:validate      Valida manifests e arquivos dos modulos\n";
        echo "  module:clear         Limpa cache de modulos\n";
        echo "  module:inspect id    Mostra relatorio de um modulo\n";
        echo "  module:build         Gera relatorio/cache otimizado dos modulos\n";
        echo "  make:module nome     Cria modulo em layout/nome ou domain/layout/nome\n";
        echo "  make:component nome  Cria componente compartilhado\n";
        echo "  market:recalculate   Recalcula oferta/demanda do mercado\n";

        return 0;
    }
}
