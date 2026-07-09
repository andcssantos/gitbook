<?php

namespace Tests\Game\Containers;

use App\Game\Containers\Services\PhysicalContainerLinkService;
use App\Game\Inventory\DTO\GrantItemRequest;
use App\Game\Inventory\Services\InventoryAutoPlacementService;
use App\Game\Inventory\Services\StarterInventoryService;
use PDO;
use PHPUnit\Framework\TestCase;

class PhysicalContainerLinkTest extends TestCase
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

    public function testGrantingBackpackCreatesLinkedContainerInstance(): void
    {
        $result = (new InventoryAutoPlacementService($this->pdo))->grantAndPlace(new GrantItemRequest(
            1,
            'small_leather_backpack',
            1,
            'common',
            45.0,
            null
        ));

        $this->assertSame('placed', $result['action']);
        $this->assertNotEmpty($result['linked_container_public_id']);
        $this->assertSame('small_backpack', $result['linked_container_definition_code']);
        $this->assertSame(1, $this->linkedContainerCount($result['item_public_id']));
    }

    public function testEnsureForItemIsIdempotent(): void
    {
        $item = $this->loadItemByCode('small_leather_backpack');
        if ($item === null) {
            (new InventoryAutoPlacementService($this->pdo))->grantAndPlace(new GrantItemRequest(
                1,
                'small_leather_backpack',
                1,
                'common',
                45.0,
                null
            ));
            $item = $this->loadItemByCode('small_leather_backpack');
        }

        $service = new PhysicalContainerLinkService($this->pdo);
        $first = $service->ensureForItem(1, $item);
        $second = $service->ensureForItem(1, $item);

        $this->assertNotNull($first);
        $this->assertSame($first['public_id'], $second['public_id']);
        $this->assertSame(1, $this->linkedContainerCount((string) $item['public_id']));
    }

    public function testStarterBackpackStillCreatesLinkedContainer(): void
    {
        (new StarterInventoryService($this->pdo))->ensureForPlayer(1, true);

        $itemPublicId = $this->itemPublicId('small_leather_backpack');
        $this->assertNotEmpty($itemPublicId);
        $this->assertSame(1, $this->linkedContainerCount($itemPublicId));
    }

    private function linkedContainerCount(string $itemPublicId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*)
            FROM container_instances cinst
            INNER JOIN item_instances ii ON ii.id = cinst.source_item_instance_id
            WHERE ii.public_id = :public_id AND cinst.status = :status');
        $stmt->execute([
            'public_id' => $itemPublicId,
            'status' => 'active',
        ]);

        return (int) $stmt->fetchColumn();
    }

    private function loadItemByCode(string $code): ?array
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
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function itemPublicId(string $code): string
    {
        $stmt = $this->pdo->prepare('SELECT ii.public_id FROM item_instances ii INNER JOIN item_definitions id ON id.id = ii.item_definition_id WHERE id.code = :code LIMIT 1');
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
