<?php

namespace Tests\Http;

use App\Http\Route;
use PHPUnit\Framework\TestCase;

class RouteCacheTest extends TestCase
{
    private string $cacheFile;

    protected function setUp(): void
    {
        $this->cacheFile = __DIR__ . '/../../bootstrap/cache/test-routes.php';
        Route::clearRoutes();
        @unlink($this->cacheFile);
    }

    protected function tearDown(): void
    {
        @unlink($this->cacheFile);
    }

    public function testCanCacheAndLoadRoutesFromFile(): void
    {
        Route::get('/health', 'App/Website/HomeController@index');
        Route::cacheToFile($this->cacheFile);

        Route::clearRoutes();
        $this->assertTrue(Route::loadFromFile($this->cacheFile));

        $routes = Route::routes();
        $this->assertArrayHasKey('/health', $routes['GET']);
    }
}
