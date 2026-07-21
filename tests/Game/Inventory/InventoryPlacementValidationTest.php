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
        $expeditionMigration = require __DIR__ . '/../../../database/migrations/2026_07_11_000029_expedition_state_and_decimal_currency.php';
        $expeditionMigration->up($this->pdo);

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
            fn (): array => $this->move('stone_pickaxe', 'main_inventory_level_1', 'main_inventory_level_1', 11, 0, 1),
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

    public function testBackpackCanBePlacedInsideWoodenChest(): void
    {
        $chestPublicId = $this->createWoodenChestItem('wooden-chest-public', 0, 4);
        $result = $this->moveByPublicId(
            $this->itemPublicId('small_leather_backpack'),
            'main_inventory_level_1',
            $chestPublicId,
            0,
            0,
            1
        );

        $this->assertSame($chestPublicId, $result['target_container_public_id']);
    }

    public function testContainerNestingLimitBlocksThirdLevelContainer(): void
    {
        $chestOnePublicId = $this->createWoodenChestItem('wooden-chest-public-2', 0, 4);
        $chestTwoPublicId = $this->createWoodenChestItem('wooden-chest-public-3', 4, 4);
        $this->moveByPublicId(
            'wooden-chest-public-3',
            'main_inventory_level_1',
            $chestOnePublicId,
            0,
            0,
            1
        );

        $chestTwoLinkedPublicId = $this->linkedContainerPublicIdForItem('wooden-chest-public-3');
        $this->createWoodenChestItem('wooden-chest-public-4', 8, 0);

        $this->assertInventoryException(
            fn (): array => $this->moveByPublicId(
                'wooden-chest-public-4',
                'main_inventory_level_1',
                $chestTwoLinkedPublicId,
                0,
                0,
                1
            ),
            'INVENTORY_CONTAINER_NESTING_LIMIT'
        );
    }

    public function testContainerItemCanMoveInsideMainInventory(): void
    {
        $result = $this->move('small_leather_backpack', 'main_inventory_level_1', 'main_inventory_level_1', 9, 0, 1);

        $this->assertSame(9, $result['grid_x']);
        $this->assertSame(0, $result['grid_y']);
        $this->assertSame(2, $result['placement_version']);
    }

    public function testRotatedMoveSwapsGridDimensions(): void
    {
        $result = $this->move('stone_pickaxe', 'main_inventory_level_1', 'main_inventory_level_1', 0, 2, 1, true);

        $this->assertTrue($result['rotated']);
        $this->assertSame(3, $result['grid_w']);
        $this->assertSame(2, $result['grid_h']);
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

    public function testMarketDeliveryAllowsRearrangingItemsInsideContainer(): void
    {
        $this->move('wood', 'main_inventory_level_1', 'market_delivery', 0, 0, 1);

        $result = $this->move('wood', 'market_delivery', 'market_delivery', 2, 0, 2);

        $this->assertSame(2, $result['grid_x']);
        $this->assertSame(0, $result['grid_y']);
    }

    public function testMarketDeliveryRejectsDepositsOutsideMainInventory(): void
    {
        $this->createPlacedItem('wood', 'wood-in-backpack', 'small_backpack', 0, 0, 1, 1);

        $this->assertInventoryException(
            fn (): array => $this->moveByPublicId('wood-in-backpack', 'small_backpack', 'market_delivery', 0, 0, 1),
            'INVENTORY_MARKET_DELIVERY_SOURCE_RESTRICTED'
        );
    }

    public function testExpeditionCarryRejectsDepositsWithoutActiveExpedition(): void
    {
        $this->assertInventoryException(
            fn (): array => $this->move('wood', 'main_inventory_level_1', 'expedition_carry', 0, 0, 1),
            'INVENTORY_EXPEDITION_CARRY_DEPOSIT_LOCKED'
        );
    }

    public function testExpeditionCarryAcceptsAnyFittingItemDuringActiveExpedition(): void
    {
        $this->createActiveExpedition();
        $this->createPlacedItem('iron_sword', 'sword-public-1', 'main_inventory_level_1', 9, 0, 1, 3);

        $result = $this->moveByPublicId('sword-public-1', 'main_inventory_level_1', 'expedition_carry', 0, 0, 1);

        $this->assertSame($this->containerPublicId('expedition_carry'), $result['target_container_public_id']);
    }

    public function testExpeditionCarryAllowsWithdrawalWithoutActiveExpedition(): void
    {
        $this->createPlacedItem('wood', 'wood-in-expedition', 'expedition_carry', 0, 0, 1, 1);

        $result = $this->moveByPublicId('wood-in-expedition', 'expedition_carry', 'main_inventory_level_1', 9, 0, 1);

        $this->assertSame($this->containerPublicId('main_inventory_level_1'), $result['target_container_public_id']);
    }

    public function testFrontendClassesAreNotExpeditionAuthority(): void
    {
        $this->createPlacedItem('iron_sword', 'sword-public-2', 'main_inventory_level_1', 9, 0, 1, 3);

        $this->assertInventoryException(
            fn (): array => $this->moveByPublicId('sword-public-2', 'main_inventory_level_1', 'expedition_carry', 0, 0, 1),
            'INVENTORY_EXPEDITION_CARRY_DEPOSIT_LOCKED'
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

    private function move(string $itemCode, string $sourceCode, string $targetCode, int $x, int $y, int $version, bool $rotated = false): array
    {
        return $this->moveByPublicId($this->itemPublicId($itemCode), $sourceCode, $targetCode, $x, $y, $version, $rotated);
    }

    private function moveByPublicId(string $itemPublicId, string $sourceReference, string $targetReference, int $x, int $y, int $version, bool $rotated = false): array
    {
        return (new InventoryMoveService($this->pdo))->move(new MoveItemRequest(
            1,
            $itemPublicId,
            $this->resolveContainerPublicId($sourceReference),
            $this->resolveContainerPublicId($targetReference),
            $x,
            $y,
            $rotated,
            $version
        ));
    }

    private function resolveContainerPublicId(string $reference): string
    {
        $stmt = $this->pdo->prepare('SELECT ci.public_id FROM container_instances ci INNER JOIN container_definitions cd ON cd.id = ci.container_definition_id WHERE cd.code = :code AND ci.owner_player_id = 1 LIMIT 1');
        $stmt->execute(['code' => $reference]);
        $byCode = $stmt->fetchColumn();
        if ($byCode !== false) {
            return (string) $byCode;
        }

        $stmt = $this->pdo->prepare('SELECT public_id FROM container_instances WHERE public_id = :public_id AND owner_player_id = 1 LIMIT 1');
        $stmt->execute(['public_id' => $reference]);
        $byPublicId = $stmt->fetchColumn();
        if ($byPublicId !== false) {
            return (string) $byPublicId;
        }

        $this->fail("Container {$reference} not found.");
    }

    private function createWoodenChestItem(string $publicId, int $x, int $y): string
    {
        $definitionCode = 'wooden_storage_chest_' . str_replace('-', '_', $publicId);
        $categoryId = (int) $this->pdo->query("SELECT id FROM item_categories WHERE code = 'container' LIMIT 1")->fetchColumn();
        $familyId = (int) $this->pdo->query("SELECT id FROM material_families WHERE code = 'wood' LIMIT 1")->fetchColumn();
        $stmt = $this->pdo->prepare('INSERT INTO item_definitions (
            code, name, description, category_id, material_family_id, stackable, max_stack, grid_w, grid_h, equip_slot_code, is_container, tradeable, base_config, status
        ) VALUES (
            :code, :name, :description, :category_id, :material_family_id, 0, 1, 2, 2, NULL, 1, 1, :base_config, :status
        )');
        $stmt->execute([
            'code' => $definitionCode,
            'name' => 'Wooden Storage Chest',
            'description' => 'Chest for nested container tests.',
            'category_id' => $categoryId,
            'material_family_id' => $familyId,
            'base_config' => json_encode(['container_definition' => 'wooden_chest'], JSON_THROW_ON_ERROR),
            'status' => 'active',
        ]);
        $definitionId = (int) $this->pdo->lastInsertId();
        $mainContainerId = (int) $this->pdo->query("SELECT ci.id FROM container_instances ci INNER JOIN container_definitions cd ON cd.id = ci.container_definition_id WHERE cd.code = 'main_inventory_level_1' AND ci.owner_player_id = 1 LIMIT 1")->fetchColumn();

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
            'container_instance_id' => $mainContainerId,
            'item_instance_id' => $itemId,
            'grid_x' => $x,
            'grid_y' => $y,
            'grid_w' => 2,
            'grid_h' => 2,
        ]);

        $chestDefinitionId = (int) $this->pdo->query("SELECT id FROM container_definitions WHERE code = 'wooden_chest' LIMIT 1")->fetchColumn();
        $stmt = $this->pdo->prepare('INSERT INTO container_instances (public_id, container_definition_id, owner_player_id, source_item_instance_id, name, grid_columns, grid_rows, status) VALUES (:public_id, :definition_id, :owner_player_id, :source_item_instance_id, :name, 10, 8, :status)');
        $stmt->execute([
            'public_id' => 'linked-' . $publicId,
            'definition_id' => $chestDefinitionId,
            'owner_player_id' => 1,
            'source_item_instance_id' => $itemId,
            'name' => 'Wooden Chest',
            'status' => 'active',
        ]);

        return 'linked-' . $publicId;
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

    private function createActiveExpedition(): void
    {
        $this->pdo->exec("UPDATE container_instances
            SET grid_columns = 4, grid_rows = 4
            WHERE id = (
                SELECT ci.id
                FROM container_instances ci
                INNER JOIN container_definitions cd ON cd.id = ci.container_definition_id
                WHERE cd.code = 'expedition_carry' AND ci.owner_player_id = 1
                LIMIT 1
            )");
        $stmt = $this->pdo->prepare("INSERT INTO expedition_instances (public_id, player_id, status, expedition_seed, ends_at)
            VALUES (:public_id, :player_id, :status, :expedition_seed, :ends_at)");
        $stmt->execute([
            'public_id' => 'expedition-active-1',
            'player_id' => 1,
            'status' => 'active',
            'expedition_seed' => 'test-seed',
            'ends_at' => date('Y-m-d H:i:s', time() + 600),
        ]);
    }

    private function linkedContainerPublicIdForItem(string $itemPublicId): string
    {
        $stmt = $this->pdo->prepare('SELECT cinst.public_id
            FROM container_instances cinst
            INNER JOIN item_instances ii ON ii.id = cinst.source_item_instance_id
            WHERE ii.public_id = :public_id
            LIMIT 1');
        $stmt->execute(['public_id' => $itemPublicId]);

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
