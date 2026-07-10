<?php

namespace App\Game\Inventory\Services;

use App\Game\Containers\Repositories\ContainerRepository;
use App\Game\Containers\Services\ContainerAcceptanceService;
use App\Game\Containers\Services\ContainerNestingService;
use App\Game\Inventory\DTO\MoveItemRequest;
use App\Game\Inventory\InventoryException;
use App\Game\Inventory\Validators\MoveItemValidator;
use App\Game\Items\Repositories\ItemInstanceRepository;
use App\Security\AuditLogger;
use App\Support\DB;
use PDO;
use Throwable;

class InventoryMoveService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function move(MoveItemRequest $request): array
    {
        (new MoveItemValidator())->validate($request);

        return $this->transaction(function () use ($request): array {
            $containers = new ContainerRepository($this->pdo());
            $items = new ItemInstanceRepository($this->pdo());

            $item = $this->loadOwnedItem($items, $request->itemPublicId, $request->playerId);
            $sourceContainer = $this->loadOwnedContainer($containers, $request->sourceContainerPublicId, $request->playerId, 'INVENTORY_SOURCE_CONTAINER_NOT_FOUND');
            $targetContainer = $this->loadOwnedContainer($containers, $request->targetContainerPublicId, $request->playerId, 'INVENTORY_TARGET_CONTAINER_NOT_FOUND');
            $this->validateContainerFlow($sourceContainer, $targetContainer);

            $currentPlacement = $containers->findPlacement((int) $item['id'], (int) $sourceContainer['id'], true);
            if ($currentPlacement === null) {
                throw new InventoryException('INVENTORY_ITEM_NOT_IN_SOURCE_CONTAINER', 'Item is not in the provided source container.');
            }

            $targetPlacements = $containers->listPlacements((int) $targetContainer['id'], true);
            $size = (new InventoryPlacementValidator(
                new ContainerAcceptanceService(null, $this->pdo()),
                new ContainerNestingService($this->pdo())
            ))->validateMove(
                $item,
                $sourceContainer,
                $targetContainer,
                $currentPlacement,
                $targetPlacements,
                $request->gridX,
                $request->gridY,
                $request->rotated,
                $request->expectedPlacementVersion
            );

            $containers->updatePlacement((int) $currentPlacement['id'], [
                'container_instance_id' => (int) $targetContainer['id'],
                'grid_x' => $request->gridX,
                'grid_y' => $request->gridY,
                'grid_w' => $size['grid_w'],
                'grid_h' => $size['grid_h'],
                'rotated' => $request->rotated ? 1 : 0,
            ]);

            $updated = $containers->findPlacementById((int) $currentPlacement['id']);
            if ($updated === null) {
                throw new InventoryException('INVENTORY_PLACEMENT_NOT_FOUND', 'Updated inventory placement was not found.', 500);
            }

            $this->auditMove($request, $currentPlacement, $updated);

            return [
                'item_public_id' => $request->itemPublicId,
                'source_container_public_id' => $request->sourceContainerPublicId,
                'target_container_public_id' => $request->targetContainerPublicId,
                'grid_x' => (int) $updated['grid_x'],
                'grid_y' => (int) $updated['grid_y'],
                'grid_w' => (int) $updated['grid_w'],
                'grid_h' => (int) $updated['grid_h'],
                'rotated' => (bool) $updated['rotated'],
                'placement_version' => (int) $updated['placement_version'],
            ];
        });
    }

    private function loadOwnedItem(ItemInstanceRepository $items, string $publicId, int $playerId): array
    {
        $item = $items->findByPublicIdAndOwner($publicId, $playerId, true);
        if ($item !== null) {
            return $item;
        }

        if ($items->findByPublicId($publicId) !== null) {
            throw new InventoryException('INVENTORY_FORBIDDEN', 'Inventory item does not belong to the authenticated player.', 403);
        }

        throw new InventoryException('INVENTORY_ITEM_NOT_FOUND', 'Inventory item was not found.', 404);
    }

    private function auditMove(MoveItemRequest $request, array $currentPlacement, array $updated): void
    {
        if ($this->pdo instanceof PDO) {
            return;
        }

        (new AuditLogger())->log('INVENTORY_ITEM_MOVED', [
            'player_id' => $request->playerId,
            'item_public_id' => $request->itemPublicId,
            'source_container_public_id' => $request->sourceContainerPublicId,
            'target_container_public_id' => $request->targetContainerPublicId,
            'old_grid_x' => (int) $currentPlacement['grid_x'],
            'old_grid_y' => (int) $currentPlacement['grid_y'],
            'new_grid_x' => $request->gridX,
            'new_grid_y' => $request->gridY,
            'placement_version' => (int) $updated['placement_version'],
        ]);
    }

    private function validateContainerFlow(array $sourceContainer, array $targetContainer): void
    {
        if ((int) ($sourceContainer['id'] ?? 0) === (int) ($targetContainer['id'] ?? 0)) {
            return;
        }

        $sourceType = (string) ($sourceContainer['container_type'] ?? '');
        $targetType = (string) ($targetContainer['container_type'] ?? '');

        if ($targetType === 'MARKET_DELIVERY' && $sourceType === 'MAIN_INVENTORY') {
            return;
        }

        if ($targetType === 'MARKET_DELIVERY') {
            throw new InventoryException(
                'INVENTORY_MARKET_DELIVERY_SOURCE_RESTRICTED',
                'Market delivery only accepts deposits from the main inventory.',
                422
            );
        }

        if ($sourceType === 'MARKET_DELIVERY' && $targetType === 'MAIN_INVENTORY') {
            return;
        }
    }

    private function loadOwnedContainer(ContainerRepository $containers, string $publicId, int $playerId, string $notFoundCode): array
    {
        $container = $containers->findInstanceByPublicIdForPlayer($publicId, $playerId, true);
        if ($container !== null) {
            return $container;
        }

        if ($containers->findInstanceByPublicId($publicId) !== null) {
            throw new InventoryException('INVENTORY_FORBIDDEN', 'Inventory container does not belong to the authenticated player.', 403);
        }

        throw new InventoryException($notFoundCode, 'Inventory container was not found.', 404);
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

    private function pdo(): ?PDO
    {
        return $this->pdo;
    }
}
