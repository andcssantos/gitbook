<?php

namespace Tests\Game\Equipment;

use App\Game\Equipment\Services\EquipmentService;
use App\Game\Inventory\DTO\MoveItemRequest;
use App\Game\Inventory\InventoryException;
use App\Game\Inventory\Services\InventoryMoveService;
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
        $this->assertSame(4, $result['expedition_carry']['grid_columns']);
        $this->assertSame(4, $result['expedition_carry']['grid_rows']);
        $this->assertSame(['columns' => 4, 'rows' => 4], $this->containerGrid('expedition_carry'));
    }

    public function testBackpackCannotBeUnequippedWhileExpeditionCarryHasItems(): void
    {
        (new EquipmentService($this->pdo))->equip(1, $this->itemPublicId('small_leather_backpack'));

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

        $this->assertInventoryException(
            fn (): array => (new EquipmentService($this->pdo))->unequip(1, $this->itemPublicId('small_leather_backpack')),
            'EQUIPMENT_BACKPACK_EXPEDITION_CARRY_NOT_EMPTY'
        );
    }

    public function testUnequippingEmptyBackpackRestoresExpeditionCarryDefaultCapacity(): void
    {
        (new EquipmentService($this->pdo))->equip(1, $this->itemPublicId('small_leather_backpack'));

        $result = (new EquipmentService($this->pdo))->unequip(1, $this->itemPublicId('small_leather_backpack'));

        $this->assertSame('UNEQUIP', $result['action']);
        $this->assertSame(8, $result['expedition_carry']['grid_columns']);
        $this->assertSame(5, $result['expedition_carry']['grid_rows']);
        $this->assertSame(['columns' => 8, 'rows' => 5], $this->containerGrid('expedition_carry'));
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
