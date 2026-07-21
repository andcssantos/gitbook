<?php

namespace App\Game\Socketing\Services;

use App\Game\Containers\Repositories\ContainerRepository;
use App\Game\Inventory\InventoryException;
use App\Game\Inventory\Services\InventoryStateService;
use App\Game\Items\Repositories\ItemInstancePropertyRepository;
use App\Game\Items\Repositories\ItemInstanceRepository;
use App\Game\Items\Services\ItemSafetyService;
use App\Game\Socketing\DTO\ApplyGemSocketRequest;
use App\Game\Socketing\Repositories\ItemInstanceSocketRepository;
use App\Support\DB;
use PDO;
use Throwable;

class GemSocketService
{
    public function __construct(
        private ?PDO $pdo = null,
        private ?ItemInstanceRepository $items = null,
        private ?ContainerRepository $containers = null,
        private ?GemSocketCompatibilityService $compatibility = null,
        private ?GemSocketApplyService $apply = null,
        private ?GemSocketConsumptionService $consumption = null
    ) {
        $this->pdo = $pdo;
        $this->items = $items ?? new ItemInstanceRepository($this->pdo);
        $this->containers = $containers ?? new ContainerRepository($this->pdo);
        $properties = new ItemInstancePropertyRepository($this->pdo);
        $sockets = new ItemInstanceSocketRepository($this->pdo);
        $this->compatibility = $compatibility ?? new GemSocketCompatibilityService($sockets, $properties);
        $this->apply = $apply ?? new GemSocketApplyService($sockets, $this->compatibility, $properties);
        $this->consumption = $consumption ?? new GemSocketConsumptionService($this->containers, $this->items);
    }

    public function preview(int $playerId, string $gemPublicId, string $targetPublicId): array
    {
        $gem = $this->loadItem($gemPublicId, $playerId);
        $target = $this->loadItem($targetPublicId, $playerId);

        return $this->compatibility->preview($gem, $target);
    }

    public function apply(ApplyGemSocketRequest $request): array
    {
        if (!$request->confirmed) {
            throw new InventoryException('SOCKET_CONFIRMATION_REQUIRED', 'Socketing requires confirmation.', 422);
        }

        $result = $this->transaction(function () use ($request): array {
            $gem = $this->loadItem($request->gemItemPublicId, $request->playerId, true);
            $target = $this->loadItem($request->targetItemPublicId, $request->playerId, true);

            $safety = new ItemSafetyService($this->pdo);
            $safety->assertNotLocked($request->playerId, (int) $gem['id'], 'SOCKET_CONSUME_GEM');
            $safety->assertNotLocked($request->playerId, (int) $target['id'], 'SOCKET_TARGET');

            $this->assertPlacementVersion($gem, $request->expectedGemPlacementVersion);
            $this->assertPlacementVersion($target, $request->expectedTargetPlacementVersion);

            $this->compatibility->assertCanApply($gem, $target);
            $preview = $this->compatibility->preview($gem, $target);
            $result = $this->apply->apply($gem, $target);

            if (($result['success'] ?? false) !== true) {
                throw new InventoryException(
                    (string) ($result['reason_code'] ?? 'SOCKET_FAILED'),
                    (string) ($result['reason_message'] ?? 'Gem could not be socketed.'),
                    422
                );
            }

            $this->consumption->consume($gem);

            return array_merge($result, [
                'preview' => $preview,
            ]);
        });

        InventoryStateService::forgetCombatSnapshot($request->playerId);

        return $result;
    }

    private function loadItem(string $publicId, int $playerId, bool $lock = false): array
    {
        $item = $this->items->findByPublicIdAndOwner($publicId, $playerId, $lock);
        if ($item !== null) {
            return $item;
        }

        if ($this->items->findByPublicId($publicId) !== null) {
            throw new InventoryException('SOCKET_FORBIDDEN', 'Item does not belong to the authenticated player.', 403);
        }

        throw new InventoryException('INVENTORY_ITEM_NOT_FOUND', 'Inventory item was not found.', 404);
    }

    private function assertPlacementVersion(array $item, int $expectedVersion): void
    {
        if ($expectedVersion < 1) {
            throw new InventoryException('SOCKET_INVALID_REQUEST', 'Expected placement version is required.', 422);
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
