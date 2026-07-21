<?php

namespace App\Game\Market\Services;

use App\Game\Containers\Repositories\ContainerRepository;
use App\Game\Containers\Services\ContainerAcceptanceService;
use App\Game\Containers\Services\ContainerNestingService;
use App\Game\Inventory\Services\GridFreeSpaceFinder;
use App\Game\Inventory\Services\InventoryPlacementValidator;
use App\Game\Items\Services\ItemSafetyService;
use App\Support\DB;
use App\Support\PublicId;
use App\Utils\Config;
use PDO;
use Throwable;

class MarketListingService
{
    public function __construct(
        private ?PDO $pdo = null,
        private ?MarketItemContextService $context = null,
        private ?ItemMarketEligibilityService $eligibility = null,
        private ?MarketPriceService $pricing = null,
        private ?PlayerCurrencyService $currencies = null
    ) {
        $this->context ??= new MarketItemContextService($this->pdo);
        $this->eligibility ??= new ItemMarketEligibilityService($this->pdo);
        $this->pricing ??= new MarketPriceService($this->pdo);
        $this->currencies ??= new PlayerCurrencyService($this->pdo);
    }

    public function createListing(int $playerId, string $itemPublicId, int $pricePremium): array
    {
        return $this->transaction(function () use ($playerId, $itemPublicId, $pricePremium): array {
            $item = $this->context->forOwnedItem($playerId, $itemPublicId, true);
            if ($item === null) {
                throw new \App\Game\Inventory\InventoryException('INVENTORY_ITEM_NOT_FOUND', 'Inventory item was not found.', 404);
            }

            (new ItemSafetyService($this->pdo()))->assertNotLocked($playerId, (int) $item['item_instance_id'], 'LIST_MARKET');
            $this->eligibility->assertSellable($item);
            $quote = $this->pricing->quote($item);
            $bounds = $this->pricing->listingPriceBounds((int) $quote['suggested_premium']);
            $min = (int) $bounds['min_premium'];
            $max = (int) $bounds['max_premium'];
            if ($pricePremium < $min || $pricePremium > $max) {
                throw new \App\Game\Inventory\InventoryException(
                    'MARKET_INVALID_LISTING_PRICE',
                    "Preco deve estar entre {$min} e {$max} Eter Cristal.",
                    422
                );
            }
            $listingFee = (int) Config::get('market.listing.fee_premium', 1);

            $this->currencies->debit($playerId, 'premium', $listingFee, 'market_listing_fee', 'item', $itemPublicId);

            $escrowContainer = $this->ensureEscrowContainer($playerId);
            $this->moveItemToEscrow($item, $escrowContainer);

            $listingPublicId = PublicId::uuid();
            $stmt = $this->pdo()->prepare("INSERT INTO market_listings (public_id, seller_player_id, item_instance_id, profile_key, price_premium, listing_fee_premium, status) VALUES (:public_id, :seller_player_id, :item_instance_id, :profile_key, :price_premium, :listing_fee_premium, 'active')");
            $stmt->execute([
                'public_id' => $listingPublicId,
                'seller_player_id' => $playerId,
                'item_instance_id' => (int) $item['item_instance_id'],
                'profile_key' => (string) $quote['profile_key'],
                'price_premium' => $pricePremium,
                'listing_fee_premium' => $listingFee,
            ]);
            (new ItemSafetyService($this->pdo()))->record($item, $playerId, 'listed_market', [
                'listing_public_id' => $listingPublicId,
                'price_premium' => $pricePremium,
            ]);

            return [
                'action' => 'LIST_MARKET',
                'listing_public_id' => $listingPublicId,
                'item_public_id' => $itemPublicId,
                'price_premium' => $pricePremium,
                'listing_fee_premium' => $listingFee,
                'suggested_market_value' => (int) $quote['market_value'],
                'listing_price_min' => $min,
                'listing_price_max' => $max,
            ];
        });
    }

