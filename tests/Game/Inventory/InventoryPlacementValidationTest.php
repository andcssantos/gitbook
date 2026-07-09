<?php

namespace Tests\Game\Inventory;

use App\Game\Inventory\DTO\MoveItemRequest;
use App\Game\Inventory\InventoryException;
use App\Game\Inventory\Services\InventoryMoveService;
use App\Game\Inventory\Services\StarterInventoryService;
use PDO;
use PHPUnit\Framework\TestCase;

class InventoryPlacementValidationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/inventory/move';

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $migration = require __DIR__ . '/../../../database/migrations/2026_07_08_000003_create_evolvaxe_foundation_tables.php';
        $migration->up($this->pdo);

        $seed = require __DIR__ . '/../../../database/seeds/001_evolvaxe_foundation_seed.php';
        $seed($this->pdo);

        $this->createPlayer(1, 'player-1', 'Tester');
        (new StarterInventoryService($this->pdo))->ensureForPlayer(1);
    }

    public function testValidMoveUpdatesPlacementAndIncrementsVersion(): void
    {
        $result = $this->move('stone_pickaxe', 'main_inventory_level_1', 'main_inventory_level_1', 6, 2, 1);

        $this->assertSame(6, $result['grid_x']);
        $this->assertSame(2, $result['grid_y']);
        $this->assertSame(2, $result['grid_w']);
        $this->assertSame(3, $result['grid_h']);
        $this->assertSame(2, $result['placement_version']);
    }

    public function testOutOfBoundsMoveIsRejected(): void
    {
        $this->assertInventoryException(
            fn (): array => $this->move('stone_pickaxe', 'main_inventory_level_1', 'main_inventory_level_1', 7, 3, 1),
            'INVENTORY_OUT_OF_BOUNDS'
        );
    }

    public function testOverlapMoveIsRejected(): void
    {
        $this->assertInventoryException(
            fn (): array => $this->move('stone', 'main_inventory_level_1', 'main_inventory_level_1', 0, 0, 1),
            'INVENTORY_OVERLAP'
        );
    }

    public function testSameContainerMoveIgnoresOwnOldPlacementForOverlap(): void
    {
        $result = $this->move('stone', 'main_inventory_level_1', 'main_inventory_level_1', 3, 0, 1);

        $this->assertSame(3, $result['grid_x']);
        $this->assertSame(0, $result['grid_y']);
        $this->assertSame(2, $result['placement_version']);
    }

    public function testContainerNestingIsBlocked(): void
    {
        $this->assertInventoryException(
            fn (): array => $this->move('small_leather_backpack', 'main_inventory_level_1', 'small_backpack', 0, 0, 1),
            'INVENTORY_CONTAINER_ITEM_BLOCKED'
        );
    }

    public function testStalePlacementVersionIsRejected(): void
    {
        $this->move('stone', 'main_inventory_level_1', 'main_inventory_level_1', 3, 1, 1);

        $this->assertInventoryException(
            fn (): array => $this->move('stone', 'main_inventory_level_1', 'main_inventory_level_1', 3, 2, 1),
            'INVENTORY_STALE_PLACEMENT'
        );
    }

    public function testOwnershipMismatchIsRejected(): void
    {
        $this->createPlayer(2, 'player-2', 'Other');

        $itemPublicId = $this->itemPublicId('wood');
        $sourcePublicId = $this->containerPublicId('main_inventory_level_1');
        $targetPublicId = $this->containerPublicId('main_inventory_level_1');

        $this->assertInventoryException(
            fn (): array => (new InventoryMoveService($this->pdo))->move(new MoveItemRequest(
                2,
                $itemPublicId,
                $sourcePublicId,
                $targetPublicId,
                0,
                0,
                false,
                1
            )),
            'INVENTORY_FORBIDDEN'
        );
    }

    public function testMoveBetweenTwoOwnedContainersPreservesUniquePlacement(): void
    {
        $result = $this->move('wood', 'main_inventory_level_1', 'market_delivery', 0, 0, 1);

        $this->assertSame($this->containerPublicId('market_delivery'), $result['target_container_public_id']);

        $itemId = (int) $this->pdo->query("SELECT id FROM item_instances WHERE public_id = " . $this->pdo->quote($result['item_public_id']))->fetchColumn();
        $placements = (int) $this->pdo->query("SELECT COUNT(*) FROM container_items WHERE item_instance_id = {$itemId}")->fetchColumn();
        $this->assertSame(1, $placements);

        $targetContainerId = (int) $this->pdo->query("SELECT id FROM container_instances WHERE public_id = " . $this->pdo->quote($result['target_container_public_id']))->fetchColumn();
        $actualContainerId = (int) $this->pdo->query("SELECT container_instance_id FROM container_items WHERE item_instance_id = {$itemId}")->fetchColumn();
        $this->assertSame($targetContainerId, $actualContainerId);
    }

    public function testExpeditionCarryAcceptsMvpLootMaterials(): void
    {
        $result = $this->move('wood', 'main_inventory_level_1', 'expedition_carry', 0, 0, 1);

        $this->assertSame($this->containerPublicId('expedition_carry'), $result['target_container_public_id']);
    }

    public function testExpeditionCarryRejectsWeaponByServerRule(): void
    {
        $this->createPlacedItem('iron_sword', 'sword-public-1', 'main_inventory_level_1', 6, 0, 1, 3);

        $this->assertInventoryException(
            fn (): array => $this->moveByPublicId('sword-public-1', 'main_inventory_level_1', 'expedition_carry', 0, 0, 1),
            'INVENTORY_CONTAINER_REJECTS_ITEM'
        );
    }

    public function testFrontendClassesAreNotContainerAuthority(): void
    {
        $this->createPlacedItem('iron_sword', 'sword-public-2', 'main_inventory_level_1', 6, 0, 1, 3);

        $this->assertInventoryException(
            fn (): array => $this->moveByPublicId('sword-public-2', 'main_inventory_level_1', 'expedition_carry', 0, 0, 1),
            'INVENTORY_CONTAINER_REJECTS_ITEM'
        );
    }

    public function testInventoryMoveSchemaDoesNotUsePixels(): void
    {
        $columns = array_column($this->pdo->query('PRAGMA table_info(container_items)')->fetchAll(PDO::FETCH_ASSOC), 'name');

        foreach ($columns as $column) {
            $this->assertStringNotContainsString('pixel', strtolower($column));
            $this->assertStringNotContainsString('px', strtolower($column));
        }
    }

    private function move(string $itemCode, string $sourceCode, string $targetCode, int $x, int $y, int $version): array
    {
        return $this->moveByPublicId($this->itemPublicId($itemCode), $sourceCode, $targetCode, $x, $y, $version);
    }

    private function moveByPublicId(string $itemPublicId, string $sourceCode, string $targetCode, int $x, int $y, int $version): array
    {
        return (new InventoryMoveService($this->pdo))->move(new MoveItemRequest(
            1,
            $itemPublicId,
            $this->containerPublicId($sourceCode),
            $this->containerPublicId($targetCode),
            $x,
            $y,
            false,
            $version
        ));
    }

    private function createPlacedItem(string $itemCode, string $publicId, string $containerCode, int $x, int $y, int $w, int $h): void
    {
        $definitionId = (int) $this->pdo->query("SELECT id FROM item_definitions WHERE code = " . $this->pdo->quote($itemCode))->fetchColumn();
        $containerId = (int) $this->pdo->query("SELECT ci.id FROM container_instances ci INNER JOIN container_definitions cd ON cd.id = ci.container_definition_id WHERE cd.code = " . $this->pdo->quote($containerCode) . " AND ci.owner_player_id = 1 LIMIT 1")->fetchColumn();

        $stmt = $this->pdo->prepare('INSERT INTO item_instances (public_id, item_definition_id, owner_player_id, quantity, state) VALUES (:public_id, :item_definition_id, :owner_player_id, :quantity, :state)');
        $stmt->execute([
            'public_id' => $publicId,
            'item_definition_id' => $definitionId,
            'owner_player_id' => 1,
            'quantity' => 1,
            'state' => 'available',
        ]);

        $itemId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO container_items (container_instance_id, item_instance_id, grid_x, grid_y, grid_w, grid_h) VALUES (:container_instance_id, :item_instance_id, :grid_x, :grid_y, :grid_w, :grid_h)');
        $stmt->execute([
            'container_instance_id' => $containerId,
            'item_instance_id' => $itemId,
            'grid_x' => $x,
            'grid_y' => $y,
            'grid_w' => $w,
            'grid_h' => $h,
        ]);
    }

    private function createPlayer(int $accountId, string $playerPublicId, string $name): void
    {
        $this->pdo->prepare('INSERT INTO accounts (id, public_id, display_name, email, password_hash, status) VALUES (:id, :public_id, :display_name, :email, :password_hash, :status)')
            ->execute([
                'id' => $accountId,
                'public_id' => "account-{$accountId}",
                'display_name' => $name,
                'email' => strtolower($name) . '@example.com',
                'password_hash' => password_hash('secret', PASSWORD_ARGON2ID),
                'status' => 'active',
            ]);
        $this->pdo->prepare('INSERT INTO players (id, public_id, account_id, name, status) VALUES (:id, :public_id, :account_id, :name, :status)')
            ->execute([
                'id' => $accountId,
                'public_id' => $playerPublicId,
                'account_id' => $accountId,
                'name' => $name,
                'status' => 'active',
            ]);
    }

    private function itemPublicId(string $code): string
    {
        $stmt = $this->pdo->prepare('SELECT ii.public_id FROM item_instances ii INNER JOIN item_definitions id ON id.id = ii.item_definition_id WHERE id.code = :code LIMIT 1');
        $stmt->execute(['code' => $code]);

        return (string) $stmt->fetchColumn();
    }

    private function containerPublicId(string $code): string
    {
        $stmt = $this->pdo->prepare('SELECT ci.public_id FROM container_instances ci INNER JOIN container_definitions cd ON cd.id = ci.container_definition_id WHERE cd.code = :code AND ci.owner_player_id = 1 LIMIT 1');
        $stmt->execute(['code' => $code]);

        return (string) $stmt->fetchColumn();
    }

    private function assertInventoryException(callable $callback, string $code): void
    {
        try {
            $callback();
            $this->fail("Expected inventory exception {$code}.");
        } catch (InventoryException $e) {
            $this->assertSame($code, $e->errorCode());
        }
    }
}
