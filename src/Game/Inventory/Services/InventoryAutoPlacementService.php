<?php

namespace App\Game\Inventory\Services;

use App\Game\Containers\Repositories\ContainerAcceptanceRuleRepository;
use App\Game\Containers\Repositories\ContainerRepository;
use App\Game\Containers\Services\ContainerAcceptanceService;
use App\Game\Containers\Services\PhysicalContainerLinkService;
use App\Game\Inventory\DTO\GrantItemRequest;
use App\Game\Inventory\DTO\MergeStackRequest;
use App\Game\Inventory\InventoryException;
use App\Game\Items\Repositories\ItemDefinitionRepository;
use App\Game\Items\Repositories\ItemInstanceRepository;
use App\Support\DB;
use PDO;
use Throwable;

class InventoryAutoPlacementService
{
    private ?ContainerPriorityService $priorityService = null;
    private ?GridFreeSpaceFinder $spaceFinder = null;

    public function __construct(
        private ?PDO $pdo = null,
        ?ContainerPriorityService $priority = null,
        ?GridFreeSpaceFinder $freeSpaceFinder = null
    ) {
        $this->priorityService = $priority;
        $this->spaceFinder = $freeSpaceFinder;
    }

    public function grantAndPlace(GrantItemRequest $request): array
    {
        if ($request->itemDefinitionCode === '') {
            throw new InventoryException('INVENTORY_ITEM_DEFINITION_INVALID', 'Item definition code is required.');
        }

        if ($request->quantity < 1) {
            throw new InventoryException('INVENTORY_QUANTITY_INVALID', 'Grant quantity must be at least one.');
        }

        return $this->transaction(function () use ($request): array {
            $definitions = new ItemDefinitionRepository($this->pdo());
            $definition = $definitions->findActiveByCode($request->itemDefinitionCode);
            if ($definition === null) {
                throw new InventoryException('INVENTORY_ITEM_DEFINITION_NOT_FOUND', 'Item definition was not found.', 404);
            }

            $materialOriginId = null;
            if ($request->materialOriginCode !== null && $request->materialOriginCode !== '') {
                $materialOriginId = $this->materialOriginId($request->materialOriginCode);
                if ($materialOriginId === null) {
                    throw new InventoryException('INVENTORY_MATERIAL_ORIGIN_NOT_FOUND', 'Material origin was not found.', 404);
                }
            }

            $items = new ItemInstanceRepository($this->pdo());
            $itemId = $items->create([
                'item_definition_id' => (int) $definition['id'],
                'owner_player_id' => $request->playerId,
                'quantity' => $request->quantity,
                'quality_value' => $request->qualityValue,
                'quality_bucket' => $request->qualityBucket,
                'material_origin_id' => $materialOriginId,
                'item_name' => (string) $definition['name'],
            ]);

            $item = $items->findByPublicIdAndOwner((string) $this->publicIdForItem($itemId), $request->playerId, true);
            if ($item === null) {
                throw new InventoryException('INVENTORY_ITEM_NOT_FOUND', 'Granted item was not found after creation.', 500);
            }

            $linkedContainer = $this->linkService()->ensureForItem($request->playerId, $item);

            $result = $this->autoPlaceExistingItem($request->playerId, $item);
            if ($linkedContainer !== null) {
                $result['linked_container_public_id'] = $linkedContainer['public_id'];
                $result['linked_container_definition_code'] = $linkedContainer['definition_code'];
            }

            return $result;
        });
    }

