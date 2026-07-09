<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Core;
use App\Core\ErrorHandler;
use App\Http\Route;
use App\Security\Session;
use App\Utils\Config;
use App\Utils\Construct\GenJwt;
use App\Utils\Functions\Layout;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../', '.env');
$dotenv->safeLoad();

Config::load(__DIR__ . '/..');
Session::start();
ErrorHandler::register();

date_default_timezone_set(Config::get('app.timezone', 'UTC'));
ini_set('display_errors', Config::get('app.debug', false) ? '1' : '0');

foreach ((array) Config::get('security.headers', []) as $name => $value) {
    if (!headers_sent()) {
        header($name . ': ' . $value);
    }
}

$routeCacheFile = Config::get('routing.route_cache_file', __DIR__ . '/cache/routes.php');
$routeCacheEnabled = filter_var($_ENV['APP_ROUTE_CACHE'] ?? false, FILTER_VALIDATE_BOOLEAN);

if (!$routeCacheEnabled || !Route::loadFromFile($routeCacheFile)) {
    $routeFile = __DIR__ . '/../src/routes/' . Layout::getSubdomainHost() . '_main.php';

    if (!is_file($routeFile)) {
        throw new RuntimeException("Arquivo de rotas nao encontrado: {$routeFile}");
    }

    require_once $routeFile;

    if ($routeCacheEnabled) {
        Route::cacheToFile($routeCacheFile);
    }
}

$jwt = new GenJwt();
$jwt->validateJwt();

Core::dispatch(Route::routes());
