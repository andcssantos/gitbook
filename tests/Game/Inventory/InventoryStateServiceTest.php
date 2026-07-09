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

        $this->assertSame(['columns' => 8, 'rows' => 5], $main['grid']);
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
}
