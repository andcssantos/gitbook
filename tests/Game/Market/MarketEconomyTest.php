<?php

namespace Tests\Game\Market;

use App\Game\Inventory\Services\StarterInventoryService;
use App\Game\Items\Services\ItemActionAvailabilityService;
use App\Game\Items\Services\ItemActionExecuteService;
use App\Game\Market\Services\MarketListingService;
use App\Game\Market\Services\MarketPriceService;
use App\Game\Market\Services\MarketSupplyDemandRecalculateService;
use App\Game\Market\Services\NpcSellService;
use App\Game\Market\Services\PlayerCurrencyService;
use PDO;
use PHPUnit\Framework\TestCase;

class MarketEconomyTest extends TestCase
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
        ] as $migrationFile) {
            $migration = require __DIR__ . '/../../../database/migrations/' . $migrationFile;
            $migration->up($this->pdo);
        }

        $seed = require __DIR__ . '/../../../database/seeds/001_evolvaxe_foundation_seed.php';
        $seed($this->pdo);

        $marketSeed = require __DIR__ . '/../../../database/seeds/006_market_economy_seed.php';
        $marketSeed($this->pdo);

        $this->createPlayer(1, 'player-1', 'Tester');
        (new StarterInventoryService($this->pdo))->ensureForPlayer(1);
    }

    public function testWoodCanBeSoldToNpc(): void
    {
        $publicId = $this->itemPublicId('wood');
        $actions = (new ItemActionAvailabilityService($this->pdo))->listForItem($this->loadItem('wood'));
        $codes = array_column($actions, 'code');

        $this->assertContains('SELL', $codes);

        $result = (new ItemActionExecuteService($this->pdo))->execute(1, $publicId, 'SELL', true);
        $this->assertSame('SELL', $result['action']);
        $this->assertGreaterThan(0, $result['gold_received']);
        $this->assertGreaterThan($result['gold_received'], $result['market_value']);

        $gold = (new PlayerCurrencyService($this->pdo))->balance(1, 'gold');
        $this->assertGreaterThanOrEqual(500 + $result['gold_received'], $gold);
    }

    public function testPriceQuoteUsesDynamicFormula(): void
    {
        $item = (new \App\Game\Market\Services\MarketItemContextService($this->pdo))->forOwnedItem(1, $this->itemPublicId('wood'));
        $quote = (new MarketPriceService($this->pdo))->quote($item);

        $this->assertGreaterThan(0, $quote['market_value']);
        $this->assertGreaterThan(0, $quote['npc_value']);
        $this->assertGreaterThan(0, $quote['suggested_premium']);
        $this->assertLessThan($quote['market_value'], $quote['npc_value']);
        $this->assertNotEmpty($quote['profile_key']);
    }

    public function testRecalculateUpdatesDemandProfiles(): void
    {
        $itemPublicId = $this->itemPublicId('wood');
        $listing = (new ItemActionExecuteService($this->pdo))->execute(1, $itemPublicId, 'LIST_MARKET', true, [
            'price_premium' => 4,
        ]);

        $result = (new MarketSupplyDemandRecalculateService($this->pdo))->recalculate();
        $this->assertFalse($result['skipped']);
        $this->assertGreaterThanOrEqual(1, $result['profiles_updated']);

        $profileStmt = $this->pdo->prepare('SELECT profile_key FROM market_listings WHERE public_id = :public_id LIMIT 1');
        $profileStmt->execute(['public_id' => $listing['listing_public_id']]);
        $profileKey = (string) $profileStmt->fetchColumn();

        $stmt = $this->pdo->prepare('SELECT demand_factor, similar_listings_count FROM market_supply_demand WHERE profile_key = :profile_key LIMIT 1');
        $stmt->execute(['profile_key' => $profileKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertGreaterThanOrEqual(1, (int) $row['similar_listings_count']);
        $this->assertGreaterThan(0, (float) $row['demand_factor']);
    }

    public function testListingSearchFiltersByQuality(): void
    {
        $itemPublicId = $this->itemPublicId('wood');
        (new ItemActionExecuteService($this->pdo))->execute(1, $itemPublicId, 'LIST_MARKET', true, [
            'price_premium' => 3,
        ]);

        $all = (new MarketListingService($this->pdo))->searchListings([], 20);
        $filtered = (new MarketListingService($this->pdo))->searchListings(['quality_bucket' => 'common'], 20);

        $this->assertNotEmpty($all);
        $this->assertCount(count($all), $filtered);
    }

    public function testListingRejectsPriceOutsideBounds(): void
    {
        $itemPublicId = $this->itemPublicId('wood');
        $item = (new \App\Game\Market\Services\MarketItemContextService($this->pdo))->forOwnedItem(1, $itemPublicId);
        $quote = (new MarketPriceService($this->pdo))->quote($item);
        $bounds = (new MarketPriceService($this->pdo))->listingPriceBounds((int) $quote['suggested_premium']);

        $this->expectException(\App\Game\Inventory\InventoryException::class);
        (new MarketListingService($this->pdo))->createListing(1, $itemPublicId, (int) $bounds['max_premium'] + 10);
    }

    public function testListingSearchIncludesSellerAndItemDetails(): void
    {
        $itemPublicId = $this->itemPublicId('wood');
        (new ItemActionExecuteService($this->pdo))->execute(1, $itemPublicId, 'LIST_MARKET', true, [
            'price_premium' => 3,
        ]);

        $listings = (new MarketListingService($this->pdo))->searchListings([], 20, 1);
        $this->assertNotEmpty($listings);
        $this->assertSame('Tester', $listings[0]['seller']['name']);
        $this->assertTrue($listings[0]['is_own_listing']);
        $this->assertSame('wood', $listings[0]['item']['definition']['code'] ?? $listings[0]['item']['definition_code'] ?? null);
        $this->assertGreaterThan(0, (int) ($listings[0]['item']['market_value'] ?? 0));
    }

    public function testSellerCanCancelListingAndRecoverItem(): void
    {
        $itemPublicId = $this->itemPublicId('wood');
        $listing = (new ItemActionExecuteService($this->pdo))->execute(1, $itemPublicId, 'LIST_MARKET', true, [
            'price_premium' => 3,
        ]);

        $result = (new MarketListingService($this->pdo))->cancelListing(1, $listing['listing_public_id']);
        $this->assertSame('cancelled', $result['status']);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM container_items ci
            INNER JOIN container_instances cinst ON cinst.id = ci.container_instance_id
            INNER JOIN container_definitions cd ON cd.id = cinst.container_definition_id
            INNER JOIN item_instances ii ON ii.id = ci.item_instance_id
            WHERE cinst.owner_player_id = 1 AND cd.code = :code AND ii.public_id = :public_id');
        $stmt->execute([
            'code' => 'main_inventory_level_1',
            'public_id' => $itemPublicId,
        ]);

        $this->assertSame(1, (int) $stmt->fetchColumn());

        $active = (new MarketListingService($this->pdo))->searchListings([], 20, 1);
        $this->assertCount(0, $active);
    }

    public function testListingAndBuyMovesItemToDelivery(): void
    {
        $sellerId = 1;
        $buyerId = 2;
        $this->createPlayer(2, 'player-2', 'Buyer');
        (new StarterInventoryService($this->pdo))->ensureForPlayer(2);

        $itemPublicId = $this->itemPublicId('wood');
        $listing = (new ItemActionExecuteService($this->pdo))->execute($sellerId, $itemPublicId, 'LIST_MARKET', true, [
            'price_premium' => 4,
        ]);

        $this->assertSame('LIST_MARKET', $listing['action']);

        $buy = (new MarketListingService($this->pdo))->buyListing($buyerId, $listing['listing_public_id']);
        $this->assertNotEmpty($buy['delivery_container_public_id']);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM container_items ci
            INNER JOIN container_instances cinst ON cinst.id = ci.container_instance_id
            INNER JOIN container_definitions cd ON cd.id = cinst.container_definition_id
            INNER JOIN item_instances ii ON ii.id = ci.item_instance_id
            WHERE cinst.owner_player_id = :player_id AND cd.code = :code AND ii.public_id = :public_id');
        $stmt->execute([
            'player_id' => $buyerId,
            'code' => 'market_delivery',
            'public_id' => $itemPublicId,
        ]);

        $this->assertSame(1, (int) $stmt->fetchColumn());
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
