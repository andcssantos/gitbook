<?php

namespace Tests\Game\Crafting;

use App\Game\Crafting\Services\CraftingWorkspaceService;
use App\Game\Equipment\Services\EquipmentService;
use App\Game\Inventory\InventoryException;
use App\Game\Inventory\Services\StarterInventoryService;
use App\Game\Items\Services\ItemActionExecuteService;
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
        ] as $migrationFile) {
            $migration = require __DIR__ . '/../../../database/migrations/' . $migrationFile;
            $migration->up($this->pdo);
        }

        $seed = require __DIR__ . '/../../../database/seeds/001_evolvaxe_foundation_seed.php';
        $seed($this->pdo);

        $this->createPlayer(1, 'player-1', 'Tester');
        (new StarterInventoryService($this->pdo))->ensureForPlayer(1);
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
