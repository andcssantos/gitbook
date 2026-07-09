<?php

return [
    'default' => $_ENV['APP_ENV'] ?? 'dev',
    'migrations_table' => $_ENV['DB_MIGRATIONS_TABLE'] ?? 'gb_migrations',
    'paths' => [
        'migrations' => __DIR__ . '/../database/migrations',
        'seeds' => __DIR__ . '/../database/seeds',
    ],
];
