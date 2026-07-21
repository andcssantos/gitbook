<?php

namespace Tests\Game\Inventory;

use App\Game\Inventory\InventoryException;
use App\Game\Inventory\Services\InventoryStateService;
use App\Game\Inventory\Services\StarterInventoryService;
use PDO;
use PHPUnit\Framework\TestCase;

class InventoryQueryTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $migration = require __DIR__ . '/../../../database/migrations/2026_07_08_000003_create_evolvaxe_foundation_tables.php';
        $migration->up($this->pdo);

        $seed = require __DIR__ . '/../../../database/seeds/001_evolvaxe_foundation_seed.php';
        $seed($this->pdo);

        $this->createPlayerFixture();
        (new StarterInventoryService($this->pdo))->ensureForPlayer(1);
    }

    public function testSummaryReturnsOccupancyPerContainer(): void
    {
        $summary = (new InventoryStateService($this->pdo))->summaryForPlayer(1);

        $this->assertSame(4, $summary['container_count']);
        $this->assertSame(6, $summary['item_count']);

        $main = $this->summaryContainerByCode($summary, 'main_inventory_level_1');
        $this->assertSame(6, $main['item_count']);
        $this->assertGreaterThan(0, $main['occupied_cells']);
        $this->assertSame(60, $main['capacity_cells']);
    }

    public function testShowContainerReturnsSingleContainerSnapshot(): void
    {
        $full = (new InventoryStateService($this->pdo))->forPlayer(1);
        $publicId = $this->containerByCode($full, 'main_inventory_level_1')['public_id'];

        $result = (new InventoryStateService($this->pdo))->containerForPlayer(1, $publicId);

        $this->assertSame($publicId, $result['container']['public_id']);
        $this->assertCount(6, $result['container']['items']);
        $this->assertArrayNotHasKey('internal_id', $result['container']);
    }

    public function testShowItemReturnsPlacementAndLinkedContainer(): void
    {
        $itemPublicId = $this->itemPublicId('small_leather_backpack');
        $result = (new InventoryStateService($this->pdo))->itemForPlayer(1, $itemPublicId);

        $this->assertSame($itemPublicId, $result['item']['public_id']);
        $this->assertArrayHasKey('container', $result['item']);
        $this->assertArrayHasKey('linked_container', $result['item']);
        $this->assertSame('small_backpack', $result['item']['linked_container']['definition_code']);
    }

    public function testPhysicalContainerExposesSourceItemPublicId(): void
    {
        $state = (new InventoryStateService($this->pdo))->forPlayer(1);
        $backpackItemPublicId = $this->itemPublicId('small_leather_backpack');
        $linked = null;

        foreach ($state['containers'] as $container) {
            if (($container['source_item_public_id'] ?? null) === $backpackItemPublicId) {
                $linked = $container;
                break;
            }
        }

        $this->assertNotNull($linked);
        $this->assertSame('small_backpack', $linked['definition_code']);
    }

    public function testMissingContainerThrowsNotFound(): void
    {
        $this->expectException(InventoryException::class);
        $this->expectExceptionMessage('Inventory container was not found.');

        (new InventoryStateService($this->pdo))->containerForPlayer(1, 'missing-container');
    }

    private function summaryContainerByCode(array $summary, string $code): array
    {
        foreach ($summary['containers'] as $container) {
            if ($container['definition_code'] === $code) {
                return $container;
            }
        }

        $this->fail("Container {$code} not found in summary.");
    }

    private function containerByCode(array $state, string $code): array
    {
        foreach ($state['containers'] as $container) {
            if ($container['definition_code'] === $code) {
                return $container;
            }
        }

        $this->fail("Container {$code} not found.");
    }

    private function itemPublicId(string $code): string
    {
        $stmt = $this->pdo->prepare('SELECT ii.public_id FROM item_instances ii INNER JOIN item_definitions id ON id.id = ii.item_definition_id WHERE id.code = :code LIMIT 1');
        $stmt->execute(['code' => $code]);

        return (string) $stmt->fetchColumn();
    }

    private function createPlayerFixture(): void
    {
        $this->pdo->prepare("INSERT INTO accounts (public_id, display_name, email, password_hash, status) VALUES ('account-1', 'Tester', 'tester@example.com', :password_hash, 'active')")
            ->execute(['password_hash' => password_hash('secret', PASSWORD_ARGON2ID)]);
        $this->pdo->exec("INSERT INTO players (public_id, account_id, name, status) VALUES ('player-1', 1, 'Tester', 'active')");
    }
}