    public function cancelListing(int $playerId, string $listingPublicId): array
    {
        return $this->transaction(function () use ($playerId, $listingPublicId): array {
            $listing = $this->lockListing($listingPublicId);
            if ($listing === null) {
                throw new \App\Game\Inventory\InventoryException('MARKET_LISTING_NOT_FOUND', 'Anuncio nao encontrado.', 404);
            }

            if ((int) $listing['seller_player_id'] !== $playerId) {
                throw new \App\Game\Inventory\InventoryException('MARKET_CANNOT_CANCEL_LISTING', 'Voce so pode remover seus proprios anuncios.', 403);
            }

            $this->moveItemFromEscrowToMainInventory($playerId, (int) $listing['item_instance_id']);

            $this->pdo()->prepare("UPDATE market_listings SET status = 'cancelled', cancelled_at = CURRENT_TIMESTAMP WHERE id = :id")
                ->execute(['id' => (int) $listing['id']]);

            return [
                'listing_public_id' => $listingPublicId,
                'item_instance_id' => (int) $listing['item_instance_id'],
                'status' => 'cancelled',
            ];
        });
    }

    public function buyListing(int $buyerPlayerId, string $listingPublicId): array
    {
        return $this->transaction(function () use ($buyerPlayerId, $listingPublicId): array {
            $listing = $this->lockListing($listingPublicId);
            if ($listing === null) {
                throw new \App\Game\Inventory\InventoryException('MARKET_LISTING_NOT_FOUND', 'Anuncio nao encontrado.', 404);
            }

            if ((int) $listing['seller_player_id'] === $buyerPlayerId) {
                throw new \App\Game\Inventory\InventoryException('MARKET_CANNOT_BUY_OWN_LISTING', 'Voce nao pode comprar seu proprio anuncio.', 422);
            }

            $price = (int) $listing['price_premium'];
            $feePercent = (float) Config::get('market.listing.seller_fee_percent', 0.05);
            $sellerFee = (int) floor($price * $feePercent);
            $sellerNet = max(0, $price - $sellerFee);

            $this->currencies->debit($buyerPlayerId, 'premium', $price, 'market_buy', 'listing', $listingPublicId);
            $this->currencies->credit((int) $listing['seller_player_id'], 'premium', $sellerNet, 'market_sale', 'listing', $listingPublicId, [
                'gross' => $price,
                'fee' => $sellerFee,
            ]);

            $deliveryContainer = $this->ensureDeliveryContainer($buyerPlayerId);
            $this->moveListedItemToDelivery((int) $listing['item_instance_id'], $deliveryContainer);

            $transactionPublicId = PublicId::uuid();
            $this->pdo()->prepare('INSERT INTO market_transactions (public_id, listing_id, buyer_player_id, seller_player_id, item_instance_id, price_premium, seller_fee_premium, seller_net_premium) VALUES (:public_id, :listing_id, :buyer_player_id, :seller_player_id, :item_instance_id, :price_premium, :seller_fee_premium, :seller_net_premium)')
                ->execute([
                    'public_id' => $transactionPublicId,
                    'listing_id' => (int) $listing['id'],
                    'buyer_player_id' => $buyerPlayerId,
                    'seller_player_id' => (int) $listing['seller_player_id'],
                    'item_instance_id' => (int) $listing['item_instance_id'],
                    'price_premium' => $price,
                    'seller_fee_premium' => $sellerFee,
                    'seller_net_premium' => $sellerNet,
                ]);

            $this->pdo()->prepare("UPDATE market_listings SET status = 'sold', sold_at = CURRENT_TIMESTAMP, buyer_player_id = :buyer_player_id WHERE id = :id")
                ->execute([
                    'buyer_player_id' => $buyerPlayerId,
                    'id' => (int) $listing['id'],
                ]);

            return [
                'transaction_public_id' => $transactionPublicId,
                'listing_public_id' => $listingPublicId,
                'price_premium' => $price,
                'seller_fee_premium' => $sellerFee,
                'delivery_container_public_id' => (string) $deliveryContainer['public_id'],
            ];
        });
    }

