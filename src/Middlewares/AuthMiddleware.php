<?php

namespace App\Middlewares;

use App\Utils\Construct\Auth;
use App\Http\Response;

class AuthMiddleware
{
    public function handle($request, $response)
    {
        if (!Auth::check()) {
            Response::json([
                "success" => false,
                "message" => "Acesso restrito. Por favor, faça login."
            ], 401);
            exit;
        }
    }
}