<?php

namespace App\Middlewares;

use App\Http\Request;
use App\Http\Response;
use App\Validation\ValidationException;
use App\Validation\Validator;

class ValidateMiddleware
{
    public function handle(Request $request, Response $response, string ...$rules): void
    {
        $parsed = [];
        foreach ($rules as $rule) {
            [$field, $definition] = array_pad(explode('=', $rule, 2), 2, '');
            if ($field !== '' && $definition !== '') {
                $parsed[$field] = $definition;
            }
        }

        if ($parsed === []) {
            return;
        }

        try {
            Validator::make($request::body(), $parsed)->validate();
        } catch (ValidationException $e) {
            Response::json(['success' => false, 'error' => true, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
            exit;
        }
    }
}
