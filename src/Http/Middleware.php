<?php

namespace App\Http;

use App\Http\Request;
use App\Http\Response;

class Middleware
{
    /**
     * Mapeamento de 'apelidos' para as classes reais.
     */
    private static array $map = [
        'auth' => \App\Middlewares\AuthMiddleware::class
    ];

    /**
     * Executa a fila de middlewares de uma rota.
     */
    public static function handle(array $middlewares): void
    {
        foreach ($middlewares as $key) {
            if (!isset(self::$map[$key])) {
                throw new \Exception("Middleware '{$key}' não encontrado.");
            }

            $middlewareClass = self::$map[$key];
            $instance = new $middlewareClass();
            
            // O middleware deve retornar true para prosseguir ou interromper a execução
            $instance->handle(new Request(), new Response());
        }
    }
}