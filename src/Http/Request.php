<?php

namespace App\Http;

use App\Utils\Config;

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
            $body = self::rawBody();
            if ($body === '') {
                $cache = [];
            } else {
                $decoded = json_decode($body, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                    throw new HttpException('Malformed JSON request body.', 400);
                }

                $cache = $decoded;
            }
        }

        return $cache;
    }

    public static function rawBody(): string
    {
        static $cache = null;

        if ($cache === null) {
            $input = file_get_contents('php://input');
            $cache = $input === false ? '' : $input;
            $maxBytes = (int) Config::get('security.request.max_body_bytes', 1048576);
            if ($maxBytes > 0 && strlen($cache) > $maxBytes) {
                throw new HttpException('Request body too large.', 413);
            }
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

        parse_str(self::rawBody(), $parsedInput);
        return $parsedInput;
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

    public static function path(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    public static function ip(): string
    {
        $trustProxy = (bool) \App\Utils\Config::get('app.trust_proxy', false);
        $forwardedFor = $trustProxy ? self::header('X-Forwarded-For') : null;
        if (is_string($forwardedFor) && $forwardedFor !== '') {
            return trim(explode(',', $forwardedFor)[0]);
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    public static function notFoundResponse(): void
    {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['message' => 'Not Found']);
        exit;
    }
}
