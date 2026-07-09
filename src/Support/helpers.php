<?php

use App\Http\Request;
use App\Security\AuditLogger;
use App\Security\Csrf;
use App\Security\Idempotency;
use App\Support\DB;
use App\Validation\Validator;

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return Csrf::token();
    }
}

if (!function_exists('auth_required')) {
    function auth_required(): void
    {
        if (!\App\Utils\Construct\Auth::check()) {
            \App\Http\Response::json(['success' => false, 'message' => 'Acesso restrito.'], 401);
            exit;
        }
    }
}

if (!function_exists('rate_limit')) {
    function rate_limit(string $key, int $maxAttempts = 60, int $decaySeconds = 60): bool
    {
        return (new \App\Utils\Security\RateLimiter())->attempt($key, $maxAttempts, $decaySeconds);
    }
}

if (!function_exists('validate_request')) {
    function validate_request(array $rules, ?array $data = null): array
    {
        return Validator::make($data ?? Request::body(), $rules)->validate();
    }
}

if (!function_exists('db_transaction')) {
    function db_transaction(callable $callback): mixed
    {
        return DB::transaction($callback);
    }
}

if (!function_exists('idempotency_check')) {
    function idempotency_check(string $scope, callable $callback): mixed
    {
        return (new Idempotency())->handle($scope, $callback);
    }
}

if (!function_exists('audit_log')) {
    function audit_log(string $action, array $context = [], ?string $actor = null): void
    {
        (new AuditLogger())->log($action, $context, $actor);
    }
}
