<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Utils\Config;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../', '.env');
$dotenv->safeLoad();

Config::load(__DIR__ . '/..');

date_default_timezone_set(Config::get('app.timezone', 'UTC'));
