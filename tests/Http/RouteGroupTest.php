<?php

namespace Tests\Http;

use App\Http\Route;
use PHPUnit\Framework\TestCase;

class RouteGroupTest extends TestCase
{
    protected function setUp(): void
    {
        Route::clearRoutes();
    }

    public function testGroupDoesNotOverwriteExistingRootRoute(): void
    {
        Route::get('/', 'App/Website/HomeController@index', ['as' => 'website.home']);

        Route::group(['prefix' => '/dashboard', 'middleware' => ['auth']], function (): void {
            Route::get('/', 'App/Dashboard/HomeController@index', ['as' => 'dashboard.home']);
        });

        $routes = Route::routes();

        $this->assertArrayHasKey('/', $routes['GET']);
        $this->assertArrayHasKey('/dashboard', $routes['GET']);
        $this->assertSame('website.home', $routes['GET']['/']['name']);
        $this->assertSame('dashboard.home', $routes['GET']['/dashboard']['name']);
        $this->assertSame(['auth'], $routes['GET']['/dashboard']['options']['middleware']);
    }

    public function testNamedUrlReplacesTypedParameters(): void
    {
        Route::get('/users/{id:int}', 'UserController@show', ['as' => 'users.show']);

        $this->assertSame('/users/10', Route::url('users.show', ['id' => 10]));
    }
}
