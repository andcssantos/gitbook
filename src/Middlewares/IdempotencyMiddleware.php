<?php

namespace App\Middlewares;

use App\Http\Request;
use App\Http\Response;
use App\Security\Idempotency;

class IdempotencyMiddleware
{
    public function handle(Request $request, Response $response, ?string $scope = null): void
    {
        if (!in_array($request::method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }

        $key = (new Idempotency())->key();
        if ($key) {
            return;
        }

        Response::json(['success' => false, 'error' => true, 'message' => 'Missing Idempotency-Key'], 428);
        exit;
    }
}
