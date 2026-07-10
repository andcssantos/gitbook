<?php

namespace App\Http;

use App\Utils\Config;
use RuntimeException;

class Middleware
{
    private static array $map = [];

    public static function bootstrap(): void
    {
        self::$map = (array) Config::get('middleware.aliases', []);

        if (empty(self::$map)) {
            self::$map = [
                'auth' => \App\Middlewares\AuthMiddleware::class,
            ];
        }
    }

    public static function alias(string $name, string $middlewareClass): void
    {
        self::$map[$name] = $middlewareClass;
    }

    public static function handle(array $middlewares): void
    {
        foreach ($middlewares as $middleware) {
            [$middlewareClass, $params] = self::resolveMiddleware((string) $middleware);
            $instance = new $middlewareClass();

            if (!method_exists($instance, 'handle')) {
                throw new RuntimeException("Middleware '{$middlewareClass}' precisa implementar handle().");
            }

            $instance->handle(new Request(), new Response(), ...$params);
        }
    }

    private static function resolveMiddleware(string $middleware): array
    {
        $parts = explode(':', $middleware, 2);
        $name = $parts[0];
        $params = isset($parts[1]) ? array_map('trim', explode(',', $parts[1])) : [];

        if (isset(self::$map[$name])) {
            if ($name === 'validate' && isset($parts[1])) {
                return [self::$map[$name], [$parts[1]]];
            }

            return [self::$map[$name], $params];
        }

        if (class_exists($name)) {
            return [$name, $params];
        }

        throw new RuntimeException("Middleware '{$middleware}' nao encontrado.");
    }
}

Middleware::bootstrap();
