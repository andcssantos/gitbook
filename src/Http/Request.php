<?php

namespace App\Http;

class Request
{
    public static function method(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    public static function query(?string $key = null)
    {
        return $key ? ($_GET[$key] ?? null) : $_GET;
    }

    public static function json(): array
    {
        static $cache = null;

        if ($cache === null) {
            $input = file_get_contents('php://input');
            $cache = json_decode($input, true) ?? [];
        }

        return $cache;
    }

    public static function body(): array
    {
        $method = self::method();
        if ($method === 'GET') {
            return self::query();
        }

        $contentType = strtolower(self::contentType());

        if (str_contains($contentType, 'application/json')) {
            return self::json();
        }

        if (str_contains($contentType, 'application/x-www-form-urlencoded') || str_contains($contentType, 'multipart/form-data')) {
            return $_POST;
        }

        parse_str(file_get_contents('php://input'), $parsedInput);
        return is_array($parsedInput) ? $parsedInput : [];
    }

    public static function has(string $key): bool
    {
        return isset($_REQUEST[$key]);
    }

    public static function input(string $key, $default = null)
    {
        return $_REQUEST[$key] ?? self::body()[$key] ?? $default;
    }

    public static function sanitize(string $key, string $type = 'string')
    {
        $input = self::input($key);

        if ($input === null) {
            return null;
        }

        return match ($type) {
            'int'       => filter_var($input, FILTER_SANITIZE_NUMBER_INT),
            'float'     => filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION),
            'email'     => filter_var($input, FILTER_SANITIZE_EMAIL),
            'url'       => filter_var($input, FILTER_SANITIZE_URL),
            'string'    => filter_var($input, FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'text'      => htmlspecialchars(strip_tags($input), ENT_QUOTES, 'UTF-8'),
            'alphanum'  => preg_replace('/[^a-zA-Z0-9]/', '', $input),
            'bool'      => filter_var($input, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            'json'      => json_decode($input, true),
            default     => htmlspecialchars((string) $input, ENT_QUOTES, 'UTF-8'),
        };
    }

    public static function isPost(): bool    { return self::method() === 'POST'; }
    public static function isGet(): bool     { return self::method() === 'GET'; }
    public static function isPut(): bool     { return self::method() === 'PUT'; }
    public static function isDelete(): bool  { return self::method() === 'DELETE'; }

    public static function header(string $key): ?string
    {
        $normalized = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
        return $_SERVER[$normalized] ?? $_SERVER[strtoupper($key)] ?? null;
    }

    public static function contentType(): string
    {
        return $_SERVER['CONTENT_TYPE'] ?? self::header('Content-Type') ?? 'application/x-www-form-urlencoded';
    }

    public static function headers(): array
    {
        return function_exists('getallheaders') ? getallheaders() : [];
    }

    public static function notFoundResponse(): void
    {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['message' => 'Not Found']);
        exit;
    }
}
