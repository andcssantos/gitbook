<?php

namespace App\Http;

use App\Utils\Config;

class Middleware
{
    /**
     * Mapeamento de 'apelidos' para as classes reais.
     */
    private static array $map = [];

    /**
     * Executa a fila de middlewares de uma rota.
     */
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
            $middlewareClass = self::resolveMiddlewareClass($middleware);
            $instance = new $middlewareClass();

            if (!method_exists($instance, 'handle')) {
                throw new \RuntimeException("Middleware '{$middlewareClass}' precisa implementar handle().");
            }

            $instance->handle(new Request(), new Response());
        }
    }

    private static function resolveMiddlewareClass(string $middleware): string
    {
        if (isset(self::$map[$middleware])) {
            return self::$map[$middleware];
        }

        if (class_exists($middleware)) {
            return $middleware;
        }

        throw new \RuntimeException("Middleware '{$middleware}' não encontrado.");
    }
}

Middleware::bootstrap();
