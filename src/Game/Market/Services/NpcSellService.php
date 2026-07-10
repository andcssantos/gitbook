<?php

namespace App\Game\Market\Services;

use App\Game\Containers\Repositories\ContainerRepository;
use App\Game\Items\Repositories\ItemInstanceRepository;
use App\Game\Items\Repositories\ItemMaterialCompositionRepository;
use App\Support\DB;
use PDO;
use Throwable;

class NpcSellService
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

    public function sell(int $playerId, string $itemPublicId): array
    {
        return $this->transaction(function () use ($playerId, $itemPublicId): array {
            $item = $this->context->forOwnedItem($playerId, $itemPublicId, true);
            if ($item === null) {
                throw new \App\Game\Inventory\InventoryException('INVENTORY_ITEM_NOT_FOUND', 'Inventory item was not found.', 404);
            }

            $this->eligibility->assertSellable($item);
            $quote = $this->pricing->quote($item);
            $this->pricing->recordHistory((int) $item['item_instance_id'], $quote);

            $this->removeItem($item);
            $goldBalance = $this->currencies->credit(
                $playerId,
                'gold',
                (int) $quote['npc_value'],
                'npc_sell',
                'item',
                $itemPublicId,
                ['market_value' => $quote['market_value']]
            );

            return [
                'action' => 'SELL',
                'item_public_id' => $itemPublicId,
                'gold_received' => (int) $quote['npc_value'],
                'market_value' => (int) $quote['market_value'],
                'gold_balance' => $goldBalance,
                'breakdown' => $quote['breakdown'],
            ];
        });
    }

    public function preview(int $playerId, string $itemPublicId): array
    {
        $item = $this->context->forOwnedItem($playerId, $itemPublicId);
        if ($item === null) {
            throw new \App\Game\Inventory\InventoryException('INVENTORY_ITEM_NOT_FOUND', 'Inventory item was not found.', 404);
        }

        $quote = $this->pricing->quote($item);
        $reason = $this->eligibility->evaluate($item);

        return [
            'item_public_id' => $itemPublicId,
            'sellable' => $reason === null,
            'blocked_reason' => $reason,
            'market_value' => (int) $quote['market_value'],
            'npc_value' => (int) $quote['npc_value'],
            'npc_rate' => (float) $quote['npc_rate'],
            'breakdown' => $quote['breakdown'],
        ];
    }

    private function removeItem(array $item): void
    {
        $itemId = (int) $item['item_instance_id'];

        if ((bool) ($item['is_container'] ?? false)) {
            $containers = new ContainerRepository($this->pdo());
            $linked = $containers->findInstanceBySourceItemId($itemId, true);
            if ($linked !== null) {
                $this->pdo()->prepare('UPDATE container_instances SET status = :status WHERE id = :id')->execute([
                    'id' => (int) $linked['id'],
                    'status' => 'inactive',
                ]);
            }
        }

        $containers = new ContainerRepository($this->pdo());
        $containers->deletePlacementByItemId($itemId);

        (new ItemMaterialCompositionRepository($this->pdo()))->deleteForItem($itemId);
        (new ItemInstanceRepository($this->pdo()))->deleteById($itemId);
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
