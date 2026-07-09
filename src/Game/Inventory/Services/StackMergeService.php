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
            }

            return [
                'source_item_public_id' => $request->sourceItemPublicId,
                'target_item_public_id' => $request->targetItemPublicId,
                'merged_quantity' => $request->quantity,
                'source_quantity' => max(0, $remainingSourceQuantity),
                'target_quantity' => $newTargetQuantity,
                'target_quality_value' => $newTargetQuality,
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