    public function autoPlaceExistingItem(int $playerId, array $item): array
    {
        $containers = new ContainerRepository($this->pdo());
        $containers->deletePlacementByItemId((int) $item['id']);

        $items = new ItemInstanceRepository($this->pdo());
        $this->linkService()->ensureForItem($playerId, $item);
        $compatibility = new StackCompatibilityService();
        $mergeResults = [];
        $remainingQuantity = (int) $item['quantity'];

        if ((int) ($item['stackable'] ?? 0) === 1) {
            foreach ($this->compatibleMergeTargets($playerId, $item, $compatibility) as $target) {
                if ($remainingQuantity <= 0) {
                    break;
                }

                $mergeQuantity = min(
                    $remainingQuantity,
                    (int) $target['max_stack'] - (int) $target['quantity']
                );

                if ($mergeQuantity <= 0 || !$compatibility->canMerge($item, $target, $mergeQuantity)) {
                    continue;
                }

                $mergeResult = (new StackMergeService($this->pdo()))->merge(new MergeStackRequest(
                    $playerId,
                    (string) $item['public_id'],
                    (string) $target['public_id'],
                    $mergeQuantity
                ));

                $mergeResults[] = $mergeResult;
                $remainingQuantity = (int) $mergeResult['source_quantity'];

                if ($remainingQuantity <= 0) {
                    return [
                        'action' => 'merged',
                        'item_public_id' => (string) $item['public_id'],
                        'target_item_public_id' => (string) $target['public_id'],
                        'merged_quantity' => (int) $mergeResult['merged_quantity'],
                        'target_quantity' => (int) $mergeResult['target_quantity'],
                        'merges' => $mergeResults,
                    ];
                }

                $reloaded = $items->findByPublicIdAndOwner((string) $item['public_id'], $playerId, true);
                if ($reloaded === null) {
                    return [
                        'action' => 'merged',
                        'item_public_id' => (string) $item['public_id'],
                        'target_item_public_id' => (string) $target['public_id'],
                        'merged_quantity' => (int) $mergeResult['merged_quantity'],
                        'target_quantity' => (int) $mergeResult['target_quantity'],
                        'merges' => $mergeResults,
                    ];
                }

                $item = $reloaded;
                $remainingQuantity = (int) $item['quantity'];
            }
        }

        $placement = $this->findPlacement($playerId, $item, $containers);
        if ($placement === null) {
            throw new InventoryException('INVENTORY_FULL', 'Inventory has no free space for the item.');
        }

        $placementId = $containers->placeItem([
            'container_instance_id' => (int) $placement['container']['id'],
            'item_instance_id' => (int) $item['id'],
            'grid_x' => (int) $placement['grid_x'],
            'grid_y' => (int) $placement['grid_y'],
            'grid_w' => (int) $placement['grid_w'],
            'grid_h' => (int) $placement['grid_h'],
        ]);

        $storedPlacement = $containers->findPlacementById($placementId);

        return [
            'action' => $mergeResults === [] ? 'placed' : 'merged_and_placed',
            'item_public_id' => (string) $item['public_id'],
            'quantity' => $remainingQuantity,
            'container_public_id' => (string) $placement['container']['public_id'],
            'container_definition_code' => (string) $placement['container']['definition_code'],
            'grid_x' => (int) ($storedPlacement['grid_x'] ?? $placement['grid_x']),
            'grid_y' => (int) ($storedPlacement['grid_y'] ?? $placement['grid_y']),
            'grid_w' => (int) ($storedPlacement['grid_w'] ?? $placement['grid_w']),
            'grid_h' => (int) ($storedPlacement['grid_h'] ?? $placement['grid_h']),
            'placement_version' => (int) ($storedPlacement['placement_version'] ?? 1),
            'merges' => $mergeResults,
        ];
    }

    private function compatibleMergeTargets(int $playerId, array $item, StackCompatibilityService $compatibility): array
    {
        $items = new ItemInstanceRepository($this->pdo());
        $targets = [];

        foreach ($items->listPlacedForPlayer($playerId, true) as $candidate) {
            if ((int) $candidate['id'] === (int) $item['id']) {
                continue;
            }

            if (!$compatibility->canMerge($item, $candidate, 1)) {
                continue;
            }

            $targets[] = $candidate;
        }

        usort($targets, function (array $left, array $right): int {
            return $this->priority()->compareForMerge(
                [
                    'container_type' => (string) $left['container_type'],
                    'sort_order' => (int) $left['container_sort_order'],
                    'id' => (int) $left['container_instance_id'],
                ],
                [
                    'container_type' => (string) $right['container_type'],
                    'sort_order' => (int) $right['container_sort_order'],
                    'id' => (int) $right['container_instance_id'],
                ]
            );
        });

        return $targets;
    }

    private function findPlacement(int $playerId, array $item, ContainerRepository $containers): ?array
    {
        $containerList = $containers->listActiveInstancesForPlayer($playerId, true);
        usort($containerList, fn (array $left, array $right): int => $this->priority()->compareForPlacement($left, $right, $item));

        foreach ($containerList as $container) {
            if (!$this->priority()->isEligibleForAutoPlacement($container, $item)) {
                continue;
            }

            $placements = $containers->listPlacements((int) $container['id'], true);
            $slot = $this->spaceFinder()->findFirst($item, $container, $placements);
            if ($slot === null) {
                continue;
            }

            return [
                'container' => $container,
                'grid_x' => $slot['grid_x'],
                'grid_y' => $slot['grid_y'],
                'grid_w' => $slot['grid_w'],
                'grid_h' => $slot['grid_h'],
            ];
        }

        return null;
    }

    private function linkService(): PhysicalContainerLinkService
    {
        return new PhysicalContainerLinkService($this->pdo());
    }

    private function priority(): ContainerPriorityService
    {
        return $this->priorityService ??= new ContainerPriorityService(
            new ContainerAcceptanceService(null, $this->pdo()),
            new ContainerAcceptanceRuleRepository($this->pdo())
        );
    }

    private function spaceFinder(): GridFreeSpaceFinder
    {
        return $this->spaceFinder ??= new GridFreeSpaceFinder(
            new InventoryPlacementValidator(new ContainerAcceptanceService(null, $this->pdo()))
        );
    }

    private function materialOriginId(string $code): ?int
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM material_origins WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => $code]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    private function publicIdForItem(int $itemId): string
    {
        $stmt = $this->pdo()->prepare('SELECT public_id FROM item_instances WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $itemId]);

        return (string) $stmt->fetchColumn();
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
