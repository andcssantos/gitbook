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
        foreach ($this->normalizeRules($rules) as $rule) {
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

    /** @param list<string> $rules */
    private function normalizeRules(array $rules): array
    {
        if ($rules === []) {
            return [];
        }

        return $this->splitFieldRules(implode(',', $rules));
    }

    /** @return list<string> */
    private function splitFieldRules(string $combined): array
    {
        $fields = [];
        $buffer = '';

        foreach (explode(',', $combined) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if ($buffer !== '' && !str_contains($part, '=')) {
                $buffer .= ',' . $part;
                continue;
            }

            if ($buffer !== '') {
                $fields[] = $buffer;
            }

            $buffer = $part;
        }

        if ($buffer !== '') {
            $fields[] = $buffer;
        }

        return $fields;
    }
}
