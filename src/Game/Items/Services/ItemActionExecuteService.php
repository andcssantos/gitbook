<?php

namespace App\Game\Items\Services;

use App\Game\Containers\Repositories\ContainerRepository;
use App\Game\Inventory\InventoryException;
use App\Game\Items\Repositories\ItemActionDefinitionRepository;
use App\Game\Items\Repositories\ItemInstanceRepository;
use App\Game\Items\Repositories\ItemMaterialCompositionRepository;
use App\Support\DB;
use PDO;
use Throwable;

class ItemActionExecuteService
{
    private const MVP_EXECUTABLE = ['DISCARD', 'INSPECT', 'OPEN'];

    public function __construct(
        private ?PDO $pdo = null,
        private ?ItemActionAvailabilityService $availability = null,
        private ?ItemActionDefinitionRepository $definitions = null
    ) {
        $this->availability ??= new ItemActionAvailabilityService($this->pdo);
        $this->definitions ??= new ItemActionDefinitionRepository($this->pdo);
    }

    public function execute(int $playerId, string $itemPublicId, string $actionCode, bool $confirmed = false): array
    {
        $actionCode = strtoupper(trim($actionCode));
        if (!in_array($actionCode, self::MVP_EXECUTABLE, true)) {
            throw new InventoryException('ITEM_ACTION_NOT_EXECUTABLE', 'This item action is not executable in MVP.', 422);
        }

        return $this->transaction(function () use ($playerId, $itemPublicId, $actionCode, $confirmed): array {
            $items = new ItemInstanceRepository($this->pdo());
            $item = $this->loadOwnedItem($items, $itemPublicId, $playerId);

            if (!$this->availability->isExecutable($actionCode, $item)) {
                throw new InventoryException('ITEM_ACTION_NOT_AVAILABLE', 'This action is not available for the selected item.', 422);
            }

            $definition = $this->definitions->findActiveByCode($actionCode);
            if ($definition === null) {
                throw new InventoryException('ITEM_ACTION_NOT_FOUND', 'Item action was not found.', 404);
            }

            if ((bool) $definition['requires_confirmation'] && !$confirmed) {
                throw new InventoryException('ITEM_ACTION_CONFIRMATION_REQUIRED', 'This action requires confirmation.', 422);
            }

            return match ($actionCode) {
                'DISCARD' => $this->discard($item),
                'INSPECT' => $this->inspect($item),
                'OPEN' => $this->open($item),
            };
        });
    }

    private function discard(array $item): array
    {
        if ((int) ($item['is_container'] ?? 0) === 1) {
            $containers = new ContainerRepository($this->pdo());
            $linked = $containers->findInstanceBySourceItemId((int) $item['id'], true);
            if ($linked !== null && $containers->countItems((int) $linked['id']) > 0) {
                throw new InventoryException('INVENTORY_CONTAINER_NOT_EMPTY', 'Container must be empty before discarding.', 422);
            }

            if ($linked !== null) {
                $this->pdo()->prepare('UPDATE container_instances SET status = :status WHERE id = :id')->execute([
                    'id' => (int) $linked['id'],
                    'status' => 'inactive',
                ]);
            }
        }

        $containers = new ContainerRepository($this->pdo());
        $containers->deletePlacementByItemId((int) $item['id']);

        $compositions = new ItemMaterialCompositionRepository($this->pdo());
        $compositions->deleteForItem((int) $item['id']);

        $items = new ItemInstanceRepository($this->pdo());
        $items->deleteById((int) $item['id']);

        return [
            'action' => 'DISCARD',
            'item_public_id' => (string) $item['public_id'],
            'discarded' => true,
        ];
    }

    private function inspect(array $item): array
    {
        return [
            'action' => 'INSPECT',
            'item_public_id' => (string) $item['public_id'],
            'item' => [
                'definition_code' => (string) $item['definition_code'],
                'quantity' => (int) $item['quantity'],
                'quality_bucket' => $item['quality_bucket'] !== null ? (string) $item['quality_bucket'] : null,
                'quality_value' => $item['quality_value'] !== null ? (float) $item['quality_value'] : null,
                'state' => (string) $item['state'],
                'bind_type' => (string) $item['bind_type'],
                'stackable' => (bool) $item['stackable'],
                'is_container' => (bool) $item['is_container'],
            ],
        ];
    }

    private function open(array $item): array
    {
        if ((int) ($item['is_container'] ?? 0) !== 1) {
            throw new InventoryException('ITEM_ACTION_NOT_AVAILABLE', 'Only container items can be opened.', 422);
        }

        $containers = new ContainerRepository($this->pdo());
        $linked = $containers->findInstanceBySourceItemId((int) $item['id']);
        if ($linked === null) {
            throw new InventoryException('ITEM_CONTAINER_NOT_LINKED', 'Container item has no linked container.', 404);
        }

        if ((int) $linked['owner_player_id'] !== (int) $item['owner_player_id']) {
            throw new InventoryException('ITEM_ACTION_FORBIDDEN', 'Linked container does not belong to the item owner.', 403);
        }

        return [
            'action' => 'OPEN',
            'item_public_id' => (string) $item['public_id'],
            'container_public_id' => (string) $linked['public_id'],
            'container_definition_code' => (string) ($linked['definition_code'] ?? ''),
            'container_name' => (string) $linked['name'],
        ];
    }

    private function loadOwnedItem(ItemInstanceRepository $items, string $publicId, int $playerId): array
    {
        $item = $items->findByPublicIdAndOwner($publicId, $playerId, true);
        if ($item !== null) {
            return $item;
        }

        if ($items->findByPublicId($publicId) !== null) {
            throw new InventoryException('ITEM_ACTION_FORBIDDEN', 'Item does not belong to the authenticated player.', 403);
        }

        throw new InventoryException('INVENTORY_ITEM_NOT_FOUND', 'Inventory item was not found.', 404);
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
                if ($started && $this->pdo->inTransaction()) {
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

        return DB::transaction(fn (): mixed => $callback());
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
