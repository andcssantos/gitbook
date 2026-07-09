<?php

namespace App\Middlewares;

use App\Http\Request;
use App\Http\Response;
use App\Utils\Config;

class SecurityHeadersMiddleware
{
    public function handle(Request $request, Response $response): void
    {
        foreach ((array) Config::get('security.headers', []) as $name => $value) {
            if (!headers_sent()) {
                header($name . ': ' . $value);
            }
        }
    }
}
