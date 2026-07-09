<?php

namespace App\Middlewares;

use App\Http\Request;
use App\Http\Response;
use App\Utils\Config;
use App\Utils\Security\NonceStore;
use App\Utils\Security\RequestSigner;
use RuntimeException;

class SignedRequestMiddleware
{
    public function handle(Request $request, Response $response, ?string $ttlSeconds = null): void
    {
        $ttl = max(1, (int) ($ttlSeconds ?? Config::get('security.signed_requests.ttl_seconds', 120)));
        $nonceTtl = max($ttl, (int) Config::get('security.signed_requests.nonce_ttl_seconds', 300));

        try {
            $valid = (new RequestSigner())->validate($request, $ttl, $nonceTtl, new NonceStore());
        } catch (RuntimeException) {
            $valid = false;
        }

        if ($valid) {
            return;
        }

        Response::json([
            'success' => false,
            'error' => true,
            'message' => 'Invalid request signature',
        ], 401);
        exit;
    }
}
