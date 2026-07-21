<?php

namespace App\Controllers\App\Api;

use App\Core\Controller;
use App\Game\Inventory\InventoryException;
use App\Game\Market\Services\MarketListingService;
use App\Game\Market\Services\MarketHistoryService;
use App\Game\Market\Services\NpcSellService;
use App\Game\Market\Services\PlayerCurrencyService;
use App\Game\Player\Services\PlayerResolver;
use App\Http\Request;
use App\Validation\ValidationException;

class MarketController extends Controller
{
    public function wallets(): void
    {
        try {
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $wallets = (new PlayerCurrencyService())->walletsForPlayer((int) $player['id']);

            $this->success(['wallets' => $wallets], 'Player currency wallets.');
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function listings(): void
    {
        try {
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $query = Request::query();
            $filters = [
                'q' => $query['q'] ?? null,
                'quality_bucket' => $query['quality_bucket'] ?? null,
                'category_code' => $query['category_code'] ?? null,
                'min_price' => $query['min_price'] ?? null,
                'max_price' => $query['max_price'] ?? null,
            ];
            $listings = (new MarketListingService())->searchListings($filters, (int) ($query['limit'] ?? 50), (int) $player['id']);

            $this->success(['listings' => $listings], 'Active market listings.');
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function pricePreview(array $params = []): void
    {
        try {
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $itemPublicId = (string) ($params['itemPublicId'] ?? '');
            $preview = (new NpcSellService())->preview((int) $player['id'], $itemPublicId);

            $this->success($preview, 'Market price preview.');
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function buyListing(array $params = []): void
    {
        try {
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $listingPublicId = (string) ($params['listingPublicId'] ?? '');
            $result = (new MarketListingService())->buyListing((int) $player['id'], $listingPublicId);

            $this->success($result, 'Market listing purchased.');
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function cancelListing(array $params = []): void
    {
        try {
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $listingPublicId = (string) ($params['listingPublicId'] ?? '');
            $result = (new MarketListingService())->cancelListing((int) $player['id'], $listingPublicId);

            $this->success($result, 'Market listing cancelled.');
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function myListings(): void
    {
        try {
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $this->success((new MarketHistoryService())->myListings((int) $player['id']), 'My market listings.');
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function myHistory(): void
    {
        try {
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $this->success((new MarketHistoryService())->myTransactions((int) $player['id']), 'My market transactions.');
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }
}
