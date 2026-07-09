<?php

namespace App\Middlewares;

use App\Http\Request;
use App\Http\Response;
use App\Security\AuditLogger;

class AuditMiddleware
{
    public function handle(Request $request, Response $response, ?string $action = null): void
    {
        (new AuditLogger())->log($action ?: strtolower($request::method()) . ':' . $request::path());
    }
}
