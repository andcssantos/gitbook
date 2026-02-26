<?php

namespace Tests\Http;

use App\Http\Request;
use PHPUnit\Framework\TestCase;

class RequestTest extends TestCase
{
    protected function setUp(): void
    {
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        $_SERVER = [];
    }

    public function testBodyReturnsQueryForGet(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['page' => '2'];

        $this->assertSame(['page' => '2'], Request::body());
    }

    public function testBodyReturnsPostForFormContentType(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_POST = ['name' => 'André'];

        $this->assertSame(['name' => 'André'], Request::body());
    }

    public function testInputFallsBackToBodyData(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_POST = ['email' => 'dev@example.com'];

        $this->assertSame('dev@example.com', Request::input('email'));
    }
}
