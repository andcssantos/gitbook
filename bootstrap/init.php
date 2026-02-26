<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Core;
use App\Core\ErrorHandler;
use App\Http\Route;
use App\Utils\Config;
use App\Utils\Construct\GenJwt;
use App\Utils\Functions\Layout;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../', '.env');
$dotenv->safeLoad();

Config::load(__DIR__ . '/..');
ErrorHandler::register();

date_default_timezone_set(Config::get('app.timezone', 'UTC'));

$routeCacheFile = Config::get('routing.route_cache_file', __DIR__ . '/cache/routes.php');
$routeCacheEnabled = filter_var($_ENV['APP_ROUTE_CACHE'] ?? false, FILTER_VALIDATE_BOOLEAN);

if (!$routeCacheEnabled || !Route::loadFromFile($routeCacheFile)) {
    require_once __DIR__ . '/../src/routes/' . Layout::getSubdomainHost() . '_main.php';

    if ($routeCacheEnabled) {
        Route::cacheToFile($routeCacheFile);
    }
}

$jwt = new GenJwt();
$jwt->validateJwt();

Core::dispatch(Route::routes());
