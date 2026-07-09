<?php

namespace App\Console\Commands;

use App\Http\Route;
use App\Utils\Config;

class RouteCommand
{
    public function cache(): int
    {
        Route::clearRoutes();
        $routeFile = __DIR__ . '/../../../src/routes/' . Config::get('routing.default_system_content', 'app') . '/_main.php';

        if (!is_file($routeFile)) {
            throw new \RuntimeException("Arquivo de rotas nao encontrado: {$routeFile}");
        }

        require $routeFile;
        Route::cacheToFile((string) Config::get('routing.route_cache_file'));
        echo "Cache de rotas gerado.\n";

        return 0;
    }

    public function clear(): int
    {
        $file = (string) Config::get('routing.route_cache_file');
        if (is_file($file)) {
            @unlink($file);
        }

        echo "Cache de rotas removido.\n";
        return 0;
    }
}
