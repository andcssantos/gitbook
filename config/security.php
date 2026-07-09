<?php

return [
    'session' => [
        'name' => $_ENV['SESSION_NAME'] ?? 'GBSESSID',
        'lifetime' => (int) ($_ENV['SESSION_LIFETIME'] ?? 0),
        'secure' => filter_var($_ENV['SESSION_SECURE'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'http_only' => filter_var($_ENV['SESSION_HTTP_ONLY'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'same_site' => $_ENV['SESSION_SAME_SITE'] ?? 'Lax',
        'strict_mode' => filter_var($_ENV['SESSION_STRICT_MODE'] ?? true, FILTER_VALIDATE_BOOLEAN),
    ],
    'rate_limit' => [
        'max_attempts' => (int) ($_ENV['RATE_LIMIT_MAX_ATTEMPTS'] ?? 120),
        'decay_seconds' => (int) ($_ENV['RATE_LIMIT_DECAY_SECONDS'] ?? 60),
    ],
    'request' => [
        'max_body_bytes' => (int) ($_ENV['REQUEST_MAX_BODY_BYTES'] ?? 1048576),
    ],
    'signed_requests' => [
        'enabled' => filter_var($_ENV['SIGNED_REQUESTS_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'ttl_seconds' => (int) ($_ENV['SIGNED_REQUEST_TTL_SECONDS'] ?? 120),
        'nonce_ttl_seconds' => (int) ($_ENV['SIGNED_REQUEST_NONCE_TTL_SECONDS'] ?? 300),
    ],
    'headers' => [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
    ],
    'sse' => [
        'allowed_origins' => array_filter(array_map(
            'trim',
            explode(',', $_ENV['SSE_ALLOWED_ORIGINS'] ?? '')
        )),
        'max_seconds' => (int) ($_ENV['SSE_MAX_SECONDS'] ?? 120),
        'heartbeat_seconds' => (int) ($_ENV['SSE_HEARTBEAT_SECONDS'] ?? 15),
    ],
];
