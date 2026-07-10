<?php

namespace Tests\Game\Items;

use App\Game\Inventory\InventoryException;
use App\Game\Inventory\Services\StarterInventoryService;
use App\Game\Items\Services\ItemActionAvailabilityService;
use App\Game\Items\Services\ItemActionExecuteService;
use PDO;
use PHPUnit\Framework\TestCase;

class ItemActionTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $migration = require __DIR__ . '/../../../database/migrations/2026_07_08_000003_create_evolvaxe_foundation_tables.php';
        $migration->up($this->pdo);

        $acceptanceMigration = require __DIR__ . '/../../../database/migrations/2026_07_08_000004_create_container_acceptance_rules_table.php';
        $acceptanceMigration->up($this->pdo);

        $itemActionMigration = require __DIR__ . '/../../../database/migrations/2026_07_09_000006_create_item_action_tables.php';
        $itemActionMigration->up($this->pdo);

        $marketMigration = require __DIR__ . '/../../../database/migrations/2026_07_10_000019_create_market_economy_tables.php';
        $marketMigration->up($this->pdo);

        $marketActionsMigration = require __DIR__ . '/../../../database/migrations/2026_07_10_000020_enable_market_item_actions.php';
        $marketActionsMigration->up($this->pdo);

        $seed = require __DIR__ . '/../../../database/seeds/001_evolvaxe_foundation_seed.php';
        $seed($this->pdo);

        $this->createPlayer(1, 'player-1', 'Tester');
        (new StarterInventoryService($this->pdo))->ensureForPlayer(1);
    }

    public function testMaterialReturnsDiscardAndInspectActions(): void
    {
        $item = $this->loadItem('wood');
        $actions = (new ItemActionAvailabilityService($this->pdo))->listForItem($item);
        $codes = array_column($actions, 'code');

        $this->assertContains('DISCARD', $codes);
        $this->assertContains('INSPECT', $codes);
        $this->assertNotContains('OPEN', $codes);
    }

    public function testContainerReturnsOpenAction(): void
    {
        $item = $this->loadItem('small_leather_backpack');
        $actions = (new ItemActionAvailabilityService($this->pdo))->listForItem($item);
        $codes = array_column($actions, 'code');

        $this->assertContains('OPEN', $codes);
        $this->assertContains('INSPECT', $codes);
    }

    public function testForbiddenOwnershipRejectsExecution(): void
    {
        $this->createPlayer(2, 'player-2', 'Other');

        $this->assertInventoryException(
            fn (): array => (new ItemActionExecuteService($this->pdo))->execute(2, $this->itemPublicId('wood'), 'INSPECT'),
            'ITEM_ACTION_FORBIDDEN'
        );
    }

    public function testDiscardRequiresConfirmation(): void
    {
        $this->assertInventoryException(
            fn (): array => (new ItemActionExecuteService($this->pdo))->execute(1, $this->itemPublicId('wood'), 'DISCARD', false),
            'ITEM_ACTION_CONFIRMATION_REQUIRED'
        );
    }

    public function testDiscardRemovesItemSafely(): void
    {
        $publicId = $this->itemPublicId('wood');

        $result = (new ItemActionExecuteService($this->pdo))->execute(1, $publicId, 'DISCARD', true);

        $this->assertTrue($result['discarded']);
        $this->assertSame(0, $this->itemExists($publicId));
    }

    public function testOpenReturnsLinkedContainerForOwner(): void
    {
        $result = (new ItemActionExecuteService($this->pdo))->execute(1, $this->itemPublicId('small_leather_backpack'), 'OPEN');

        $this->assertSame('OPEN', $result['action']);
        $this->assertSame('small_backpack', $result['container_definition_code']);
        $this->assertNotEmpty($result['container_public_id']);
    }

    public function testSellActionIsEnabledForTradeableItems(): void
    {
        $actions = (new ItemActionAvailabilityService($this->pdo))->listForItem($this->loadItem('wood'));
        $codes = array_column($actions, 'code');

        $this->assertContains('SELL', $codes);
    }

    private function loadItem(string $code): array
    {
        $stmt = $this->pdo->prepare('SELECT
                ii.*,
                id.code AS definition_code,
                id.grid_w AS definition_grid_w,
                id.grid_h AS definition_grid_h,
                id.is_container,
                id.stackable,
                id.max_stack,
                id.equip_slot_code,
                id.base_config,
                ic.code AS category_code,
                mf.code AS material_family_code
            FROM item_instances ii
            INNER JOIN item_definitions id ON id.id = ii.item_definition_id
            INNER JOIN item_categories ic ON ic.id = id.category_id
            LEFT JOIN material_families mf ON mf.id = id.material_family_id
            WHERE id.code = :code AND ii.owner_player_id = 1
            LIMIT 1');
        $stmt->execute(['code' => $code]);

        return (array) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function itemPublicId(string $code): string
    {
        $stmt = $this->pdo->prepare('SELECT ii.public_id FROM item_instances ii INNER JOIN item_definitions id ON id.id = ii.item_definition_id WHERE id.code = :code AND ii.owner_player_id = 1 LIMIT 1');
        $stmt->execute(['code' => $code]);

        return (string) $stmt->fetchColumn();
    }

    private function itemExists(string $publicId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM item_instances WHERE public_id = :public_id');
        $stmt->execute(['public_id' => $publicId]);

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
