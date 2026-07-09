<?php

namespace Tests\Http;

use App\Http\Route;
use PHPUnit\Framework\TestCase;

class ApiRouteTest extends TestCase
{
    protected function setUp(): void
    {
        Route::clearRoutes();
    }

    public function testSecureActionRouteUsesProtectionMiddlewareStack(): void
    {
        require __DIR__ . '/../../src/routes/app/api/RoutesSecureAction.php';

        $route = Route::routes()['POST']['/api/example/action'] ?? null;

        $this->assertNotNull($route);
        $this->assertSame('api.example.action.store', $route['name']);
        $this->assertSame('App/Api/SecureActionController@store', $route['action']);
        $this->assertSame([
            'auth',
            'csrf',
            'rateLimit:30,60',
            'idempotency:api.example.action',
            'validate:action=required|string|max:80,client_tick=required|int|min:0,nonce=required|string|max:120',
            'audit:api.example.action',
        ], $route['options']['middleware']);
    }
}
