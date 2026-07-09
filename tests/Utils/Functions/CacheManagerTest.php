<?php

namespace Tests\Utils\Functions;

use App\Utils\Functions\CacheManager;
use PHPUnit\Framework\TestCase;

class CacheManagerTest extends TestCase
{
    private CacheManager $cacheManager;

    protected function setUp(): void
    {
        $_ENV['CACHE_KEY'] = str_repeat('c', 32);
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

    public function testRememberCachesResolverResult(): void
    {
        $calls = 0;

        $first = $this->cacheManager->remember('remember-key', function () use (&$calls): array {
            $calls++;
            return ['value' => 'fresh'];
        }, 60);

        $second = $this->cacheManager->remember('remember-key', function () use (&$calls): array {
            $calls++;
            return ['value' => 'new'];
        }, 60);

        $this->assertSame(['value' => 'fresh'], $first);
        $this->assertSame(['value' => 'fresh'], $second);
        $this->assertSame(1, $calls);
    }

    public function testFlushTagRemovesTaggedEntries(): void
    {
        $this->assertTrue($this->cacheManager->set('player-1', ['name' => 'A'], 60, ['tags' => ['players']]));
        $this->assertTrue($this->cacheManager->set('config', ['version' => 1], 60, ['tags' => ['config']]));

        $this->cacheManager->flushTag('players');

        $this->assertNull($this->cacheManager->get('player-1'));
        $this->assertSame(['version' => 1], $this->cacheManager->get('config'));
    }

    public function testCleanRemovesExpiredFilesAndPrunesTagIndex(): void
    {
        $this->assertTrue($this->cacheManager->set('clean-expired-key', ['old' => true], 1, ['tags' => ['cleanup']]));
        sleep(2);

        $reflection = new \ReflectionClass($this->cacheManager);
        $getFilePath = $reflection->getMethod('getFilePath');
        $getFilePath->setAccessible(true);
        $getTagPath = $reflection->getMethod('getTagPath');
        $getTagPath->setAccessible(true);

        $cachePath = $getFilePath->invoke($this->cacheManager, 'clean-expired-key');
        $tagPath = $getTagPath->invoke($this->cacheManager, 'cleanup');

        $this->assertFileExists($cachePath);
        $this->assertFileExists($tagPath);

        $this->cacheManager->clean();

        $this->assertFileDoesNotExist($cachePath);
        $this->assertFileDoesNotExist($tagPath);
    }

    public function testTamperedSignedPayloadIsRejected(): void
    {
        $this->assertTrue($this->cacheManager->set('signed-key', ['safe' => true], 60));

        $reflection = new \ReflectionClass($this->cacheManager);
        $getFilePath = $reflection->getMethod('getFilePath');
        $getFilePath->setAccessible(true);

        $path = $getFilePath->invoke($this->cacheManager, 'signed-key');
        $payload = json_decode(gzdecode((string) file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR);
        $payload['data'] = ['safe' => false];
        file_put_contents($path, gzencode(json_encode($payload, JSON_THROW_ON_ERROR)), LOCK_EX);

        $this->assertNull($this->cacheManager->get('signed-key'));
    }
}
