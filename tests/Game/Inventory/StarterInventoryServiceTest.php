<?php

namespace Tests\Game\Inventory;

use App\Game\Inventory\Services\StarterInventoryService;
use PDO;
use PHPUnit\Framework\TestCase;

class StarterInventoryServiceTest extends TestCase
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
    }

    public function testCreatesMainInventoryStarterItemsAndBackpackContainer(): void
    {
        $result = (new StarterInventoryService($this->pdo))->ensureForPlayer(1);

        $this->assertTrue($result['created']);
        $this->assertSame(4, $result['placed_items']);
        $this->assertSame(4, (int) $this->pdo->query('SELECT COUNT(*) FROM container_instances')->fetchColumn());
        $this->assertSame(4, (int) $this->pdo->query('SELECT COUNT(*) FROM item_instances')->fetchColumn());
        $this->assertSame(4, (int) $this->pdo->query('SELECT COUNT(*) FROM container_items')->fetchColumn());

        $backpackContainers = (int) $this->pdo->query('SELECT COUNT(*) FROM container_instances WHERE source_item_instance_id IS NOT NULL')->fetchColumn();
        $this->assertSame(1, $backpackContainers);
    }

    public function testStarterInventoryIsIdempotent(): void
    {
        $service = new StarterInventoryService($this->pdo);

        $first = $service->ensureForPlayer(1);
        $second = $service->ensureForPlayer(1);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame(4, (int) $this->pdo->query('SELECT COUNT(*) FROM container_instances')->fetchColumn());
        $this->assertSame(4, (int) $this->pdo->query('SELECT COUNT(*) FROM item_instances')->fetchColumn());
        $this->assertSame(4, (int) $this->pdo->query('SELECT COUNT(*) FROM container_items')->fetchColumn());
    }

    public function testStarterPlacementsUseExpectedLogicalGridCoordinates(): void
    {
        (new StarterInventoryService($this->pdo))->ensureForPlayer(1);

        $stmt = $this->pdo->query('SELECT id.code, ci.grid_x, ci.grid_y, ci.grid_w, ci.grid_h FROM container_items ci INNER JOIN item_instances ii ON ii.id = ci.item_instance_id INNER JOIN item_definitions id ON id.id = ii.item_definition_id ORDER BY id.code ASC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertSame([
            ['code' => 'small_leather_backpack', 'grid_x' => 4, 'grid_y' => 0, 'grid_w' => 2, 'grid_h' => 2],
            ['code' => 'stone', 'grid_x' => 3, 'grid_y' => 0, 'grid_w' => 1, 'grid_h' => 1],
            ['code' => 'stone_pickaxe', 'grid_x' => 0, 'grid_y' => 0, 'grid_w' => 2, 'grid_h' => 3],
            ['code' => 'wood', 'grid_x' => 2, 'grid_y' => 0, 'grid_w' => 1, 'grid_h' => 2],
        ], array_map(fn (array $row): array => [
            'code' => $row['code'],
            'grid_x' => (int) $row['grid_x'],
            'grid_y' => (int) $row['grid_y'],
            'grid_w' => (int) $row['grid_w'],
            'grid_h' => (int) $row['grid_h'],
        ], $rows));
    }

    public function testItemInstanceCannotBePlacedInTwoContainers(): void
    {
        (new StarterInventoryService($this->pdo))->ensureForPlayer(1);

        $mainContainerId = (int) $this->pdo->query("SELECT ci.id FROM container_instances ci INNER JOIN container_definitions cd ON cd.id = ci.container_definition_id WHERE cd.code = 'main_inventory_level_1' LIMIT 1")->fetchColumn();
        $deliveryContainerId = (int) $this->pdo->query("SELECT ci.id FROM container_instances ci INNER JOIN container_definitions cd ON cd.id = ci.container_definition_id WHERE cd.code = 'market_delivery' LIMIT 1")->fetchColumn();
        $itemId = (int) $this->pdo->query("SELECT item_instance_id FROM container_items WHERE container_instance_id = {$mainContainerId} LIMIT 1")->fetchColumn();

        $this->expectException(\PDOException::class);
        $this->pdo->exec("INSERT INTO container_items (container_instance_id, item_instance_id, grid_x, grid_y, grid_w, grid_h) VALUES ({$deliveryContainerId}, {$itemId}, 0, 0, 1, 1)");
    }

    public function testMarketDeliveryAndExpeditionCarryExistButAreEmpty(): void
    {
        (new StarterInventoryService($this->pdo))->ensureForPlayer(1);

        $stmt = $this->pdo->query("SELECT cd.code, COUNT(ci_items.id) AS item_count
            FROM container_instances ci
            INNER JOIN container_definitions cd ON cd.id = ci.container_definition_id
            LEFT JOIN container_items ci_items ON ci_items.container_instance_id = ci.id
            WHERE cd.code IN ('market_delivery', 'expedition_carry')
            GROUP BY cd.code
            ORDER BY cd.code ASC");

        $this->assertSame([
            ['code' => 'expedition_carry', 'item_count' => 0],
            ['code' => 'market_delivery', 'item_count' => 0],
        ], array_map(fn (array $row): array => [
            'code' => $row['code'],
            'item_count' => (int) $row['item_count'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    private function createPlayerFixture(): void
    {
        $this->pdo->prepare("INSERT INTO accounts (public_id, display_name, email, password_hash, status) VALUES ('account-1', 'Tester', 'tester@example.com', :password_hash, 'active')")
            ->execute(['password_hash' => password_hash('secret', PASSWORD_ARGON2ID)]);
        $this->pdo->exec("INSERT INTO players (public_id, account_id, name, status) VALUES ('player-1', 1, 'Tester', 'active')");
    }
}
