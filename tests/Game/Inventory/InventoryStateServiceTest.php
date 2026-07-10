<?php

namespace Tests\Game\Inventory;

use App\Game\Inventory\DTO\MoveItemRequest;
use App\Game\Inventory\Services\InventoryMoveService;
use App\Game\Inventory\Services\InventoryStateService;
use App\Game\Inventory\Services\StarterInventoryService;
use PDO;
use PHPUnit\Framework\TestCase;

class InventoryStateServiceTest extends TestCase
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

    public function testReturnsAuthoritativeInventoryStateForPlayer(): void
    {
        $state = (new InventoryStateService($this->pdo))->forPlayer(1);

        $this->assertCount(4, $state['containers']);
        $main = $this->containerByCode($state, 'main_inventory_level_1');

        $this->assertSame(['columns' => 12, 'rows' => 5], $main['grid']);
        $this->assertCount(4, $main['items']);
        $this->assertSame('stone_pickaxe', $main['items'][0]['definition']['code']);
        $this->assertArrayHasKey('placement', $main['items'][0]);
        $this->assertSame(0, $main['items'][0]['placement']['grid_x']);
        $this->assertSame(0, $main['items'][0]['placement']['grid_y']);
    }

    public function testMarketDeliveryAndExpeditionCarryAreReturnedEmpty(): void
    {
        $state = (new InventoryStateService($this->pdo))->forPlayer(1);

        $this->assertSame([], $this->containerByCode($state, 'market_delivery')['items']);
        $this->assertSame([], $this->containerByCode($state, 'expedition_carry')['items']);
        $this->assertSame(['columns' => 2, 'rows' => 2], $this->containerByCode($state, 'expedition_carry')['grid']);
    }

    public function testStateReflectsServerAuthoritativeMove(): void
    {
        $itemPublicId = $this->itemPublicId('wood');
        $sourcePublicId = $this->containerPublicId('main_inventory_level_1');
        $targetPublicId = $this->containerPublicId('market_delivery');

        (new InventoryMoveService($this->pdo))->move(new MoveItemRequest(
            1,
            $itemPublicId,
            $sourcePublicId,
            $targetPublicId,
            0,
            0,
            false,
            1
        ));

        $state = (new InventoryStateService($this->pdo))->forPlayer(1);

        $mainCodes = array_column(array_map(fn (array $item): array => $item['definition'], $this->containerByCode($state, 'main_inventory_level_1')['items']), 'code');
        $deliveryCodes = array_column(array_map(fn (array $item): array => $item['definition'], $this->containerByCode($state, 'market_delivery')['items']), 'code');

        $this->assertNotContains('wood', $mainCodes);
        $this->assertContains('wood', $deliveryCodes);
    }

    public function testDoesNotExposeInternalNumericIdsInState(): void
    {
        $state = (new InventoryStateService($this->pdo))->forPlayer(1);
        $encoded = json_encode($state, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('"id"', $encoded);
        $this->assertStringContainsString('public_id', $encoded);
    }

    public function testReturnsEquipmentLinksAndActiveSetBonuses(): void
    {
        $setMigration = require __DIR__ . '/../../../database/migrations/2026_07_09_000012_create_item_set_affinity_tables.php';
        $setMigration->up($this->pdo);

        $this->pdo->exec("INSERT INTO item_property_definitions (code, name, value_type, unit, status) VALUES ('attack_power', 'Poder de ataque', 'integer', NULL, 'active')");
        $weaponDefinitionId = $this->createDefinition('set_blade', 'Set Blade', 'weapon', 'metal', 'weapon');
        $chestDefinitionId = $this->createDefinition('set_chest', 'Set Chest', 'armor', 'metal', 'chest');
        $weaponItemId = $this->createItemInstance('set-blade-public', $weaponDefinitionId);
        $chestItemId = $this->createItemInstance('set-chest-public', $chestDefinitionId);

        $this->equipItem(1, 'weapon', $weaponItemId);
        $this->equipItem(1, 'chest', $chestItemId);

        $this->pdo->exec("INSERT INTO item_sets (id, code, name, aura_color, status) VALUES (1, 'test_set', 'Test Set', '#55c58a', 'active')");
        $this->pdo->prepare('INSERT INTO item_set_pieces (item_set_id, item_definition_id, piece_key, sort_order) VALUES (1, :definition_id, :piece_key, :sort_order)')
            ->execute(['definition_id' => $weaponDefinitionId, 'piece_key' => 'weapon', 'sort_order' => 10]);
        $this->pdo->prepare('INSERT INTO item_set_pieces (item_set_id, item_definition_id, piece_key, sort_order) VALUES (1, :definition_id, :piece_key, :sort_order)')
            ->execute(['definition_id' => $chestDefinitionId, 'piece_key' => 'chest', 'sort_order' => 20]);
        $this->pdo->exec("INSERT INTO item_set_bonuses (item_set_id, required_pieces, property_definition_id, integer_value, description) VALUES (1, 2, 1, 8, '2 pecas: +8 Poder de ataque')");

        $state = (new InventoryStateService($this->pdo))->forPlayer(1);

        $this->assertCount(1, $state['equipment_links']);
        $this->assertSame('test_set', $state['equipment_links'][0]['set_code']);
        $this->assertSame(['weapon', 'chest'], array_column($state['equipment_links'][0]['slots'], 'slot_code'));
        $this->assertCount(1, $state['active_set_bonuses']);
        $this->assertSame('test_set', $state['active_set_bonuses'][0]['set_code']);

        $attack = array_values(array_filter($state['character_stats'], fn (array $stat): bool => $stat['code'] === 'attack_power'))[0] ?? null;
        $this->assertNotNull($attack);
        $this->assertSame(8.0, $attack['value']);
    }

    private function createPlayerFixture(): void
    {
        $this->pdo->prepare("INSERT INTO accounts (public_id, display_name, email, password_hash, status) VALUES ('account-1', 'Tester', 'tester@example.com', :password_hash, 'active')")
            ->execute(['password_hash' => password_hash('secret', PASSWORD_ARGON2ID)]);
        $this->pdo->exec("INSERT INTO players (public_id, account_id, name, status) VALUES ('player-1', 1, 'Tester', 'active')");
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

    private function containerPublicId(string $code): string
    {
        $stmt = $this->pdo->prepare('SELECT ci.public_id FROM container_instances ci INNER JOIN container_definitions cd ON cd.id = ci.container_definition_id WHERE cd.code = :code LIMIT 1');
        $stmt->execute(['code' => $code]);

        return (string) $stmt->fetchColumn();
    }

    private function createDefinition(string $code, string $name, string $categoryCode, string $familyCode, string $slotCode): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO item_definitions (
            code, name, category_id, material_family_id, stackable, max_stack, grid_w, grid_h, equip_slot_code, is_container, tradeable, status
        ) VALUES (
            :code, :name, :category_id, :material_family_id, 0, 1, 1, 1, :slot_code, 0, 1, :status
        )');
        $stmt->execute([
            'code' => $code,
            'name' => $name,
            'category_id' => $this->idByCode('item_categories', $categoryCode),
            'material_family_id' => $this->idByCode('material_families', $familyCode),
            'slot_code' => $slotCode,
            'status' => 'active',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createItemInstance(string $publicId, int $definitionId): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO item_instances (public_id, item_definition_id, owner_player_id, quantity, item_name, state) VALUES (:public_id, :definition_id, 1, 1, :item_name, :state)');
        $stmt->execute([
            'public_id' => $publicId,
            'definition_id' => $definitionId,
            'item_name' => $publicId,
            'state' => 'available',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function equipItem(int $playerId, string $slotCode, int $itemId): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO player_equipment (player_id, equipment_slot_id, item_instance_id) VALUES (:player_id, :slot_id, :item_id)');
        $stmt->execute([
            'player_id' => $playerId,
            'slot_id' => $this->idByCode('equipment_slots', $slotCode),
            'item_id' => $itemId,
        ]);
    }

    private function idByCode(string $table, string $code): int
    {
        $stmt = $this->pdo->prepare("SELECT id FROM {$table} WHERE code = :code LIMIT 1");
        $stmt->execute(['code' => $code]);

        return (int) $stmt->fetchColumn();
    }
}
