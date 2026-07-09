<?php

return [
    'path' => __DIR__ . '/../src/.cache',
    'default_ttl' => (int) ($_ENV['CACHE_DEFAULT_TTL'] ?? 3600),
    'ttl_profiles' => [
        'hot' => (int) ($_ENV['CACHE_TTL_HOT'] ?? 15),
        'short' => (int) ($_ENV['CACHE_TTL_SHORT'] ?? 60),
        'medium' => (int) ($_ENV['CACHE_TTL_MEDIUM'] ?? 300),
        'long' => (int) ($_ENV['CACHE_TTL_LONG'] ?? 3600),
        'static' => (int) ($_ENV['CACHE_TTL_STATIC'] ?? 86400),
    ],
];
