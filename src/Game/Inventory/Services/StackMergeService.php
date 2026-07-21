<?php

namespace App\Game\Inventory\Services;

use App\Game\Inventory\DTO\MergeStackRequest;
use App\Game\Inventory\InventoryException;
use App\Game\Inventory\Validators\StackRequestValidator;
use App\Game\Items\Repositories\ItemInstanceRepository;
use App\Game\Items\Repositories\ItemMaterialCompositionRepository;
use App\Support\DB;
use PDO;
use Throwable;

class StackMergeService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function merge(MergeStackRequest $request): array
    {
        $validator = new StackRequestValidator();
        $validator->validatePublicId($request->sourceItemPublicId, 'source_item_public_id');
        $validator->validatePublicId($request->targetItemPublicId, 'target_item_public_id');
        $validator->validateQuantity($request->quantity);

        return $this->transaction(function () use ($request): array {
            $items = new ItemInstanceRepository($this->pdo());
            $compositions = new ItemMaterialCompositionRepository($this->pdo());

            $source = $this->loadOwnedItem($items, $request->sourceItemPublicId, $request->playerId);
            $target = $this->loadOwnedItem($items, $request->targetItemPublicId, $request->playerId);

            (new StackCompatibilityService())->assertCanMerge($source, $target, $request->quantity);
            $sourcePlacement = $this->placementForItem((int) $source['id'], $request->playerId);
            $targetPlacement = $this->placementForItem((int) $target['id'], $request->playerId);
            if ($targetPlacement !== null) {
                (new ExpeditionCarryAccessService($this->pdo()))->assertMergeAllowed($request->playerId, $sourcePlacement ?? [], $targetPlacement);
            }

            $this->assertExpectedPlacementVersion(
                $sourcePlacement,
                $request->expectedSourcePlacementVersion,
                'source'
            );
            $this->assertExpectedPlacementVersion(
                $targetPlacement,
                $request->expectedTargetPlacementVersion,
                'target'
            );

            $newTargetQuantity = (int) $target['quantity'] + $request->quantity;
            $remainingSourceQuantity = (int) $source['quantity'] - $request->quantity;
            $newTargetQuality = (new MaterialQualityCalculator())->weightedAverage(
                $target['quality_value'] !== null ? (float) $target['quality_value'] : null,
                (int) $target['quantity'],
                $source['quality_value'] !== null ? (float) $source['quality_value'] : null,
                $request->quantity
            );
            $newComposition = (new MaterialCompositionCalculator())->merge(
                $compositions->listForItem((int) $target['id']),
                (int) $target['quantity'],
                $compositions->listForItem((int) $source['id']),
                $request->quantity
            );

            $items->updateStack((int) $target['id'], $newTargetQuantity, $newTargetQuality);
            $compositions->replaceForItem((int) $target['id'], $newComposition);

            if ($remainingSourceQuantity <= 0) {
                $this->deleteSourceStack((int) $source['id']);
            } else {
                $items->updateStack((int) $source['id'], $remainingSourceQuantity, $source['quality_value'] !== null ? (float) $source['quality_value'] : null);
                $this->bumpPlacementVersion((int) $source['id']);
            }

            $this->bumpPlacementVersion((int) $target['id']);
            $targetPlacementAfter = $this->placementForItem((int) $target['id'], $request->playerId);
            $sourcePlacementAfter = $remainingSourceQuantity > 0
                ? $this->placementForItem((int) $source['id'], $request->playerId)
                : null;

            return [
                'source_item_public_id' => $request->sourceItemPublicId,
                'target_item_public_id' => $request->targetItemPublicId,
                'merged_quantity' => $request->quantity,
                'source_quantity' => max(0, $remainingSourceQuantity),
                'target_quantity' => $newTargetQuantity,
                'target_quality_value' => $newTargetQuality,
                'source_placement_version' => $sourcePlacementAfter !== null
                    ? (int) ($sourcePlacementAfter['placement_version'] ?? 0)
                    : null,
                'target_placement_version' => $targetPlacementAfter !== null
                    ? (int) ($targetPlacementAfter['placement_version'] ?? 0)
                    : null,
            ];
        });
    }

    private function deleteSourceStack(int $itemInstanceId): void
    {
        $pdo = $this->pdo();
        $pdo->prepare('DELETE FROM container_items WHERE item_instance_id = :item_instance_id')->execute(['item_instance_id' => $itemInstanceId]);
        $pdo->prepare('DELETE FROM item_instances WHERE id = :id')->execute(['id' => $itemInstanceId]);
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

    private function placementForItem(int $itemInstanceId, int $playerId): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT
                ci.container_instance_id,
                ci.placement_version,
                cinst.public_id AS container_public_id,
                cd.code AS container_definition_code,
                cd.container_type
            FROM container_items ci
            INNER JOIN container_instances cinst ON cinst.id = ci.container_instance_id
            INNER JOIN container_definitions cd ON cd.id = cinst.container_definition_id
            WHERE ci.item_instance_id = :item_instance_id
                AND cinst.owner_player_id = :player_id
                AND cinst.status = :status
            LIMIT 1' . ($this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
        $stmt->execute([
            'item_instance_id' => $itemInstanceId,
            'player_id' => $playerId,
            'status' => 'active',
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return $row;
    }

    private function assertExpectedPlacementVersion(?array $placement, ?int $expectedVersion, string $side): void
    {
        if ($expectedVersion === null) {
            return;
        }

        if ($placement === null) {
            throw new InventoryException(
                'INVENTORY_STALE_PLACEMENT',
                'Inventory placement version is stale.',
                409,
                [
                    'side' => $side,
                    'current_placement_version' => null,
                ]
            );
        }

        if ((int) ($placement['placement_version'] ?? 0) !== $expectedVersion) {
            throw new InventoryException(
                'INVENTORY_STALE_PLACEMENT',
                'Inventory placement version is stale.',
                409,
                [
                    'side' => $side,
                    'current_placement_version' => (int) ($placement['placement_version'] ?? 0),
                ]
            );
        }
    }

    private function bumpPlacementVersion(int $itemInstanceId): void
    {
        $this->pdo()->prepare(
            'UPDATE container_items
             SET placement_version = placement_version + 1
             WHERE item_instance_id = :item_instance_id'
        )->execute(['item_instance_id' => $itemInstanceId]);
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
