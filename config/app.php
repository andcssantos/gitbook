<?php

return [
    'name' => $_ENV['APP_NAME'] ?? 'GitBook Framework',
    'env' => $_ENV['APP_ENV'] ?? 'production',
    'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'timezone' => $_ENV['APP_TIMEZONE'] ?? 'UTC',
    'domain' => $_ENV['DEFAULT_DOMINIO'] ?? '',
    'trust_proxy' => filter_var($_ENV['APP_TRUST_PROXY'] ?? false, FILTER_VALIDATE_BOOLEAN),
];
