<?php

namespace Tests\Security;

use App\Http\HttpException;
use App\Security\Idempotency;
use PDO;
use PHPUnit\Framework\TestCase;

class IdempotencyTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/test';

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE idempotency_keys (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            scope TEXT NOT NULL,
            key_hash TEXT NOT NULL,
            request_hash TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'processing',
            response_payload TEXT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            completed_at TEXT NULL,
            expires_at TEXT NOT NULL,
            UNIQUE(scope, key_hash)
        )");
    }

    public function testReplaysCompletedResultWithoutRunningCallbackAgain(): void
    {
        $idempotency = new Idempotency(null, $this->pdo);
        $calls = 0;

        $first = $idempotency->handle('craft.confirm', function () use (&$calls): array {
            $calls++;

            return ['item_id' => 10];
        }, 'same-key');

        $second = $idempotency->handle('craft.confirm', function () use (&$calls): array {
            $calls++;

            return ['item_id' => 20];
        }, 'same-key');

        $this->assertSame(['item_id' => 10], $first);
        $this->assertSame(['item_id' => 10], $second);
        $this->assertSame(1, $calls);
    }

    public function testRejectsSameKeyWithDifferentRequestHash(): void
    {
        $idempotency = new Idempotency(null, $this->pdo);

        $idempotency->handle('market.buy', fn (): array => ['ok' => true], 'same-key');

        $_SERVER['REQUEST_URI'] = '/api/other';

        $this->expectException(HttpException::class);
        $idempotency->handle('market.buy', fn (): array => ['ok' => false], 'same-key');
    }
}
