<?php

namespace Tests\Game\Inventory;

use App\Game\Inventory\DTO\MergeStackRequest;
use App\Game\Inventory\DTO\SplitStackRequest;
use App\Game\Inventory\InventoryException;
use App\Game\Inventory\Services\StackMergeService;
use App\Game\Inventory\Services\StackSplitService;
use App\Game\Inventory\Services\StarterInventoryService;
use PDO;
use PHPUnit\Framework\TestCase;

class InventoryStackMergeSplitTest extends TestCase
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

    public function testCompatibleMergeUpdatesQuantitiesAndWeightedQuality(): void
    {
        $this->createStack('wood', 'wood-source', 10, 40.0, 'common', 'starter_forest');
        $this->createStack('wood', 'wood-target', 20, 60.0, 'common', 'starter_forest');
        $this->composition('wood-source', 'wood', 'starter_forest', 100.0, 40.0);
        $this->composition('wood-target', 'wood', 'starter_forest', 100.0, 60.0);

        $result = (new StackMergeService($this->pdo))->merge(new MergeStackRequest(1, 'wood-source', 'wood-target', 5));

        $this->assertSame(5, $result['source_quantity']);
        $this->assertSame(25, $result['target_quantity']);
        $this->assertSame(56.0, $result['target_quality_value']);
        $this->assertSame(5, $this->quantity('wood-source'));
        $this->assertSame(25, $this->quantity('wood-target'));
        $this->assertSame(56.0, $this->quality('wood-target'));
        $this->assertSame(56.0, $this->compositionQuality('wood-target'));
    }

    public function testMergeRejectsStalePlacementVersion(): void
    {
        $this->createStack('wood', 'wood-source', 10, 40.0, 'common', 'starter_forest', true, 0, 0);
        $this->createStack('wood', 'wood-target', 20, 60.0, 'common', 'starter_forest', true, 2, 0);

        $this->assertInventoryException(
            fn (): array => (new StackMergeService($this->pdo))->merge(new MergeStackRequest(
                1,
                'wood-source',
                'wood-target',
                1,
                999,
                1
            )),
            'INVENTORY_STALE_PLACEMENT'
        );
    }

    public function testMergeCannotExceedMaxStack(): void
    {
        $this->createStack('wood', 'wood-source', 10, 40.0, 'common', 'starter_forest');
        $this->createStack('wood', 'wood-target', 99, 60.0, 'common', 'starter_forest');

        $this->assertInventoryException(
            fn (): array => (new StackMergeService($this->pdo))->merge(new MergeStackRequest(1, 'wood-source', 'wood-target', 2)),
            'STACK_MAX_EXCEEDED'
        );
    }

    public function testMergeRejectsIncompatibleQualityBucket(): void
    {
        $this->createStack('wood', 'wood-source', 10, 40.0, 'common', 'starter_forest');
        $this->createStack('wood', 'wood-target', 10, 60.0, 'uncommon', 'starter_forest');

        $this->assertInventoryException(
            fn (): array => (new StackMergeService($this->pdo))->merge(new MergeStackRequest(1, 'wood-source', 'wood-target', 1)),
            'STACK_NOT_COMPATIBLE'
        );
    }

    public function testMergeRejectsIncompatibleOrigin(): void
    {
        $this->createStack('stone', 'stone-source', 10, 40.0, 'common', 'rocky_field');
        $this->createStack('stone', 'stone-target', 10, 60.0, 'common', 'abandoned_mine');

        $this->assertInventoryException(
            fn (): array => (new StackMergeService($this->pdo))->merge(new MergeStackRequest(1, 'stone-source', 'stone-target', 1)),
            'STACK_NOT_COMPATIBLE'
        );
    }

    public function testNonStackableItemCannotMerge(): void
    {
        $this->createStack('stone_pickaxe', 'pickaxe-source', 1, 50.0, 'common', 'rocky_field');
        $this->createStack('stone_pickaxe', 'pickaxe-target', 1, 60.0, 'common', 'rocky_field');

        $this->assertInventoryException(
            fn (): array => (new StackMergeService($this->pdo))->merge(new MergeStackRequest(1, 'pickaxe-source', 'pickaxe-target', 1)),
            'STACK_ITEM_NOT_STACKABLE'
        );
    }

    public function testMergeDeletesEmptySourceAndPreventsDuplicateMerge(): void
    {
        $this->createStack('wood', 'wood-source', 10, 40.0, 'common', 'starter_forest');
        $this->createStack('wood', 'wood-target', 50, 60.0, 'common', 'starter_forest');

        (new StackMergeService($this->pdo))->merge(new MergeStackRequest(1, 'wood-source', 'wood-target', 10));

        $this->assertSame(0, $this->itemCount('wood-source'));
        $this->assertSame(60, $this->quantity('wood-target'));

        $this->assertInventoryException(
            fn (): array => (new StackMergeService($this->pdo))->merge(new MergeStackRequest(1, 'wood-source', 'wood-target', 10)),
            'INVENTORY_ITEM_NOT_FOUND'
        );
        $this->assertSame(60, $this->quantity('wood-target'));
    }

    public function testSplitCreatesNewStackAndPlacement(): void
    {
        $this->createStack('wood', 'wood-source', 10, 40.0, 'common', 'starter_forest', true, 5, 0);
        $this->composition('wood-source', 'wood', 'starter_forest', 100.0, 40.0);

        $result = (new StackSplitService($this->pdo))->split(new SplitStackRequest(
            1,
            'wood-source',
            $this->containerPublicId('main_inventory_level_1'),
            $this->containerPublicId('main_inventory_level_1'),
            3,
            6,
            0,
            1
        ));

        $this->assertSame(7, $result['source_quantity']);
        $this->assertSame(3, $result['split_quantity']);
        $this->assertSame(6, $result['grid_x']);
        $this->assertSame(0, $result['grid_y']);
        $this->assertSame(7, $this->quantity('wood-source'));
        $this->assertSame(3, $this->quantity($result['split_item_public_id']));
        $this->assertSame(40.0, $this->compositionQuality($result['split_item_public_id']));
    }

    public function testSplitRejectsInvalidQuantity(): void
    {
        $this->createStack('wood', 'wood-source', 10, 40.0, 'common', 'starter_forest', true, 5, 0);

        $this->assertInventoryException(
            fn (): array => (new StackSplitService($this->pdo))->split(new SplitStackRequest(
                1,
                'wood-source',
                $this->containerPublicId('main_inventory_level_1'),
                $this->containerPublicId('main_inventory_level_1'),
                10,
                6,
                0,
                1
            )),
            'STACK_QUANTITY_INVALID'
        );
    }

    public function testSplitPlacementUsesValidationRules(): void
    {
        $this->createStack('wood', 'wood-source', 10, 40.0, 'common', 'starter_forest', true, 5, 0);
        $this->createStack('stone', 'stone-blocker', 1, 35.0, 'common', 'rocky_field', true, 6, 0);

        $this->assertInventoryException(
            fn (): array => (new StackSplitService($this->pdo))->split(new SplitStackRequest(
                1,
                'wood-source',
                $this->containerPublicId('main_inventory_level_1'),
                $this->containerPublicId('main_inventory_level_1'),
                2,
                6,
                0,
                1
            )),
            'INVENTORY_OVERLAP'
        );
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

    private function composition(string $publicId, string $familyCode, string $originCode, float $percentage, float $quality): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO item_material_composition (item_instance_id, material_family_id, material_origin_id, percentage, average_quality) VALUES (:item_instance_id, :material_family_id, :material_origin_id, :percentage, :average_quality)');
        $stmt->execute([
            'item_instance_id' => $this->itemId($publicId),
            'material_family_id' => $this->id('material_families', $familyCode),
            'material_origin_id' => $this->id('material_origins', $originCode),
            'percentage' => $percentage,
            'average_quality' => $quality,
        ]);
    }

    private function quantity(string $publicId): int
    {
        $stmt = $this->pdo->prepare('SELECT quantity FROM item_instances WHERE public_id = :public_id');
        $stmt->execute(['public_id' => $publicId]);

        return (int) $stmt->fetchColumn();
    }

    private function quality(string $publicId): float
    {
        $stmt = $this->pdo->prepare('SELECT quality_value FROM item_instances WHERE public_id = :public_id');
        $stmt->execute(['public_id' => $publicId]);

        return (float) $stmt->fetchColumn();
    }

    private function compositionQuality(string $publicId): float
    {
        $stmt = $this->pdo->prepare('SELECT average_quality FROM item_material_composition WHERE item_instance_id = :item_instance_id LIMIT 1');
        $stmt->execute(['item_instance_id' => $this->itemId($publicId)]);

        return (float) $stmt->fetchColumn();
    }

    private function itemCount(string $publicId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM item_instances WHERE public_id = :public_id');
        $stmt->execute(['public_id' => $publicId]);

        return (int) $stmt->fetchColumn();
    }

    private function itemId(string $publicId): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM item_instances WHERE public_id = :public_id LIMIT 1');
        $stmt->execute(['public_id' => $publicId]);

        return (int) $stmt->fetchColumn();
    }

    private function containerId(string $code): int
    {
        $stmt = $this->pdo->prepare('SELECT ci.id FROM container_instances ci INNER JOIN container_definitions cd ON cd.id = ci.container_definition_id WHERE cd.code = :code AND ci.owner_player_id = 1 LIMIT 1');
        $stmt->execute(['code' => $code]);

        return (int) $stmt->fetchColumn();
    }

    private function containerPublicId(string $code): string
    {
        $stmt = $this->pdo->prepare('SELECT ci.public_id FROM container_instances ci INNER JOIN container_definitions cd ON cd.id = ci.container_definition_id WHERE cd.code = :code AND ci.owner_player_id = 1 LIMIT 1');
        $stmt->execute(['code' => $code]);

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
