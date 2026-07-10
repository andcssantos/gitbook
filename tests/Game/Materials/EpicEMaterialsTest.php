<?php

namespace Tests\Game\Materials;

use App\Game\Inventory\Services\StarterInventoryService;
use App\Game\Items\Services\ItemActionExecuteService;
use App\Game\Items\Services\ItemInvestigationService;
use App\Game\Materials\Services\DismantleService;
use App\Game\Materials\Services\PlayerMaterialStashService;
use PDO;
use PHPUnit\Framework\TestCase;

class EpicEMaterialsTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
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
        ] as $migrationFile) {
            $migration = require __DIR__ . '/../../../database/migrations/' . $migrationFile;
            $migration->up($this->pdo);
        }

        $seed = require __DIR__ . '/../../../database/seeds/001_evolvaxe_foundation_seed.php';
        $seed($this->pdo);

        $this->createPlayer(1, 'player-1', 'Tester');
        (new StarterInventoryService($this->pdo))->ensureForPlayer(1);
    }

    public function testInvestigationReturnsMarketAndDismantleSections(): void
    {
        $publicId = $this->itemPublicId('wood');
        $report = (new ItemInvestigationService($this->pdo))->investigate(1, $publicId);

        $this->assertSame($publicId, $report['item']['public_id']);
        $this->assertArrayHasKey('market', $report);
        $this->assertArrayHasKey('dismantle', $report);
        $this->assertArrayHasKey('history', $report);
        $this->assertGreaterThan(0, (int) ($report['market']['market_value'] ?? 0));
    }

    public function testDismantleMovesMaterialsToStash(): void
    {
        $publicId = $this->itemPublicId('wood');
        $result = (new DismantleService($this->pdo))->dismantle(1, $publicId);

        $this->assertSame('DISMANTLE', $result['action']);
        $this->assertNotEmpty($result['materials']);

        $stash = (new PlayerMaterialStashService($this->pdo))->listForPlayer(1);
        $this->assertNotEmpty($stash['stacks']);
        $this->assertArrayHasKey('grid', $stash);
        $this->assertSame(12, (int) $stash['grid']['columns']);
        $stack = $stash['stacks'][0];
        $this->assertArrayHasKey('stack_key', $stack);
        $this->assertArrayHasKey('icon_url', $stack);
        $this->assertArrayHasKey('craft_source', $stack);
        $this->assertGreaterThan(0, (int) $stack['quantity']);
    }

    public function testDismantleActionIsAvailableForWood(): void
    {
        $publicId = $this->itemPublicId('wood');
        $actions = (new \App\Game\Items\Services\ItemActionAvailabilityService($this->pdo))->listForItem($this->loadItem('wood'));
        $codes = array_column($actions, 'code');

        $this->assertContains('DISMANTLE', $codes);

        $result = (new ItemActionExecuteService($this->pdo))->execute(1, $publicId, 'DISMANTLE', true);
        $this->assertSame('DISMANTLE', $result['action']);
    }

    private function loadItem(string $code): array
    {
        $stmt = $this->pdo->prepare('SELECT ii.*, id.code AS definition_code, id.is_container, id.stackable, id.equip_slot_code, ic.code AS category_code
            FROM item_instances ii
            INNER JOIN item_definitions id ON id.id = ii.item_definition_id
            INNER JOIN item_categories ic ON ic.id = id.category_id
            WHERE id.code = :code AND ii.owner_player_id = 1 LIMIT 1');
        $stmt->execute(['code' => $code]);

        return (array) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function itemPublicId(string $code): string
    {
        $stmt = $this->pdo->prepare('SELECT ii.public_id FROM item_instances ii INNER JOIN item_definitions id ON id.id = ii.item_definition_id WHERE id.code = :code AND ii.owner_player_id = 1 LIMIT 1');
        $stmt->execute(['code' => $code]);

        return (string) $stmt->fetchColumn();
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
