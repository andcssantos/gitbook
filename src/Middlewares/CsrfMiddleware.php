<?php

namespace App\Middlewares;

use App\Http\Request;
use App\Http\Response;
use App\Security\Csrf;

class CsrfMiddleware
{
    public function handle(Request $request, Response $response): void
    {
        if (in_array($request::method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        if (Csrf::validate()) {
            return;
        }

        Response::json(['success' => false, 'error' => true, 'message' => 'Invalid CSRF token'], 419);
        exit;
    }
}