    public function listActive(int $limit = 50, ?int $viewerPlayerId = null): array
    {
        return $this->searchListings([], $limit, $viewerPlayerId);
    }

    public function searchListings(array $filters = [], int $limit = 50, ?int $viewerPlayerId = null): array
    {
        $limit = max(1, min(200, $limit));
        $query = trim((string) ($filters['q'] ?? ''));
        $qualityBucket = strtolower(trim((string) ($filters['quality_bucket'] ?? '')));
        $categoryCode = strtolower(trim((string) ($filters['category_code'] ?? '')));
        $minPrice = isset($filters['min_price']) && $filters['min_price'] !== '' ? max(0, (int) $filters['min_price']) : null;
        $maxPrice = isset($filters['max_price']) && $filters['max_price'] !== '' ? max(0, (int) $filters['max_price']) : null;

        $sql = "SELECT
                ml.id AS listing_id,
                ml.public_id,
                ml.seller_player_id,
                ml.price_premium,
                ml.profile_key,
                ml.listed_at,
                ml.listing_fee_premium,
                ii.id AS item_instance_id,
                ii.public_id AS item_public_id,
                id.code AS definition_code,
                id.name AS definition_name,
                ii.quality_bucket,
                ii.quality_value,
                ic.code AS category_code,
                p.public_id AS seller_public_id,
                p.name AS seller_name,
                p.level AS seller_level
            FROM market_listings ml
            INNER JOIN item_instances ii ON ii.id = ml.item_instance_id
            INNER JOIN item_definitions id ON id.id = ii.item_definition_id
            INNER JOIN item_categories ic ON ic.id = id.category_id
            INNER JOIN players p ON p.id = ml.seller_player_id
            WHERE ml.status = 'active'";

        $params = [];
        if ($query !== '') {
            $sql .= ' AND (id.name LIKE :query OR id.code LIKE :query OR p.name LIKE :query)';
            $params['query'] = '%' . $query . '%';
        }
        if ($qualityBucket !== '') {
            $sql .= ' AND ii.quality_bucket = :quality_bucket';
            $params['quality_bucket'] = $qualityBucket;
        }
        if ($categoryCode !== '') {
            $sql .= ' AND ic.code = :category_code';
            $params['category_code'] = $categoryCode;
        }
        if ($minPrice !== null) {
            $sql .= ' AND ml.price_premium >= :min_price';
            $params['min_price'] = $minPrice;
        }
        if ($maxPrice !== null) {
            $sql .= ' AND ml.price_premium <= :max_price';
            $params['max_price'] = $maxPrice;
        }

        $sql .= ' ORDER BY ml.listed_at DESC LIMIT :limit';

        $stmt = $this->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            fn (array $row): array => $this->presentListingRow($row, $viewerPlayerId),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    private function presentListingRow(array $row, ?int $viewerPlayerId): array
    {
        $sellerPlayerId = (int) $row['seller_player_id'];
        $item = $this->context->forListedItem((int) $row['item_instance_id']) ?? [
            'public_id' => (string) $row['item_public_id'],
            'definition' => [
                'code' => (string) $row['definition_code'],
                'name' => (string) $row['definition_name'],
                'category_code' => (string) $row['category_code'],
            ],
            'quality_bucket' => $row['quality_bucket'] !== null ? (string) $row['quality_bucket'] : null,
            'quality_value' => $row['quality_value'] !== null ? (float) $row['quality_value'] : null,
            'category_code' => (string) $row['category_code'],
        ];

        return [
            'listing_public_id' => (string) $row['public_id'],
            'price_premium' => (int) $row['price_premium'],
            'listing_fee_premium' => (int) ($row['listing_fee_premium'] ?? 0),
            'profile_key' => (string) $row['profile_key'],
            'listed_at' => (string) $row['listed_at'],
            'is_own_listing' => $viewerPlayerId !== null && $viewerPlayerId === $sellerPlayerId,
            'seller' => [
                'player_id' => $sellerPlayerId,
                'public_id' => (string) $row['seller_public_id'],
                'name' => (string) $row['seller_name'],
                'level' => (int) ($row['seller_level'] ?? 1),
            ],
            'item' => $item,
        ];
    }

    private function lockListing(string $listingPublicId): ?array
    {
        $lock = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $stmt = $this->pdo()->prepare("SELECT * FROM market_listings WHERE public_id = :public_id AND status = 'active' LIMIT 1{$lock}");
        $stmt->execute(['public_id' => $listingPublicId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function moveItemToEscrow(array $item, array $escrowContainer): void
    {
        $containers = new ContainerRepository($this->pdo());
        $placement = $containers->findPlacementByItemId((int) $item['item_instance_id'], true);
        if ($placement === null) {
            throw new \App\Game\Inventory\InventoryException('INVENTORY_ITEM_NOT_PLACED', 'Item nao esta em um container valido.', 422);
        }

        $containers->deletePlacementByItemId((int) $item['item_instance_id']);

        $gridW = (int) ($placement['grid_w'] ?? $item['definition']['grid_w'] ?? 1);
        $gridH = (int) ($placement['grid_h'] ?? $item['definition']['grid_h'] ?? 1);
        $finder = new GridFreeSpaceFinder(new InventoryPlacementValidator(
            new ContainerAcceptanceService(null, $this->pdo()),
            new ContainerNestingService($this->pdo())
        ));
        $spot = $finder->findFirst([
            'definition_grid_w' => $gridW,
            'definition_grid_h' => $gridH,
        ], $escrowContainer, [], (bool) ($placement['rotated'] ?? false));

        if ($spot === null) {
            throw new \App\Game\Inventory\InventoryException('MARKET_ESCROW_FULL', 'Escrow do mercado esta cheio.', 409);
        }

        $containers->placeItem([
            'container_instance_id' => (int) $escrowContainer['id'],
            'item_instance_id' => (int) $item['item_instance_id'],
            'grid_x' => (int) $spot['grid_x'],
            'grid_y' => (int) $spot['grid_y'],
            'grid_w' => (int) $spot['grid_w'],
            'grid_h' => (int) $spot['grid_h'],
            'rotated' => (int) ($spot['rotated'] ?? 0),
            'locked' => 1,
        ]);
    }

    private function moveListedItemToDelivery(int $itemInstanceId, array $deliveryContainer): void
    {
        $containers = new ContainerRepository($this->pdo());
        $placement = $containers->findPlacementByItemId($itemInstanceId, true);
        if ($placement === null) {
            throw new \App\Game\Inventory\InventoryException('INVENTORY_ITEM_NOT_PLACED', 'Item listado nao foi encontrado no escrow.', 422);
        }

        $containers->deletePlacementByItemId($itemInstanceId);

        $finder = new GridFreeSpaceFinder(new InventoryPlacementValidator(
            new ContainerAcceptanceService(null, $this->pdo()),
            new ContainerNestingService($this->pdo())
        ));
        $spot = $finder->findFirst([
            'definition_grid_w' => (int) $placement['grid_w'],
            'definition_grid_h' => (int) $placement['grid_h'],
        ], $deliveryContainer, [], (bool) ($placement['rotated'] ?? false));

        if ($spot === null) {
            throw new \App\Game\Inventory\InventoryException('MARKET_DELIVERY_FULL', 'Sua caixa de entregas esta cheia.', 409);
        }

        $containers->placeItem([
            'container_instance_id' => (int) $deliveryContainer['id'],
            'item_instance_id' => $itemInstanceId,
            'grid_x' => (int) $spot['grid_x'],
            'grid_y' => (int) $spot['grid_y'],
            'grid_w' => (int) $spot['grid_w'],
            'grid_h' => (int) $spot['grid_h'],
            'rotated' => (int) ($placement['rotated'] ?? 0),
            'locked' => 0,
        ]);
    }

    private function moveItemFromEscrowToMainInventory(int $playerId, int $itemInstanceId): void
    {
        $containers = new ContainerRepository($this->pdo());
        $placement = $containers->findPlacementByItemId($itemInstanceId, true);
        if ($placement === null) {
            throw new \App\Game\Inventory\InventoryException('INVENTORY_ITEM_NOT_PLACED', 'Item listado nao foi encontrado no escrow.', 422);
        }

        $mainContainer = $this->ensurePlayerContainer($playerId, 'main_inventory_level_1', 10);
        $containers->deletePlacementByItemId($itemInstanceId);

        $finder = new GridFreeSpaceFinder(new InventoryPlacementValidator(
            new ContainerAcceptanceService(null, $this->pdo()),
            new ContainerNestingService($this->pdo())
        ));
        $spot = $finder->findFirst([
            'definition_grid_w' => (int) $placement['grid_w'],
            'definition_grid_h' => (int) $placement['grid_h'],
        ], $mainContainer, [], (bool) ($placement['rotated'] ?? false));

        if ($spot === null) {
            throw new \App\Game\Inventory\InventoryException('MARKET_CANCEL_INVENTORY_FULL', 'Inventario principal cheio. Libere espaco para remover o anuncio.', 409);
        }

        $containers->placeItem([
            'container_instance_id' => (int) $mainContainer['id'],
            'item_instance_id' => $itemInstanceId,
            'grid_x' => (int) $spot['grid_x'],
            'grid_y' => (int) $spot['grid_y'],
            'grid_w' => (int) $spot['grid_w'],
            'grid_h' => (int) $spot['grid_h'],
            'rotated' => (int) ($placement['rotated'] ?? 0),
            'locked' => 0,
        ]);
    }

    private function ensureEscrowContainer(int $playerId): array
    {
        return $this->ensurePlayerContainer($playerId, 'market_escrow', 80);
    }

    private function ensureDeliveryContainer(int $playerId): array
    {
        return $this->ensurePlayerContainer($playerId, 'market_delivery', 20);
    }

    private function ensurePlayerContainer(int $playerId, string $definitionCode, int $sortOrder): array
    {
        $containers = new ContainerRepository($this->pdo());
        $existing = $this->pdo()->prepare('SELECT ci.*, cd.code AS definition_code, cd.container_type
            FROM container_instances ci
            INNER JOIN container_definitions cd ON cd.id = ci.container_definition_id
            WHERE ci.owner_player_id = :player_id AND cd.code = :code AND ci.status = :status
            LIMIT 1');
        $existing->execute([
            'player_id' => $playerId,
            'code' => $definitionCode,
            'status' => 'active',
        ]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return $row;
        }

        $definition = $containers->findDefinition($definitionCode);
        if ($definition === null) {
            throw new \App\Game\Inventory\InventoryException('INVENTORY_CONTAINER_NOT_FOUND', 'Container de mercado nao configurado.', 500);
        }

        $containerId = $containers->createInstanceFromDefinition($definition, $playerId, [
            'sort_order' => $sortOrder,
        ]);

        $created = $containers->findInstanceById($containerId);
        if ($created === null) {
            throw new \App\Game\Inventory\InventoryException('INVENTORY_CONTAINER_NOT_FOUND', 'Falha ao criar container de mercado.', 500);
        }

        return $created;
    }

    private function transaction(callable $callback): mixed
    {
        if ($this->pdo instanceof PDO) {
            $started = !$this->pdo->inTransaction();
            if ($started) {
                $this->pdo->beginTransaction();
            }

            try {
                $result = $callback();
                if ($started) {
                    $this->pdo->commit();
                }

                return $result;
            } catch (Throwable $e) {
                if ($started && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                throw $e;
            }
        }

        return DB::transaction($callback);
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
