<?php

namespace Tests\Utils\Functions;

use App\Utils\Functions\CacheManager;
use PHPUnit\Framework\TestCase;

class CacheManagerTest extends TestCase
{
    private CacheManager $cacheManager;

    protected function setUp(): void
    {
        $this->cacheManager = new CacheManager('tests', 60, 0.0);
        $this->cacheManager->clean();
    }

    public function testCanStoreAndRetrieveNullValueFromMemoryCache(): void
    {
        $this->assertTrue($this->cacheManager->set('null-key', null, 60));

        $this->assertNull($this->cacheManager->get('null-key'));
        $this->assertNull($this->cacheManager->get('null-key'));
    }

    public function testExpiredPayloadIsRemovedAndReturnsNull(): void
    {
        $this->assertTrue($this->cacheManager->set('expired-key', ['foo' => 'bar'], 1));
        sleep(2);

        $this->assertNull($this->cacheManager->get('expired-key'));
    }

    public function testCorruptedPayloadReturnsNullWithoutWarning(): void
    {
        $this->assertTrue($this->cacheManager->set('corrupted-key', ['foo' => 'bar'], 60));

        $reflection = new \ReflectionClass($this->cacheManager);
        $getFilePath = $reflection->getMethod('getFilePath');
        $getFilePath->setAccessible(true);

        $path = $getFilePath->invoke($this->cacheManager, 'corrupted-key');
        $corruptedPayload = gzencode('{"invalid":true}');
        file_put_contents($path, $corruptedPayload, LOCK_EX);

        $this->assertNull($this->cacheManager->get('corrupted-key'));
    }
}
