<?php

namespace Tests\Game\Crafting;

use App\Game\Crafting\Services\CraftingWorkspaceService;
use App\Game\Equipment\Services\EquipmentService;
use App\Game\Inventory\DTO\GrantItemRequest;
use App\Game\Inventory\InventoryException;
use App\Game\Inventory\Services\InventoryAutoPlacementService;
use App\Game\Inventory\Services\StarterInventoryService;
use App\Game\Items\Services\ItemActionExecuteService;
use App\Game\Items\Services\ItemInvestigationService;
use App\Game\Materials\Services\PlayerMaterialStashService;
use App\Utils\Config;
use PDO;
use PHPUnit\Framework\TestCase;

class CraftingWorkspaceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        Config::load(__DIR__ . '/../../../');

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        foreach ([
            '2026_07_08_000003_create_evolvaxe_foundation_tables.php',
            '2026_07_08_000004_create_container_acceptance_rules_table.php',
            '2026_07_09_000006_create_item_action_tables.php',
            '2026_07_09_000007_create_item_progression_tables.php',
            '2026_07_10_000019_create_market_economy_tables.php',
            '2026_07_10_000020_enable_market_item_actions.php',
            '2026_07_10_000021_epic_e_materials_foundation.php',
            '2026_07_10_000022_crafting_recipes_foundation.php',
            '2026_07_10_000023_old_wood_sword_crafting_foundation.php',
            '2026_07_10_000024_old_wood_metal_sword_foundation.php',
            '2026_07_10_000025_item_safety_and_history.php',
            '2026_07_10_000028_crafting_events.php',
        ] as $migrationFile) {
            $migration = require __DIR__ . '/../../../database/migrations/' . $migrationFile;
            $migration->up($this->pdo);
        }

        $seed = require __DIR__ . '/../../../database/seeds/001_evolvaxe_foundation_seed.php';
        $seed($this->pdo);

        $this->createPlayer(1, 'player-1', 'Tester');
        (new StarterInventoryService($this->pdo))->ensureForPlayer(1);

        $craftSeed = require __DIR__ . '/../../../database/seeds/007_crafting_test_old_wood_sword_seed.php';
        $craftSeed($this->pdo);
    }

    public function testForgePreviewMatchesRecipeAndShowsCost(): void
    {
        $wood = $this->itemPublicId('wood');
        $stone = $this->itemPublicId('stone');

        $preview = (new CraftingWorkspaceService($this->pdo))->preview(1, 'forge', [
            ['index' => 0, 'source' => ['kind' => 'item_instance', 'public_id' => $wood]],
            ['index' => 1, 'source' => ['kind' => 'item_instance', 'public_id' => $stone]],
        ]);

        $this->assertSame('forge', $preview['workspace']);
        $this->assertSame(2, $preview['filled_slots']);
        $this->assertTrue($preview['can_craft']);
        $this->assertTrue($preview['recipe_match']['is_compatible']);
        $this->assertSame('Compativel', $preview['recipe_match']['compatibility_label']);
        $this->assertSame('forge_stone_pickaxe', $preview['recipe_match']['recipe_code']);
        $this->assertSame('stone_pickaxe', $preview['predicted_output']['definition_code']);
        $this->assertSame('common', $preview['predicted_output']['quality_bucket']);
        $this->assertGreaterThan(0, $preview['gold_cost']);
        $this->assertTrue($preview['can_afford']);
        $this->assertNotEmpty($preview['connections']);
    }

    public function testAlchemyPreviewMatchesRecipeWithMaterialFamily(): void
    {
        $wood = $this->itemPublicId('wood');

        $preview = (new CraftingWorkspaceService($this->pdo))->preview(1, 'alchemy', [
            ['index' => 0, 'source' => ['kind' => 'item_instance', 'public_id' => $wood]],
            ['index' => 1, 'source' => ['kind' => 'item_instance', 'public_id' => $wood]],
        ]);

        $this->assertSame(2, $preview['filled_slots']);
        $this->assertTrue($preview['recipe_match']['is_compatible']);
        $this->assertSame('alchemy_wood_infusion', $preview['recipe_match']['recipe_code']);
        $this->assertTrue($preview['can_craft']);
    }

    public function testForgeExecuteConsumesComponentsGrantsItemAndDebitsGold(): void
    {
        $wood = $this->itemPublicId('wood');
        $stone = $this->itemPublicId('stone');

        (new ItemActionExecuteService($this->pdo))->execute(1, $wood, 'DISMANTLE', true);

        $balanceBefore = $this->goldBalance();
        $service = new CraftingWorkspaceService($this->pdo);
        $result = $service->execute(1, 'forge', [
            ['index' => 0, 'source' => ['kind' => 'item_instance', 'public_id' => $stone]],
            ['index' => 2, 'source' => ['kind' => 'material_stack', 'family_code' => 'wood', 'origin_code' => 'starter_forest', 'quantity' => 1]],
        ]);

        $this->assertSame('CRAFT', $result['action']);
        $this->assertNotEmpty($result['granted_item']);
        $this->assertSame('stone_pickaxe', $result['analysis']['predicted_output']['definition_code']);
        $this->assertLessThan($balanceBefore, $this->goldBalance());

        $stash = (new PlayerMaterialStashService($this->pdo))->listForPlayer(1);
        $woodStacks = array_filter($stash['stacks'], fn (array $row): bool => $row['family_code'] === 'wood');
        $this->assertNotEmpty($woodStacks);
    }

    public function testEquippedItemIsRejected(): void
    {
        $pickaxe = $this->itemPublicId('stone_pickaxe');
        $stone = $this->itemPublicId('stone');

        (new EquipmentService($this->pdo))->equip(1, $pickaxe);

        $this->expectException(InventoryException::class);
        (new CraftingWorkspaceService($this->pdo))->preview(1, 'forge', [
            ['index' => 0, 'source' => ['kind' => 'item_instance', 'public_id' => $pickaxe]],
            ['index' => 1, 'source' => ['kind' => 'item_instance', 'public_id' => $stone]],
        ]);
    }

    public function testStackAllocationRejectsOveruse(): void
    {
        $pickaxe = $this->itemPublicId('stone_pickaxe');
        $stone = $this->itemPublicId('stone');

        $this->expectException(InventoryException::class);
        (new CraftingWorkspaceService($this->pdo))->preview(1, 'forge', [
            ['index' => 0, 'source' => ['kind' => 'item_instance', 'public_id' => $pickaxe]],
            ['index' => 1, 'source' => ['kind' => 'item_instance', 'public_id' => $pickaxe]],
            ['index' => 2, 'source' => ['kind' => 'item_instance', 'public_id' => $stone]],
        ]);
    }

    public function testForgeOldWoodSwordFromBranchAndLeatherStrip(): void
    {
        $stash = new PlayerMaterialStashService($this->pdo);
        $woodFamilyId = $this->idByCode('material_families', 'wood');
        $leatherFamilyId = $this->idByCode('material_families', 'leather');
        $stash->credit(1, $woodFamilyId, $this->idByCode('material_origins', 'wood_branch'), 2, 'fragments');
        $stash->credit(1, $leatherFamilyId, $this->idByCode('material_origins', 'leather_strip'), 2, 'fragments');

        $preview = (new CraftingWorkspaceService($this->pdo))->preview(1, 'forge', [
            ['index' => 0, 'source' => ['kind' => 'material_stack', 'family_code' => 'wood', 'origin_code' => 'wood_branch', 'quantity' => 1]],
            ['index' => 1, 'source' => ['kind' => 'material_stack', 'family_code' => 'leather', 'origin_code' => 'leather_strip', 'quantity' => 1]],
        ]);

        $this->assertTrue($preview['recipe_match']['is_compatible']);
        $this->assertSame('forge_old_wood_sword', $preview['recipe_match']['recipe_code']);
        $this->assertSame('old_wood_sword', $preview['predicted_output']['definition_code']);

        $result = (new CraftingWorkspaceService($this->pdo))->execute(1, 'forge', [
            ['index' => 0, 'source' => ['kind' => 'material_stack', 'family_code' => 'wood', 'origin_code' => 'wood_branch', 'quantity' => 1]],
            ['index' => 1, 'source' => ['kind' => 'material_stack', 'family_code' => 'leather', 'origin_code' => 'leather_strip', 'quantity' => 1]],
        ]);

        $this->assertSame('old_wood_sword', $result['analysis']['predicted_output']['definition_code']);

        $publicId = (string) ($result['granted_item']['item_public_id'] ?? '');
        $this->assertNotSame('', $publicId);

        $props = $this->pdo->prepare('SELECT ipd.code, iip.integer_value
            FROM item_instance_properties iip
            INNER JOIN item_property_definitions ipd ON ipd.id = iip.property_definition_id
            INNER JOIN item_instances ii ON ii.id = iip.item_instance_id
            WHERE ii.public_id = :public_id');
        $props->execute(['public_id' => $publicId]);
        $mapped = [];
        foreach ($props->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $mapped[(string) $row['code']] = (int) $row['integer_value'];
        }

        $this->assertSame(2, $mapped['strength'] ?? null);
        $this->assertSame(3, $mapped['agility'] ?? null);
    }

    public function testForgeOldWoodSwordAcceptsOldWoodInsteadOfBranch(): void
    {
        $stash = new PlayerMaterialStashService($this->pdo);
        $stash->credit(1, $this->idByCode('material_families', 'wood'), $this->idByCode('material_origins', 'old_wood'), 1, 'fragments');
        $stash->credit(1, $this->idByCode('material_families', 'leather'), $this->idByCode('material_origins', 'leather_strip'), 1, 'fragments');

        $preview = (new CraftingWorkspaceService($this->pdo))->preview(1, 'forge', [
            ['index' => 0, 'source' => ['kind' => 'material_stack', 'family_code' => 'wood', 'origin_code' => 'old_wood', 'quantity' => 1]],
            ['index' => 1, 'source' => ['kind' => 'material_stack', 'family_code' => 'leather', 'origin_code' => 'leather_strip', 'quantity' => 1]],
        ]);

        $this->assertTrue($preview['can_craft']);
        $this->assertSame('forge_old_wood_sword', $preview['recipe_match']['recipe_code']);
    }

    public function testForgeOldWoodMetalSwordUpgradesWoodenSwordWithTwoMetals(): void
    {
        $grant = (new InventoryAutoPlacementService($this->pdo))->grantAndPlace(GrantItemRequest::fromArray(1, [
            'item_definition_code' => 'old_wood_sword',
            'quantity' => 1,
            'quality_bucket' => 'common',
        ]));
        $woodSwordPublicId = (string) ($grant['item_public_id'] ?? '');
        $this->assertNotSame('', $woodSwordPublicId);

        $stash = new PlayerMaterialStashService($this->pdo);
        $metalFamilyId = $this->idByCode('material_families', 'metal');
        $stash->credit(1, $metalFamilyId, $this->idByCode('material_origins', 'old_iron'), 5, 'metals');

        $preview = (new CraftingWorkspaceService($this->pdo))->preview(1, 'forge', [
            ['index' => 0, 'source' => ['kind' => 'item_instance', 'public_id' => $woodSwordPublicId]],
            ['index' => 1, 'source' => ['kind' => 'material_stack', 'family_code' => 'metal', 'origin_code' => 'old_iron', 'quantity' => 1]],
            ['index' => 2, 'source' => ['kind' => 'material_stack', 'family_code' => 'metal', 'origin_code' => 'old_iron', 'quantity' => 1]],
        ]);

        $this->assertTrue($preview['can_craft']);
        $this->assertSame('forge_old_wood_metal_sword', $preview['recipe_match']['recipe_code']);
        $this->assertSame('old_wood-metal_sword', $preview['predicted_output']['definition_code']);

        $result = (new CraftingWorkspaceService($this->pdo))->execute(1, 'forge', [
            ['index' => 0, 'source' => ['kind' => 'item_instance', 'public_id' => $woodSwordPublicId]],
            ['index' => 1, 'source' => ['kind' => 'material_stack', 'family_code' => 'metal', 'origin_code' => 'old_iron', 'quantity' => 1]],
            ['index' => 2, 'source' => ['kind' => 'material_stack', 'family_code' => 'metal', 'origin_code' => 'old_iron', 'quantity' => 1]],
        ]);

        $this->assertSame('old_wood-metal_sword', $result['analysis']['predicted_output']['definition_code']);

        $publicId = (string) ($result['granted_item']['item_public_id'] ?? '');
        $props = $this->pdo->prepare('SELECT ipd.code, iip.integer_value
            FROM item_instance_properties iip
            INNER JOIN item_property_definitions ipd ON ipd.id = iip.property_definition_id
            INNER JOIN item_instances ii ON ii.id = iip.item_instance_id
            WHERE ii.public_id = :public_id');
        $props->execute(['public_id' => $publicId]);
        $mapped = [];
        foreach ($props->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $mapped[(string) $row['code']] = (int) $row['integer_value'];
        }

        $this->assertSame(5, $mapped['strength'] ?? null);
        $this->assertSame(5, $mapped['agility'] ?? null);
    }

    public function testForgeOldWoodSwordAcceptsInventoryWoodAndLeatherItem(): void
    {
        $wood = $this->itemPublicId('wood');
        $backpack = $this->itemPublicId('small_leather_backpack');

        $preview = (new CraftingWorkspaceService($this->pdo))->preview(1, 'forge', [
            ['index' => 0, 'source' => ['kind' => 'item_instance', 'public_id' => $wood]],
            ['index' => 1, 'source' => ['kind' => 'item_instance', 'public_id' => $backpack]],
        ]);

        $this->assertTrue($preview['can_craft'], (string) ($preview['reason'] ?? 'deveria craftar'));
        $this->assertSame('forge_old_wood_sword', $preview['recipe_match']['recipe_code']);
        $this->assertSame('Compativel', $preview['recipe_match']['compatibility_label']);
    }

    public function testCraftExecuteCreatesAuditableEventWithInputsAndOutput(): void
    {
        $stash = new PlayerMaterialStashService($this->pdo);
        $stash->credit(1, $this->idByCode('material_families', 'wood'), $this->idByCode('material_origins', 'wood_branch'), 1, 'fragments');
        $stash->credit(1, $this->idByCode('material_families', 'leather'), $this->idByCode('material_origins', 'leather_strip'), 1, 'fragments');

        $result = (new CraftingWorkspaceService($this->pdo))->execute(1, 'forge', [
            ['index' => 0, 'source' => ['kind' => 'material_stack', 'family_code' => 'wood', 'origin_code' => 'wood_branch', 'quantity' => 1]],
            ['index' => 4, 'source' => ['kind' => 'material_stack', 'family_code' => 'leather', 'origin_code' => 'leather_strip', 'quantity' => 1]],
        ]);

        $eventPublicId = (string) ($result['crafting_event']['public_id'] ?? '');
        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $eventPublicId);
        $this->assertSame('completed', $result['crafting_event']['status']);

        $event = $this->craftingEvent($eventPublicId);
        $this->assertSame('forge', $event['workspace']);
        $this->assertSame('forge_old_wood_sword', $event['recipe_code']);
        $this->assertSame('completed', $event['status']);
        $this->assertNotEmpty($event['craft_seed']);
        $this->assertGreaterThan(0, (int) $event['gold_cost']);

        $this->assertSame(2, $this->countRows('crafting_event_inputs', 'crafting_event_id', (int) $event['id']));
        $this->assertSame(1, $this->countRows('crafting_event_outputs', 'crafting_event_id', (int) $event['id']));

        $outputPublicId = (string) ($result['granted_item']['item_public_id'] ?? '');
        $stmt = $this->pdo->prepare('SELECT crafted_by_player_id, crafting_event_id FROM item_instances WHERE public_id = :public_id LIMIT 1');
        $stmt->execute(['public_id' => $outputPublicId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($item);
        $this->assertSame(1, (int) $item['crafted_by_player_id']);
        $this->assertSame((int) $event['id'], (int) $item['crafting_event_id']);

        $report = (new ItemInvestigationService($this->pdo))->investigate(1, $outputPublicId);
        $types = array_map(fn (array $row): string => (string) $row['type'], $report['history']);
        $this->assertContains('crafted_created', $types);
    }

    public function testCraftingConsumedItemHistoryIncludesCraftingEvent(): void
    {
        $grant = (new InventoryAutoPlacementService($this->pdo))->grantAndPlace(GrantItemRequest::fromArray(1, [
            'item_definition_code' => 'old_wood_sword',
            'quantity' => 1,
            'quality_bucket' => 'common',
        ]));
        $woodSwordPublicId = (string) ($grant['item_public_id'] ?? '');

        $stash = new PlayerMaterialStashService($this->pdo);
        $stash->credit(1, $this->idByCode('material_families', 'metal'), $this->idByCode('material_origins', 'old_iron'), 2, 'metals');

        $result = (new CraftingWorkspaceService($this->pdo))->execute(1, 'forge', [
            ['index' => 0, 'source' => ['kind' => 'item_instance', 'public_id' => $woodSwordPublicId]],
            ['index' => 1, 'source' => ['kind' => 'material_stack', 'family_code' => 'metal', 'origin_code' => 'old_iron', 'quantity' => 1]],
            ['index' => 2, 'source' => ['kind' => 'material_stack', 'family_code' => 'metal', 'origin_code' => 'old_iron', 'quantity' => 1]],
        ]);

        $stmt = $this->pdo->prepare("SELECT metadata_json FROM item_history_events WHERE item_public_id = :item_public_id AND event_type = 'crafted_consumed' ORDER BY id DESC LIMIT 1");
        $stmt->execute(['item_public_id' => $woodSwordPublicId]);
        $metadata = json_decode((string) $stmt->fetchColumn(), true);

        $this->assertIsArray($metadata);
        $this->assertSame($result['crafting_event']['public_id'], $metadata['crafting_event_public_id']);
        $this->assertSame('forge_old_wood_metal_sword', $metadata['recipe_code']);
    }

    public function testInvalidCraftExecutePersistsFailedEventWithoutOutput(): void
    {
        $wood = $this->itemPublicId('wood');
        $stone = $this->itemPublicId('stone');

        try {
            (new CraftingWorkspaceService($this->pdo))->execute(1, 'alchemy', [
                ['index' => 0, 'source' => ['kind' => 'item_instance', 'public_id' => $wood]],
                ['index' => 1, 'source' => ['kind' => 'item_instance', 'public_id' => $stone]],
            ]);
            $this->fail('Invalid craft should fail.');
        } catch (InventoryException $e) {
            $this->assertSame('CRAFT_INVALID_COMPOSITION', $e->errorCode());
        }

        $event = $this->latestCraftingEvent();
        $this->assertSame('failed', $event['status']);
        $this->assertSame('CRAFT_INVALID_COMPOSITION', $event['failure_code']);
        $this->assertSame(0, $this->countRows('crafting_event_outputs', 'crafting_event_id', (int) $event['id']));
    }

    private function idByCode(string $table, string $code): int
    {
        $stmt = $this->pdo->prepare("SELECT id FROM {$table} WHERE code = :code LIMIT 1");
        $stmt->execute(['code' => $code]);

        return (int) $stmt->fetchColumn();
    }

    private function itemPublicId(string $code): string
    {
        $stmt = $this->pdo->prepare('SELECT ii.public_id FROM item_instances ii INNER JOIN item_definitions id ON id.id = ii.item_definition_id WHERE id.code = :code AND ii.owner_player_id = 1 LIMIT 1');
        $stmt->execute(['code' => $code]);

        return (string) $stmt->fetchColumn();
    }

    private function goldBalance(): int
    {
        $stmt = $this->pdo->prepare('SELECT balance FROM player_currency_wallets WHERE player_id = 1 AND currency_code = :currency LIMIT 1');
        $stmt->execute(['currency' => 'gold']);

        return (int) $stmt->fetchColumn();
    }

    private function craftingEvent(string $publicId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM crafting_events WHERE public_id = :public_id LIMIT 1');
        $stmt->execute(['public_id' => $publicId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);

        return $row;
    }

    private function latestCraftingEvent(): array
    {
        $row = $this->pdo->query('SELECT * FROM crafting_events ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);

        return $row;
    }

    private function countRows(string $table, string $column, int $value): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = :value");
        $stmt->execute(['value' => $value]);

        return (int) $stmt->fetchColumn();
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
}
