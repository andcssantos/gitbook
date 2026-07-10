<?php

namespace App\Game\Enhancement\Services;

use App\Game\Containers\Repositories\ContainerRepository;
use App\Game\Enhancement\DTO\ApplyJewelRequest;
use App\Game\Enhancement\Repositories\ItemUpgradeEventRepository;
use App\Game\Inventory\InventoryException;
use App\Game\Items\Repositories\ItemInstanceAffixRepository;
use App\Game\Items\Repositories\ItemInstancePropertyRepository;
use App\Game\Items\Repositories\ItemInstanceRepository;
use App\Game\Socketing\Repositories\ItemInstanceSocketRepository;
use App\Support\DB;
use PDO;
use Throwable;

class JewelEnhancementService
{
    public function __construct(
        private ?PDO $pdo = null,
        private ?ItemInstanceRepository $items = null,
        private ?ContainerRepository $containers = null,
        private ?JewelCompatibilityService $compatibility = null,
        private ?BlessJewelService $bless = null,
        private ?SoulJewelService $soul = null,
        private ?ChaosJewelService $chaos = null,
        private ?RerollJewelService $reroll = null,
        private ?JewelConsumptionService $consumption = null
    ) {
        $this->pdo = $pdo;
        $this->items = $items ?? new ItemInstanceRepository($this->pdo);
        $this->containers = $containers ?? new ContainerRepository($this->pdo);
        $properties = new ItemInstancePropertyRepository($this->pdo);
        $affixes = new ItemInstanceAffixRepository($this->pdo);
        $this->compatibility = $compatibility ?? new JewelCompatibilityService($properties, $affixes);
        $this->bless = $bless ?? new BlessJewelService(
            $properties,
            $this->compatibility,
            new UpgradeSuccessCalculator(),
            new ItemUpgradeEventRepository($this->pdo)
        );
        $this->soul = $soul ?? new SoulJewelService($affixes, $this->compatibility);
        $this->chaos = $chaos ?? new ChaosJewelService(
            $this->items,
            $properties,
            $affixes,
            new ItemInstanceSocketRepository($this->pdo),
            $this->compatibility
        );
        $this->reroll = $reroll ?? new RerollJewelService($affixes, $this->compatibility);
        $this->consumption = $consumption ?? new JewelConsumptionService($this->containers, $this->items);
    }

    public function preview(int $playerId, string $jewelPublicId, string $targetPublicId): array
    {
        $jewel = $this->loadItem($jewelPublicId, $playerId);
        $target = $this->loadItem($targetPublicId, $playerId);

        return $this->compatibility->preview($jewel, $target);
    }

    public function apply(ApplyJewelRequest $request): array
    {
        if (!$request->confirmed) {
            throw new InventoryException('ENHANCEMENT_CONFIRMATION_REQUIRED', 'Enhancement requires confirmation.', 422);
        }

        return $this->transaction(function () use ($request): array {
            $jewel = $this->loadItem($request->jewelItemPublicId, $request->playerId, true);
            $target = $this->loadItem($request->targetItemPublicId, $request->playerId, true);

            $this->assertPlacementVersion($jewel, $request->expectedJewelPlacementVersion);
            $this->assertPlacementVersion($target, $request->expectedTargetPlacementVersion);

            $type = $this->compatibility->assertCanApply($jewel, $target);
            $preview = $this->compatibility->preview($jewel, $target);

            $result = match ($type) {
                'bless' => $this->bless->apply($request->playerId, $jewel, $target),
                'soul' => $this->soul->apply($request->playerId, $jewel, $target),
                'chaos' => $this->chaos->apply($request->playerId, $jewel, $target),
                'reroll' => $this->reroll->apply($request->playerId, $jewel, $target),
                default => throw new InventoryException('ENHANCEMENT_JEWEL_UNSUPPORTED', 'Unsupported jewel type.', 422),
            };

            $this->consumption->consume($jewel);

            return array_merge($result, [
                'jewel_type' => $type,
                'jewel_public_id' => (string) $jewel['public_id'],
                'target_public_id' => (string) $target['public_id'],
                'preview' => $preview,
            ]);
        });
    }

    private function loadItem(string $publicId, int $playerId, bool $lock = false): array
    {
        $item = $this->items->findByPublicIdAndOwner($publicId, $playerId, $lock);
        if ($item !== null) {
            return $item;
        }

        if ($this->items->findByPublicId($publicId) !== null) {
            throw new InventoryException('ENHANCEMENT_FORBIDDEN', 'Item does not belong to the authenticated player.', 403);
        }

        throw new InventoryException('INVENTORY_ITEM_NOT_FOUND', 'Inventory item was not found.', 404);
    }

    private function assertPlacementVersion(array $item, int $expectedVersion): void
    {
        if ($expectedVersion < 1) {
            throw new InventoryException('ENHANCEMENT_INVALID_REQUEST', 'Expected placement version is required.', 422);
        }

        $placement = $this->containers->findPlacementByItemId((int) $item['id'], true);
        if ($placement === null) {
            throw new InventoryException('INVENTORY_PLACEMENT_NOT_FOUND', 'Item placement was not found.', 404);
        }

        if ((int) $placement['placement_version'] !== $expectedVersion) {
            throw new InventoryException('INVENTORY_STALE_PLACEMENT', 'Inventory placement version is stale.', 409, [
                'current_placement_version' => (int) $placement['placement_version'],
            ]);
        }
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
}
