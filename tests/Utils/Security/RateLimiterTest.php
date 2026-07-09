<?php

namespace Tests\Utils\Security;

use App\Utils\Functions\CacheManager;
use App\Utils\Security\RateLimiter;
use PHPUnit\Framework\TestCase;

class RateLimiterTest extends TestCase
{
    public function testBlocksAfterMaxAttempts(): void
    {
        $_ENV['CACHE_KEY'] = str_repeat('r', 32);
        $limiter = new RateLimiter(new CacheManager('tests/rate-limit', 60, 0.0));
        $key = 'ip|GET|/api|' . uniqid('', true);

        $this->assertTrue($limiter->attempt($key, 2, 60));
        $this->assertTrue($limiter->attempt($key, 2, 60));
        $this->assertFalse($limiter->attempt($key, 2, 60));
        $this->assertGreaterThan(0, $limiter->retryAfter($key));
    }
}
