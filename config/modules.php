<?php

return [
    'base_path' => __DIR__ . '/../public/views',
    'asset_base_url' => 'views',
    'libs_base_url' => 'assets/libs',
    'manifest_file' => $_ENV['MOD_JSON_FILE'] ?? 'module.json',
    'index_file' => $_ENV['MOD_INDEX_FILE'] ?? 'index.php',
    'cache_ttl' => (int) ($_ENV['MODULE_CACHE_TTL'] ?? 3600),
    'allowed_external_hosts' => array_filter(array_map(
        'trim',
        explode(',', $_ENV['MODULE_ALLOWED_EXTERNAL_HOSTS'] ?? '')
    )),
];
