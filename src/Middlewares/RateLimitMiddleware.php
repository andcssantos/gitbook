<?php

namespace App\Middlewares;

use App\Http\Request;
use App\Http\Response;
use App\Utils\Config;
use App\Utils\Security\RateLimiter;

class RateLimitMiddleware
{
    public function handle(Request $request, Response $response, ?string $maxAttempts = null, ?string $decaySeconds = null): void
    {
        $max = max(1, (int) ($maxAttempts ?? Config::get('security.rate_limit.max_attempts', 120)));
        $decay = max(1, (int) ($decaySeconds ?? Config::get('security.rate_limit.decay_seconds', 60)));
        $key = implode('|', [$request::ip(), $request::method(), $request::path()]);
        $limiter = new RateLimiter();

        if ($limiter->attempt($key, $max, $decay)) {
            return;
        }

        $retryAfter = $limiter->retryAfter($key);
        Response::json([
            'success' => false,
            'error' => true,
            'message' => 'Too many requests',
            'retry_after' => $retryAfter,
        ], 429, [
            'Retry-After' => (string) $retryAfter,
        ]);
        exit;
    }
}
