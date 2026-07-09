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

    public function testAuthRoutesUseExpectedMiddlewareStack(): void
    {
        require __DIR__ . '/../../src/routes/app/api/RoutesAuth.php';

        $routes = Route::routes();

        $this->assertSame([
            'csrf',
            'rateLimit:10,60',
            'validate:email=required|email|max:160,password=required|string|min:8|max:255',
            'audit:auth.login',
        ], $routes['POST']['/api/auth/login']['options']['middleware']);

        $this->assertSame([
            'auth',
            'csrf',
            'audit:auth.logout',
        ], $routes['POST']['/api/auth/logout']['options']['middleware']);

        $this->assertSame([
            'auth',
        ], $routes['GET']['/api/auth/me']['options']['middleware']);
    }

    public function testInventoryMoveRouteUsesExpectedMiddlewareStack(): void
    {
        require __DIR__ . '/../../src/routes/app/api/RoutesInventory.php';

        $indexRoute = Route::routes()['GET']['/api/inventory'] ?? null;
        $this->assertNotNull($indexRoute);
        $this->assertSame('api.inventory.index', $indexRoute['name']);
        $this->assertSame([
            'auth',
            'rateLimit:120,60',
        ], $indexRoute['options']['middleware']);

        $route = Route::routes()['POST']['/api/inventory/move'] ?? null;

        $this->assertNotNull($route);
        $this->assertSame('api.inventory.move', $route['name']);
        $this->assertSame([
            'auth',
            'csrf',
            'rateLimit:60,60',
            'idempotency:api.inventory.move',
            'validate:item_public_id=required|string|max:64,source_container_public_id=required|string|max:64,target_container_public_id=required|string|max:64,grid_x=required|int|min:0,grid_y=required|int|min:0,expected_placement_version=required|int|min:1',
            'audit:inventory.move',
        ], $route['options']['middleware']);

        $mergeRoute = Route::routes()['POST']['/api/inventory/stacks/merge'] ?? null;
        $this->assertNotNull($mergeRoute);
        $this->assertSame('api.inventory.stacks.merge', $mergeRoute['name']);
        $this->assertSame([
            'auth',
            'csrf',
            'rateLimit:60,60',
            'idempotency:api.inventory.stacks.merge',
            'validate:source_item_public_id=required|string|max:64,target_item_public_id=required|string|max:64,quantity=required|int|min:1',
            'audit:inventory.stacks.merge',
        ], $mergeRoute['options']['middleware']);

        $splitRoute = Route::routes()['POST']['/api/inventory/stacks/split'] ?? null;
        $this->assertNotNull($splitRoute);
        $this->assertSame('api.inventory.stacks.split', $splitRoute['name']);
        $this->assertSame([
            'auth',
            'csrf',
            'rateLimit:60,60',
            'idempotency:api.inventory.stacks.split',
            'validate:source_item_public_id=required|string|max:64,source_container_public_id=required|string|max:64,target_container_public_id=required|string|max:64,quantity=required|int|min:1,grid_x=required|int|min:0,grid_y=required|int|min:0,expected_placement_version=required|int|min:1',
            'audit:inventory.stacks.split',
        ], $splitRoute['options']['middleware']);
    }
}
