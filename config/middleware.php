<?php

return [
    'aliases' => [
        'auth' => \App\Middlewares\AuthMiddleware::class,
        'rateLimit' => \App\Middlewares\RateLimitMiddleware::class,
        'signed' => \App\Middlewares\SignedRequestMiddleware::class,
        'securityHeaders' => \App\Middlewares\SecurityHeadersMiddleware::class,
        'csrf' => \App\Middlewares\CsrfMiddleware::class,
        'idempotency' => \App\Middlewares\IdempotencyMiddleware::class,
        'audit' => \App\Middlewares\AuditMiddleware::class,
        'validate' => \App\Middlewares\ValidateMiddleware::class,
    ],
];
