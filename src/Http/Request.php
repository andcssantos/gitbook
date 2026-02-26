<?php

namespace App\Http;

class Request
{
    /**
     * Retorna o método HTTP da requisição (GET, POST, etc.).
     */
    public static function method(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    /**
     * Retorna um parâmetro específico da query string ou todos.
     *
     * @param string|null $key
     * @return array|string|null
     */
    public static function query(?string $key = null)
    {
        return $key ? ($_GET[$key] ?? null) : $_GET;
    }

    /**
     * Retorna os dados do corpo da requisição, interpretando como JSON.
     *
     * @return array
     */
    public static function json(): array
    {
        static $cache = null;

        if ($cache === null) {
            $input = file_get_contents('php://input');
            $cache = json_decode($input, true) ?? [];
        }

        return $cache;
    }

    /**
     * Retorna os dados do corpo da requisição de acordo com o método.
     *
     * @return array
     */
    public static function body(): array
    {
        return match (self::method()) {
            'GET' => self::query(),
            'POST', 'PUT', 'DELETE' => self::json(),
            default => [],
        };
    }

    /**
     * Verifica se um parâmetro está presente na requisição (GET, POST ou COOKIE).
     */
    public static function has(string $key): bool
    {
        return isset($_REQUEST[$key]);
    }

    /**
     * Retorna o valor de um parâmetro da requisição ou um valor padrão.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function input(string $key, $default = null)
    {
        return $_REQUEST[$key] ?? $default;
    }

    /**
     * Sanitiza o valor de um parâmetro de entrada.
     *
     * @param string $key
     * @param string $type
     * @return string|int|null
     */
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
            default     => htmlspecialchars($input, ENT_QUOTES, 'UTF-8'),
        };
    }
    

    /**
     * Métodos auxiliares para verificar o método HTTP atual.
     */
    public static function isPost(): bool    { return self::method() === 'POST'; }
    public static function isGet(): bool     { return self::method() === 'GET'; }
    public static function isPut(): bool     { return self::method() === 'PUT'; }
    public static function isDelete(): bool  { return self::method() === 'DELETE'; }

    /**
     * Retorna um cabeçalho específico da requisição.
     *
     * @param string $key
     * @return string|null
     */
    public static function header(string $key): ?string
    {
        return $_SERVER[$key] ?? null;
    }

    /**
     * Retorna o tipo de conteúdo da requisição.
     *
     * @return string
     */
    public static function contentType(): string
    {
        return $_SERVER['CONTENT_TYPE'] ?? 'application/x-www-form-urlencoded';
    }

    /**
     * Retorna todos os cabeçalhos da requisição.
     *
     * @return array
     */
    public static function headers(): array
    {
        return function_exists('getallheaders') ? getallheaders() : [];
    }

    /**
     * Envia uma resposta 404 em JSON.
     */
    public static function notFoundResponse(): void
    {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['message' => 'Not Found']);
        exit;
    }
}