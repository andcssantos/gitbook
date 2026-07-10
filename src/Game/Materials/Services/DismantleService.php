<?php

namespace App\Game\Materials\Services;

use App\Game\Containers\Repositories\ContainerRepository;
use App\Game\Inventory\InventoryException;
use App\Game\Items\Repositories\ItemInstanceRepository;
use App\Game\Items\Repositories\ItemMaterialCompositionRepository;
use App\Game\Market\Services\MarketItemContextService;
use App\Support\DB;
use PDO;
use Throwable;

class DismantleService
{
    public function __construct(
        private ?PDO $connection = null,
        private ?MarketItemContextService $context = null,
        private ?DismantleYieldCalculator $yieldCalculator = null,
        private ?PlayerMaterialStashService $stash = null
    ) {
        $this->context ??= new MarketItemContextService($this->connection);
        $this->yieldCalculator ??= new DismantleYieldCalculator($this->connection);
        $this->stash ??= new PlayerMaterialStashService($this->connection);
    }

    public function dismantle(int $playerId, string $itemPublicId): array
    {
        return $this->transaction(function () use ($playerId, $itemPublicId): array {
            $item = $this->context->forOwnedItem($playerId, $itemPublicId, true);
            if ($item === null) {
                throw new InventoryException('INVENTORY_ITEM_NOT_FOUND', 'Inventory item was not found.', 404);
            }

            $this->assertDismantleable($item);
            $yields = $this->yieldCalculator->preview($item);
            if ($yields === []) {
                throw new InventoryException('DISMANTLE_NO_COMPOSITION', 'Este item nao pode ser desmanchado.', 422);
            }

            foreach ($yields as $yield) {
                $this->stash->credit(
                    $playerId,
                    (int) $yield['material_family_id'],
                    (int) $yield['material_origin_id'],
                    (int) $yield['quantity'],
                    (string) $yield['stash_tab']
                );
            }

            $this->removeItem($item);

            return [
                'action' => 'DISMANTLE',
                'item_public_id' => $itemPublicId,
                'materials' => $yields,
            ];
        });
    }

    public function canDismantle(array $item): bool
    {
        try {
            $this->assertDismantleable($item);

            return $this->yieldCalculator->preview($item) !== [];
        } catch (InventoryException) {
            return false;
        }
    }

    private function assertDismantleable(array $item): void
    {
        if ((int) ($item['is_container'] ?? 0) === 1) {
            $containers = new ContainerRepository($this->pdo());
            $linked = $containers->findInstanceBySourceItemId((int) ($item['item_instance_id'] ?? $item['id'] ?? 0), false);
            if ($linked !== null && $containers->countItems((int) $linked['id']) > 0) {
                throw new InventoryException('DISMANTLE_CONTAINER_NOT_EMPTY', 'Esvazie o container antes de desmanchar.', 422);
            }
        }

        if ((bool) ($item['is_collectible'] ?? false) || (bool) ($item['is_event_item'] ?? false)) {
            throw new InventoryException('DISMANTLE_PROTECTED_ITEM', 'Itens de colecao ou evento nao podem ser desmanchados.', 422);
        }

        if ($this->isListed((int) ($item['item_instance_id'] ?? $item['id'] ?? 0))) {
            throw new InventoryException('DISMANTLE_ITEM_LISTED', 'Remova o anuncio do mercado antes de desmanchar.', 422);
        }

        $category = strtolower((string) ($item['category_code'] ?? ''));
        if ($category === 'currency') {
            throw new InventoryException('DISMANTLE_PROTECTED_ITEM', 'Moedas nao podem ser desmanchadas.', 422);
        }
    }

    private function isListed(int $itemInstanceId): bool
    {
        if ($itemInstanceId <= 0 || !$this->tableExists('market_listings')) {
            return false;
        }

        $stmt = $this->pdo()->prepare("SELECT id FROM market_listings WHERE item_instance_id = :item_instance_id AND status = 'active' LIMIT 1");
        $stmt->execute(['item_instance_id' => $itemInstanceId]);

        return $stmt->fetchColumn() !== false;
    }

    private function removeItem(array $item): void
    {
        $itemInstanceId = (int) ($item['item_instance_id'] ?? $item['id'] ?? 0);
        $containers = new ContainerRepository($this->pdo());
        $containers->deletePlacementByItemId($itemInstanceId);

        if ((int) ($item['is_container'] ?? $item['definition']['is_container'] ?? 0) === 1) {
            $linked = $containers->findInstanceBySourceItemId($itemInstanceId, true);
            if ($linked !== null) {
                $this->pdo()->prepare('UPDATE container_instances SET status = :status WHERE id = :id')
                    ->execute(['id' => (int) $linked['id'], 'status' => 'inactive']);
            }
        }

        (new ItemMaterialCompositionRepository($this->pdo()))->deleteForItem($itemInstanceId);
        (new ItemInstanceRepository($this->pdo()))->deleteById($itemInstanceId);
    }

    private function transaction(callable $callback): mixed
    {
        if ($this->connection instanceof PDO) {
            $started = !$this->connection->inTransaction();
            if ($started) {
                $this->connection->beginTransaction();
            }

            try {
                $result = $callback();
                if ($started) {
                    $this->connection->commit();
                }

                return $result;
            } catch (Throwable $e) {
                if ($started && $this->connection->inTransaction()) {
                    $this->connection->rollBack();
                }

                throw $e;
            }
        }

        return DB::transaction($callback);
    }

    private function tableExists(string $table): bool
    {
        $driver = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->pdo()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
            $stmt->execute(['table' => $table]);

            return (bool) $stmt->fetchColumn();
        }

        $stmt = $this->pdo()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1');
        $stmt->execute(['table' => $table]);

        return (bool) $stmt->fetchColumn();
    }

    private function pdo(): PDO
    {
        return $this->connection ?? DB::pdo();
    }
}
