<?php

return [
    'default_system_content' => $_ENV['DEFAULT_SYSTEM_CONTENT'] ?? 'App',
    'default_dashboard_content' => $_ENV['DEFAULT_DASHBOARD_CONTENT'] ?? 'Dashboard',
    'default_website_content' => $_ENV['DEFAULT_WEBSITE_CONTENT'] ?? 'Website',
    'route_cache_file' => __DIR__ . '/../bootstrap/cache/routes.php',
];
