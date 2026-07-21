<?php

namespace App\Game\Inventory\Services;

use App\Game\Containers\Repositories\ContainerRepository;
use App\Game\Containers\Services\ContainerAcceptanceService;
use App\Game\Inventory\DTO\SplitStackRequest;
use App\Game\Inventory\InventoryException;
use App\Game\Inventory\Validators\StackRequestValidator;
use App\Game\Items\Repositories\ItemInstanceRepository;
use App\Game\Items\Repositories\ItemMaterialCompositionRepository;
use App\Support\DB;
use PDO;
use Throwable;

class StackSplitService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function split(SplitStackRequest $request): array
    {
        $validator = new StackRequestValidator();
        $validator->validatePublicId($request->sourceItemPublicId, 'source_item_public_id');
        $validator->validatePublicId($request->sourceContainerPublicId, 'source_container_public_id');
        $validator->validatePublicId($request->targetContainerPublicId, 'target_container_public_id');
        $validator->validateQuantity($request->quantity);
        $validator->validateGrid($request->gridX, $request->gridY, $request->expectedPlacementVersion);

        return $this->transaction(function () use ($request): array {
            $items = new ItemInstanceRepository($this->pdo());
            $containers = new ContainerRepository($this->pdo());
            $compositions = new ItemMaterialCompositionRepository($this->pdo());

            $source = $this->loadOwnedItem($items, $request->sourceItemPublicId, $request->playerId);
            (new StackCompatibilityService())->assertCanSplit($source, $request->quantity);

            $sourceContainer = $this->loadOwnedContainer($containers, $request->sourceContainerPublicId, $request->playerId, 'INVENTORY_SOURCE_CONTAINER_NOT_FOUND');
            $targetContainer = $this->loadOwnedContainer($containers, $request->targetContainerPublicId, $request->playerId, 'INVENTORY_TARGET_CONTAINER_NOT_FOUND');
            $expeditionCarry = new ExpeditionCarryAccessService($this->pdo());
            $expeditionCarry->assertMoveAllowed($request->playerId, $sourceContainer, $targetContainer);

            $sourcePlacement = $containers->findPlacement((int) $source['id'], (int) $sourceContainer['id'], true);
            if ($sourcePlacement === null) {
                throw new InventoryException('INVENTORY_ITEM_NOT_IN_SOURCE_CONTAINER', 'Item is not in the provided source container.');
            }

            if ((int) $sourcePlacement['placement_version'] !== $request->expectedPlacementVersion) {
                throw new InventoryException('INVENTORY_STALE_PLACEMENT', 'Inventory placement version is stale.', 409, [
                    'current_placement_version' => (int) $sourcePlacement['placement_version'],
                ]);
            }

            $targetPlacements = $containers->listPlacements((int) $targetContainer['id'], true);
            $size = (new InventoryPlacementValidator(new ContainerAcceptanceService(null, $this->pdo())))->validateNewPlacement(
                $source,
                $targetContainer,
                $targetPlacements,
                $request->gridX,
                $request->gridY,
                false,
                $expeditionCarry->bypassAcceptanceForMove($request->playerId, $sourceContainer, $targetContainer)
            );

            $splitItemId = $items->createSplitStack($source, $request->quantity);
            $compositions->copyForItem((int) $source['id'], $splitItemId);

            $placementId = $containers->placeItem([
                'container_instance_id' => (int) $targetContainer['id'],
                'item_instance_id' => $splitItemId,
                'grid_x' => $request->gridX,
                'grid_y' => $request->gridY,
                'grid_w' => $size['grid_w'],
                'grid_h' => $size['grid_h'],
            ]);

            $items->updateStack((int) $source['id'], (int) $source['quantity'] - $request->quantity, $source['quality_value'] !== null ? (float) $source['quality_value'] : null);
            $this->bumpPlacementVersion((int) $source['id']);
            $sourcePlacementAfter = $containers->findPlacement((int) $source['id'], (int) $sourceContainer['id']);
            $placement = $containers->findPlacementById($placementId);
            $publicId = (string) $this->pdo()->query('SELECT public_id FROM item_instances WHERE id = ' . (int) $splitItemId)->fetchColumn();

            return [
                'source_item_public_id' => $request->sourceItemPublicId,
                'split_item_public_id' => $publicId,
                'source_quantity' => (int) $source['quantity'] - $request->quantity,
                'split_quantity' => $request->quantity,
                'target_container_public_id' => $request->targetContainerPublicId,
                'grid_x' => (int) ($placement['grid_x'] ?? $request->gridX),
                'grid_y' => (int) ($placement['grid_y'] ?? $request->gridY),
                'grid_w' => (int) ($placement['grid_w'] ?? $size['grid_w']),
                'grid_h' => (int) ($placement['grid_h'] ?? $size['grid_h']),
                'placement_version' => (int) ($placement['placement_version'] ?? 1),
                'source_placement_version' => (int) ($sourcePlacementAfter['placement_version'] ?? 0),
            ];
        });
    }

    private function bumpPlacementVersion(int $itemInstanceId): void
    {
        $this->pdo()->prepare(
            'UPDATE container_items
             SET placement_version = placement_version + 1
             WHERE item_instance_id = :item_instance_id'
        )->execute(['item_instance_id' => $itemInstanceId]);
    }

    private function loadOwnedItem(ItemInstanceRepository $items, string $publicId, int $playerId): array
    {
        $item = $items->findByPublicIdAndOwner($publicId, $playerId, true);
        if ($item !== null) {
            return $item;
        }

        if ($items->findByPublicId($publicId) !== null) {
            throw new InventoryException('STACK_FORBIDDEN', 'Stack item does not belong to the authenticated player.', 403);
        }

        throw new InventoryException('INVENTORY_ITEM_NOT_FOUND', 'Inventory item was not found.', 404);
    }

    private function loadOwnedContainer(ContainerRepository $containers, string $publicId, int $playerId, string $notFoundCode): array
    {
        $container = $containers->findInstanceByPublicIdForPlayer($publicId, $playerId, true);
        if ($container !== null) {
            return $container;
        }

        if ($containers->findInstanceByPublicId($publicId) !== null) {
            throw new InventoryException('STACK_FORBIDDEN', 'Inventory container does not belong to the authenticated player.', 403);
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

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
