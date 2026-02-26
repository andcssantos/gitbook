<?php

namespace Tests\Utils;

use App\Utils\Config;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    public function testLoadAndGetConfigValues(): void
    {
        Config::load(__DIR__ . '/../../');

        $this->assertNotSame('', Config::get('routing.default_system_content', ''));
        $this->assertSame('UTC', Config::get('app.timezone', 'UTC'));
    }
}
