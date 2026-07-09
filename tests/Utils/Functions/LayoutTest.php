<?php

namespace Tests\Utils\Functions;

use App\Utils\Config;
use App\Utils\Functions\Layout;
use PHPUnit\Framework\TestCase;

class LayoutTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER = [];
        $_ENV['DEFAULT_SYSTEM_CONTENT'] = 'app';
        Config::load(__DIR__ . '/../../../');
    }

    public function testSubdomainHostIgnoresPortOnLocalhost(): void
    {
        $_SERVER['HTTP_HOST'] = 'localhost:8000';
        $_SERVER['DEFAULT_DOMINIO'] = 'localhost';

        $this->assertSame('app/', Layout::getSubdomainHost());
    }

    public function testSubdomainHostTreatsLoopbackIpAsLocalhost(): void
    {
        $_SERVER['HTTP_HOST'] = '127.0.0.1:8000';
        $_SERVER['DEFAULT_DOMINIO'] = 'localhost';

        $this->assertSame('app/', Layout::getSubdomainHost());
    }
}
