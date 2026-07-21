<?php

namespace Tests\Game\Equipment;

use App\Game\Equipment\Services\EquipmentService;
use App\Game\Inventory\DTO\MoveItemRequest;
use App\Game\Inventory\InventoryException;
use App\Game\Inventory\Services\InventoryMoveService;
use App\Game\Inventory\Services\InventoryStateService;
use App\Game\Inventory\Services\StarterInventoryService;
use PDO;
use PHPUnit\Framework\TestCase;

class EquipmentServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $migration = require __DIR__ . '/../../../database/migrations/2026_07_08_000003_create_evolvaxe_foundation_tables.php';
        $migration->up($this->pdo);
        $expeditionMigration = require __DIR__ . '/../../../database/migrations/2026_07_11_000029_expedition_state_and_decimal_currency.php';
        $expeditionMigration->up($this->pdo);

        $seed = require __DIR__ . '/../../../database/seeds/001_evolvaxe_foundation_seed.php';
        $seed($this->pdo);

        $slotMigration = require __DIR__ . '/../../../database/migrations/2026_07_09_000009_expand_equipment_slots.php';
        $slotMigration->up($this->pdo);

        $this->createPlayer(1, 'player-1', 'Tester');
        (new StarterInventoryService($this->pdo))->ensureForPlayer(1);
    }

    public function testEquippingBackpackResizesExpeditionCarryToBackpackCapacity(): void
    {
        $result = (new EquipmentService($this->pdo))->equip(1, $this->itemPublicId('small_leather_backpack'));

        $this->assertSame('backpack', $result['slot_code']);
        $this->assertSame(6, $result['expedition_carry']['grid_columns']);
        $this->assertSame(4, $result['expedition_carry']['grid_rows']);
        $this->assertSame(2, $result['expedition_carry']['pocket_columns']);
        $this->assertSame(4, $result['expedition_carry']['backpack_columns']);
        $this->assertSame(['columns' => 6, 'rows' => 4], $this->containerGrid('expedition_carry'));
    }

    public function testBackpackUnequipTransfersExpeditionCarryIntoLinkedContainer(): void
    {
        (new EquipmentService($this->pdo))->equip(1, $this->itemPublicId('small_leather_backpack'));
        $this->createActiveExpedition();

        (new InventoryMoveService($this->pdo))->move(new MoveItemRequest(
            1,
            $this->itemPublicId('wood'),
            $this->containerPublicId('main_inventory_level_1'),
            $this->containerPublicId('expedition_carry'),
            2,
            0,
            false,
            1
        ));

        $result = (new EquipmentService($this->pdo))->unequip(1, $this->itemPublicId('small_leather_backpack'));

        $this->assertSame('UNEQUIP', $result['action']);
        $this->assertSame(['columns' => 2, 'rows' => 2], $this->containerGrid('expedition_carry'));

        $backpackContainerId = (int) $this->pdo->query("SELECT ci.id FROM container_instances ci INNER JOIN item_instances ii ON ii.id = ci.source_item_instance_id WHERE ii.public_id = " . $this->pdo->quote($this->itemPublicId('small_leather_backpack')) . " LIMIT 1")->fetchColumn();
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM container_items WHERE container_instance_id = ' . $backpackContainerId)->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testBackpackUnequipKeepsPocketItemsInExpeditionCarry(): void
    {
        (new EquipmentService($this->pdo))->equip(1, $this->itemPublicId('small_leather_backpack'));
        $this->createActiveExpedition();

        (new InventoryMoveService($this->pdo))->move(new MoveItemRequest(
            1,
            $this->itemPublicId('wood'),
            $this->containerPublicId('main_inventory_level_1'),
            $this->containerPublicId('expedition_carry'),
            0,
            0,
            false,
            1
        ));

        (new EquipmentService($this->pdo))->unequip(1, $this->itemPublicId('small_leather_backpack'));

        $expeditionCount = (int) $this->pdo->query("SELECT COUNT(*) FROM container_items ci INNER JOIN container_instances c ON c.id = ci.container_instance_id INNER JOIN container_definitions cd ON cd.id = c.container_definition_id WHERE cd.code = 'expedition_carry' AND c.owner_player_id = 1")->fetchColumn();
        $this->assertSame(1, $expeditionCount);
    }

    public function testUnequippingEmptyBackpackRestoresExpeditionCarryDefaultCapacity(): void
    {
        (new EquipmentService($this->pdo))->equip(1, $this->itemPublicId('small_leather_backpack'));

        $result = (new EquipmentService($this->pdo))->unequip(1, $this->itemPublicId('small_leather_backpack'));

        $this->assertSame('UNEQUIP', $result['action']);
        $this->assertSame(2, $result['expedition_carry']['grid_columns']);
        $this->assertSame(2, $result['expedition_carry']['grid_rows']);
        $this->assertSame(['columns' => 2, 'rows' => 2], $this->containerGrid('expedition_carry'));
    }

    public function testNewPlayerStartsWithBaselineExpeditionCarryCapacity(): void
    {
        $this->assertSame(['columns' => 2, 'rows' => 2], $this->containerGrid('expedition_carry'));
    }

    public function testPreEquippedBackpackSyncsExpeditionCarryOnInventoryLoad(): void
    {
        $backpackPublicId = $this->itemPublicId('small_leather_backpack');
        $backpackItemId = $this->itemInstanceId($backpackPublicId);
        $backpackSlotId = $this->equipmentSlotId('backpack');

        $this->pdo->prepare('INSERT INTO player_equipment (player_id, equipment_slot_id, item_instance_id) VALUES (1, :slot_id, :item_id)')
            ->execute([
                'slot_id' => $backpackSlotId,
                'item_id' => $backpackItemId,
            ]);

        (new InventoryStateService($this->pdo))->forPlayer(1);

        $this->assertSame(['columns' => 6, 'rows' => 4], $this->containerGrid('expedition_carry'));
    }

    public function testTwoHandedWeaponRequiresFreeOffhandSlots(): void
    {
        $shield = $this->createItem('test_shield', 'Test Shield', 'armor', 'shield', ['offhand_type' => 'shield']);
        $twoHanded = $this->createItem('test_greatsword', 'Test Greatsword', 'weapon', 'weapon', ['hands' => 2]);

        (new EquipmentService($this->pdo))->equip(1, $shield);

        $this->assertInventoryException(
            fn (): array => (new EquipmentService($this->pdo))->equip(1, $twoHanded),
            'EQUIPMENT_TWO_HANDED_REQUIRES_FREE_OFFHAND'
        );
    }

    public function testTwoHandedWeaponBlocksOffhandEquipment(): void
    {
        $twoHanded = $this->createItem('test_staff_2h', 'Test Staff', 'weapon', 'weapon', ['hands' => 2]);
        $shield = $this->createItem('test_guard', 'Test Guard', 'armor', 'shield', ['offhand_type' => 'shield']);

        (new EquipmentService($this->pdo))->equip(1, $twoHanded);

        $this->assertInventoryException(
            fn (): array => (new EquipmentService($this->pdo))->equip(1, $shield),
            'EQUIPMENT_OFFHAND_BLOCKED_BY_TWO_HANDED'
        );
    }

    public function testDualWieldRequiresBothWeaponsToAllowIt(): void
    {
        $main = $this->createItem('test_dual_main', 'Test Dagger', 'weapon', 'weapon', ['allow_dual_wield' => true]);
        $offhand = $this->createItem('test_no_dual', 'Test Axe', 'weapon', 'weapon', ['allow_dual_wield' => false]);

        (new EquipmentService($this->pdo))->equip(1, $main);

        $this->assertInventoryException(
            fn (): array => (new EquipmentService($this->pdo))->equip(1, $offhand),
            'EQUIPMENT_DUAL_WIELD_NOT_ALLOWED'
        );
    }

    public function testDualWieldUsesOffhandWeaponSlotWhenAllowed(): void
    {
        $main = $this->createItem('test_dual_main_ok', 'Test Dagger A', 'weapon', 'weapon', ['allow_dual_wield' => true]);
        $offhand = $this->createItem('test_dual_offhand_ok', 'Test Dagger B', 'weapon', 'weapon', ['allow_dual_wield' => true]);

        $first = (new EquipmentService($this->pdo))->equip(1, $main);
        $second = (new EquipmentService($this->pdo))->equip(1, $offhand);

        $this->assertSame('weapon', $first['slot_code']);
        $this->assertSame('weapon_offhand', $second['slot_code']);
    }

    public function testSecondRingUsesSecondRingSlot(): void
    {
        $firstRing = $this->createItem('test_ring_a', 'Test Ring A', 'armor', 'ring');
        $secondRing = $this->createItem('test_ring_b', 'Test Ring B', 'armor', 'ring');

        $first = (new EquipmentService($this->pdo))->equip(1, $firstRing);
        $second = (new EquipmentService($this->pdo))->equip(1, $secondRing);

        $this->assertSame('ring', $first['slot_code']);
        $this->assertSame('ring_2', $second['slot_code']);
    }

    public function testPotionStackCanUsePotionSlot(): void
    {
        $potion = $this->createItem('test_potion_stack', 'Test Potion', 'consumable', 'potion', [], 1, 20, 10);

        $result = (new EquipmentService($this->pdo))->equip(1, $potion);

        $this->assertSame('potion_1', $result['slot_code']);
    }

    public function testConsumableStackCanUsePotionHotbarSlot(): void
    {
        $food = $this->createItem('test_food_stack', 'Test Food', 'consumable', 'consumable', [], 1, 20, 5);

        $result = (new EquipmentService($this->pdo))->equip(1, $food);

        $this->assertSame('potion_1', $result['slot_code']);
    }

    public function testConsumableCanTargetPreferredHotbarSlot(): void
    {
        $food = $this->createItem('test_food_slot3', 'Test Food 3', 'consumable', 'consumable', [], 1, 10, 2);

        $result = (new EquipmentService($this->pdo))->equip(1, $food, 'potion_3');

        $this->assertSame('potion_3', $result['slot_code']);
    }

    public function testUnequipSucceedsWhenStaleContainerPlacementExists(): void
    {
        $ring = $this->createItem('test_stale_ring', 'Stale Ring', 'armor', 'ring');

        (new EquipmentService($this->pdo))->equip(1, $ring);

        $itemId = $this->itemInstanceId($ring);
        $containerId = $this->containerInstanceId('main_inventory_level_1');

        $this->pdo->prepare('INSERT INTO container_items (
            container_instance_id,
            item_instance_id,
            grid_x,
            grid_y,
            grid_w,
            grid_h,
            rotated,
            locked
        ) VALUES (
            :container_instance_id,
            :item_instance_id,
            0,
            0,
            1,
            1,
            0,
            0
        )')->execute([
            'container_instance_id' => $containerId,
            'item_instance_id' => $itemId,
        ]);

        $result = (new EquipmentService($this->pdo))->unequip(1, $ring);

        $this->assertSame('UNEQUIP', $result['action']);
        $this->assertArrayHasKey('placement', $result);
        $this->assertSame(1, $this->placementCountForItem($itemId));
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
        $stmt = $this->pdo->prepare('SELECT ii.public_id FROM item_instances ii INNER JOIN item_definitions id ON id.id = ii.item_definition_id WHERE id.code = :code AND ii.owner_player_id = 1 LIMIT 1');
        $stmt->execute(['code' => $code]);

        return (string) $stmt->fetchColumn();
    }

    private function containerPublicId(string $code): string
    {
        $stmt = $this->pdo->prepare('SELECT ci.public_id FROM container_instances ci INNER JOIN container_definitions cd ON cd.id = ci.container_definition_id WHERE cd.code = :code AND ci.owner_player_id = 1 LIMIT 1');
        $stmt->execute(['code' => $code]);

        return (string) $stmt->fetchColumn();
    }

    private function containerInstanceId(string $code): int
    {
        $stmt = $this->pdo->prepare('SELECT ci.id FROM container_instances ci INNER JOIN container_definitions cd ON cd.id = ci.container_definition_id WHERE cd.code = :code AND ci.owner_player_id = 1 LIMIT 1');
        $stmt->execute(['code' => $code]);

        return (int) $stmt->fetchColumn();
    }

    private function itemInstanceId(string $publicId): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM item_instances WHERE public_id = :public_id LIMIT 1');
        $stmt->execute(['public_id' => $publicId]);

        return (int) $stmt->fetchColumn();
    }

    private function equipmentSlotId(string $code): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM equipment_slots WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => $code]);

        return (int) $stmt->fetchColumn();
    }

    private function placementCountForItem(int $itemInstanceId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM container_items WHERE item_instance_id = :item_instance_id');
        $stmt->execute(['item_instance_id' => $itemInstanceId]);

        return (int) $stmt->fetchColumn();
    }

    private function containerGrid(string $code): array
    {
        $stmt = $this->pdo->prepare('SELECT ci.grid_columns, ci.grid_rows FROM container_instances ci INNER JOIN container_definitions cd ON cd.id = ci.container_definition_id WHERE cd.code = :code AND ci.owner_player_id = 1 LIMIT 1');
        $stmt->execute(['code' => $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'columns' => (int) $row['grid_columns'],
            'rows' => (int) $row['grid_rows'],
        ];
    }

    private function createActiveExpedition(): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO expedition_instances (public_id, player_id, status, expedition_seed, ends_at)
            VALUES (:public_id, :player_id, :status, :expedition_seed, :ends_at)");
        $stmt->execute([
            'public_id' => 'equipment-expedition-active',
            'player_id' => 1,
            'status' => 'active',
            'expedition_seed' => 'equipment-test-seed',
            'ends_at' => date('Y-m-d H:i:s', time() + 600),
        ]);
    }

    private function createItem(string $code, string $name, string $categoryCode, string $slotCode, array $baseConfig = [], int $stackable = 0, int $maxStack = 1, int $quantity = 1): string
    {
        $categoryId = (int) $this->pdo->query('SELECT id FROM item_categories WHERE code = ' . $this->pdo->quote($categoryCode) . ' LIMIT 1')->fetchColumn();
        $familyId = (int) $this->pdo->query("SELECT id FROM material_families WHERE code = 'metal' LIMIT 1")->fetchColumn();

        $stmt = $this->pdo->prepare('INSERT INTO item_definitions (
            code,
            name,
            category_id,
            material_family_id,
            stackable,
            max_stack,
            grid_w,
            grid_h,
            equip_slot_code,
            is_container,
            tradeable,
            base_config,
            status
        ) VALUES (
            :code,
            :name,
            :category_id,
            :material_family_id,
            :stackable,
            :max_stack,
            1,
            1,
            :equip_slot_code,
            0,
            1,
            :base_config,
            :status
        )');
        $stmt->execute([
            'code' => $code,
            'name' => $name,
            'category_id' => $categoryId,
            'material_family_id' => $familyId,
            'stackable' => $stackable,
            'max_stack' => $maxStack,
            'equip_slot_code' => $slotCode,
            'base_config' => json_encode($baseConfig, JSON_THROW_ON_ERROR),
            'status' => 'active',
        ]);

        $definitionId = (int) $this->pdo->lastInsertId();
        $publicId = $code . '-public';
        $item = $this->pdo->prepare('INSERT INTO item_instances (public_id, item_definition_id, owner_player_id, quantity, item_name, state) VALUES (:public_id, :item_definition_id, :owner_player_id, :quantity, :item_name, :state)');
        $item->execute([
            'public_id' => $publicId,
            'item_definition_id' => $definitionId,
            'owner_player_id' => 1,
            'quantity' => $quantity,
            'item_name' => $name,
            'state' => 'available',
        ]);

        return $publicId;
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
