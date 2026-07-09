<?php

namespace Tests\Game\Inventory;

use App\Game\Inventory\DTO\GrantItemRequest;
use App\Game\Inventory\InventoryException;
use App\Game\Inventory\Services\GridFreeSpaceFinder;
use App\Game\Inventory\Services\InventoryAutoPlacementService;
use App\Game\Inventory\Services\StarterInventoryService;
use PDO;
use PHPUnit\Framework\TestCase;

class InventoryAutoPlacementTest extends TestCase
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

        $this->createPlayer(1, 'player-1', 'Tester');
        (new StarterInventoryService($this->pdo))->ensureForPlayer(1, false);
    }

    public function testAutoPlacesItemInFirstFreeSlot(): void
    {
        $result = (new InventoryAutoPlacementService($this->pdo))->grantAndPlace(new GrantItemRequest(
            1,
            'stone',
            4,
            'common',
            35.0,
            'rocky_field'
        ));

        $this->assertSame('placed', $result['action']);
        $this->assertSame(0, $result['grid_x']);
        $this->assertSame(0, $result['grid_y']);
        $this->assertSame($this->containerPublicId('main_inventory_level_1'), $result['container_public_id']);
        $this->assertSame(4, $this->quantity($result['item_public_id']));
    }

    public function testMergeCompatibleStackBeforeSearchingFreeSpace(): void
    {
        $this->createStack('wood', 'wood-target', 10, 40.0, 'common', 'starter_forest', true, 0, 0);

        $result = (new InventoryAutoPlacementService($this->pdo))->grantAndPlace(new GrantItemRequest(
            1,
            'wood',
            5,
            'common',
            40.0,
            'starter_forest'
        ));

        $this->assertSame('merged', $result['action']);
        $this->assertSame('wood-target', $result['target_item_public_id']);
        $this->assertSame(15, $this->quantity('wood-target'));
        $this->assertSame(1, $this->placementCountForPlayer());
    }

    public function testIncompatibleStacksDoNotMergeAndCreateNewPlacement(): void
    {
        $this->createStack('wood', 'wood-target', 10, 40.0, 'uncommon', 'starter_forest', true, 0, 0);

        $result = (new InventoryAutoPlacementService($this->pdo))->grantAndPlace(new GrantItemRequest(
            1,
            'wood',
            5,
            'common',
            40.0,
            'starter_forest'
        ));

        $this->assertSame('placed', $result['action']);
        $this->assertSame(10, $this->quantity('wood-target'));
        $this->assertSame(5, $this->quantity($result['item_public_id']));
        $this->assertSame(2, $this->placementCountForPlayer());
    }

    public function testSpecializedContainerHasPriorityOverMainInventory(): void
    {
        $this->configureSpecializedChest();
        $this->createContainerInstance('wooden_chest', 'material-chest', 15);

        $result = (new InventoryAutoPlacementService($this->pdo))->grantAndPlace(new GrantItemRequest(
            1,
            'wood',
            3,
            'common',
            40.0,
            'starter_forest'
        ));

        $this->assertSame('placed', $result['action']);
        $this->assertSame($this->containerPublicId('material-chest'), $result['container_public_id']);
        $this->assertSame(0, $result['grid_x']);
        $this->assertSame(0, $result['grid_y']);
    }

    public function testInventoryFullRejectsGrantAtomically(): void
    {
        $this->fillMainInventoryGrid();

        $this->assertInventoryException(
            fn (): array => (new InventoryAutoPlacementService($this->pdo))->grantAndPlace(new GrantItemRequest(
                1,
                'stone',
                1,
                'common',
                35.0,
                'rocky_field'
            )),
            'INVENTORY_FULL'
        );

        $this->assertSame(40, $this->countItemsByDefinition('stone'));
    }

    public function testLargeItemUsesDeterministicFreeSpaceScan(): void
    {
        $this->createStack('wood', 'wood-blocker', 1, 40.0, 'common', 'starter_forest', true, 0, 0);

        $result = (new InventoryAutoPlacementService($this->pdo))->grantAndPlace(new GrantItemRequest(
            1,
            'stone_pickaxe',
            1,
            'common',
            50.0,
            'rocky_field'
        ));

        $this->assertSame('placed', $result['action']);
        $this->assertSame(2, $result['grid_w']);
        $this->assertSame(3, $result['grid_h']);
        $this->assertSame(1, $result['grid_x']);
        $this->assertSame(0, $result['grid_y']);
    }

    public function testBlockedContainerIsSkippedForAutoPlacement(): void
    {
        $this->createContainerInstance('expedition_carry', 'expedition-carry', 5);
        $this->fillMainInventoryGrid();

        $this->assertInventoryException(
            fn (): array => (new InventoryAutoPlacementService($this->pdo))->grantAndPlace(new GrantItemRequest(
                1,
                'wood',
                1,
                'common',
                40.0,
                'starter_forest'
            )),
            'INVENTORY_FULL'
        );
    }

    public function testGridFreeSpaceFinderScansRowMajor(): void
    {
        $container = [
            'grid_columns' => 4,
            'grid_rows' => 3,
        ];
        $item = [
            'definition_grid_w' => 1,
            'definition_grid_h' => 1,
            'category_code' => 'material',
            'definition_code' => 'wood',
            'is_container' => 0,
        ];
        $placements = [
            ['grid_x' => 0, 'grid_y' => 0, 'grid_w' => 1, 'grid_h' => 1],
            ['grid_x' => 1, 'grid_y' => 0, 'grid_w' => 1, 'grid_h' => 1],
        ];

        $slot = (new GridFreeSpaceFinder(
            new \App\Game\Inventory\Services\InventoryPlacementValidator(
                new \App\Game\Containers\Services\ContainerAcceptanceService(null, $this->pdo)
            )
        ))->findFirst($item, $container, $placements);

        $this->assertSame(['grid_x' => 2, 'grid_y' => 0, 'grid_w' => 1, 'grid_h' => 1], $slot);
    }

    public function testPartialMergePlacesRemainderWithoutDuplication(): void
    {
        $this->createStack('wood', 'wood-target', 98, 40.0, 'common', 'starter_forest', true, 0, 0);

        $result = (new InventoryAutoPlacementService($this->pdo))->grantAndPlace(new GrantItemRequest(
            1,
            'wood',
            5,
            'common',
            40.0,
            'starter_forest'
        ));

        $this->assertSame('merged_and_placed', $result['action']);
        $this->assertSame(100, $this->quantity('wood-target'));
        $this->assertSame(3, $this->quantity($result['item_public_id']));
        $this->assertSame(2, $this->placementCountForPlayer());
    }

    private function configureSpecializedChest(): void
    {
        $definitionId = $this->id('container_definitions', 'wooden_chest');
        $this->pdo->prepare('DELETE FROM container_acceptance_rules WHERE container_definition_id = :container_definition_id')
            ->execute(['container_definition_id' => $definitionId]);
        $this->pdo->prepare('INSERT INTO container_acceptance_rules (container_definition_id, rule_type, reference_code, allow, priority) VALUES (:container_definition_id, :rule_type, :reference_code, :allow, :priority)')
            ->execute([
                'container_definition_id' => $definitionId,
                'rule_type' => 'CONTAINER_BLOCK',
                'reference_code' => '',
                'allow' => 0,
                'priority' => 10,
            ]);
        $this->pdo->prepare('INSERT INTO container_acceptance_rules (container_definition_id, rule_type, reference_code, allow, priority) VALUES (:container_definition_id, :rule_type, :reference_code, :allow, :priority)')
            ->execute([
                'container_definition_id' => $definitionId,
                'rule_type' => 'ITEM_CATEGORY',
                'reference_code' => 'material',
                'allow' => 1,
                'priority' => 100,
            ]);
    }

    private function createContainerInstance(string $definitionCode, string $publicId, int $sortOrder): void
    {
        $definition = $this->fetchByCode('container_definitions', $definitionCode);
        $stmt = $this->pdo->prepare('INSERT INTO container_instances (public_id, container_definition_id, owner_player_id, name, grid_columns, grid_rows, status, sort_order) VALUES (:public_id, :container_definition_id, :owner_player_id, :name, :grid_columns, :grid_rows, :status, :sort_order)');
        $stmt->execute([
            'public_id' => $publicId,
            'container_definition_id' => (int) $definition['id'],
            'owner_player_id' => 1,
            'name' => (string) $definition['name'],
            'grid_columns' => (int) $definition['grid_columns'],
            'grid_rows' => (int) $definition['grid_rows'],
            'status' => 'active',
            'sort_order' => $sortOrder,
        ]);
    }

    private function fillMainInventoryGrid(): void
    {
        $containerId = $this->containerId('main_inventory_level_1');
        $definition = $this->fetchByCode('item_definitions', 'stone');

        for ($y = 0; $y < 5; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $stmt = $this->pdo->prepare('INSERT INTO item_instances (public_id, item_definition_id, owner_player_id, quantity, quality_value, quality_bucket, bind_type, state) VALUES (:public_id, :item_definition_id, :owner_player_id, :quantity, :quality_value, :quality_bucket, :bind_type, :state)');
                $stmt->execute([
                    'public_id' => "filler-{$x}-{$y}",
                    'item_definition_id' => (int) $definition['id'],
                    'owner_player_id' => 1,
                    'quantity' => 1,
                    'quality_value' => 35.0,
                    'quality_bucket' => 'common',
                    'bind_type' => 'none',
                    'state' => 'available',
                ]);

                $itemId = (int) $this->pdo->lastInsertId();
                $placement = $this->pdo->prepare('INSERT INTO container_items (container_instance_id, item_instance_id, grid_x, grid_y, grid_w, grid_h) VALUES (:container_instance_id, :item_instance_id, :grid_x, :grid_y, :grid_w, :grid_h)');
                $placement->execute([
                    'container_instance_id' => $containerId,
                    'item_instance_id' => $itemId,
                    'grid_x' => $x,
                    'grid_y' => $y,
                    'grid_w' => 1,
                    'grid_h' => 1,
                ]);
            }
        }
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

    private function createStack(
        string $itemCode,
        string $publicId,
        int $quantity,
        float $quality,
        string $bucket,
        string $originCode,
        bool $place = false,
        int $x = 0,
        int $y = 0
    ): void {
        $stmt = $this->pdo->prepare('INSERT INTO item_instances (
            public_id,
            item_definition_id,
            owner_player_id,
            quantity,
            quality_value,
            quality_bucket,
            material_origin_id,
            bind_type,
            state
        ) VALUES (
            :public_id,
            :item_definition_id,
            :owner_player_id,
            :quantity,
            :quality_value,
            :quality_bucket,
            :material_origin_id,
            :bind_type,
            :state
        )');
        $stmt->execute([
            'public_id' => $publicId,
            'item_definition_id' => $this->id('item_definitions', $itemCode),
            'owner_player_id' => 1,
            'quantity' => $quantity,
            'quality_value' => $quality,
            'quality_bucket' => $bucket,
            'material_origin_id' => $this->id('material_origins', $originCode),
            'bind_type' => 'none',
            'state' => 'available',
        ]);

        if ($place) {
            $definition = $this->fetchByCode('item_definitions', $itemCode);
            $stmt = $this->pdo->prepare('INSERT INTO container_items (container_instance_id, item_instance_id, grid_x, grid_y, grid_w, grid_h) VALUES (:container_instance_id, :item_instance_id, :grid_x, :grid_y, :grid_w, :grid_h)');
            $stmt->execute([
                'container_instance_id' => $this->containerId('main_inventory_level_1'),
                'item_instance_id' => (int) $this->pdo->lastInsertId(),
                'grid_x' => $x,
                'grid_y' => $y,
                'grid_w' => (int) $definition['grid_w'],
                'grid_h' => (int) $definition['grid_h'],
            ]);
        }
    }

    private function quantity(string $publicId): int
    {
        $stmt = $this->pdo->prepare('SELECT quantity FROM item_instances WHERE public_id = :public_id');
        $stmt->execute(['public_id' => $publicId]);

        return (int) $stmt->fetchColumn();
    }

    private function placementCountForPlayer(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM container_items ci INNER JOIN container_instances cinst ON cinst.id = ci.container_instance_id WHERE cinst.owner_player_id = 1')->fetchColumn();
    }

    private function countItemsByDefinition(string $code): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM item_instances ii INNER JOIN item_definitions id ON id.id = ii.item_definition_id WHERE ii.owner_player_id = 1 AND id.code = :code');
        $stmt->execute(['code' => $code]);

        return (int) $stmt->fetchColumn();
    }

    private function containerId(string $code): int
    {
        $stmt = $this->pdo->prepare('SELECT ci.id FROM container_instances ci INNER JOIN container_definitions cd ON cd.id = ci.container_definition_id WHERE cd.code = :code AND ci.owner_player_id = 1 LIMIT 1');
        $stmt->execute(['code' => $code]);

        return (int) $stmt->fetchColumn();
    }

    private function containerPublicId(string $codeOrPublicId): string
    {
        $stmt = $this->pdo->prepare('SELECT ci.public_id FROM container_instances ci INNER JOIN container_definitions cd ON cd.id = ci.container_definition_id WHERE (ci.public_id = :code OR cd.code = :code) AND ci.owner_player_id = 1 LIMIT 1');
        $stmt->execute(['code' => $codeOrPublicId]);

        return (string) $stmt->fetchColumn();
    }

    private function id(string $table, string $code): int
    {
        $stmt = $this->pdo->prepare("SELECT id FROM {$table} WHERE code = :code LIMIT 1");
        $stmt->execute(['code' => $code]);

        return (int) $stmt->fetchColumn();
    }

    private function fetchByCode(string $table, string $code): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$table} WHERE code = :code LIMIT 1");
        $stmt->execute(['code' => $code]);

        return (array) $stmt->fetch(PDO::FETCH_ASSOC);
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
