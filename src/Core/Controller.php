<?php

namespace App\Core;

use App\Http\Response;
use App\Security\AuditLogger;
use App\Security\Idempotency;
use App\Support\DB;
use App\Validation\Validator;

abstract class Controller
{
    protected function json(mixed $data = [], int $status = 200, array $headers = []): void
    {
        Response::json($data, $status, $headers);
    }

    protected function success(mixed $data = [], string $message = 'OK', int $status = 200): void
    {
        Response::json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    protected function fail(string $message, int $status = 400, array $errors = []): void
    {
        Response::json([
            'success' => false,
            'error' => true,
            'message' => $message,
            'errors' => $errors,
        ], $status);
    }

    protected function validate(array $data, array $rules): array
    {
        return Validator::make($data, $rules)->validate();
    }

    protected function transaction(callable $callback): mixed
    {
        return DB::transaction($callback);
    }

    protected function idempotent(string $scope, callable $callback): mixed
    {
        return (new Idempotency())->handle($scope, $callback);
    }

    protected function audit(string $action, array $context = []): void
    {
        (new AuditLogger())->log($action, $context);
    }
}
