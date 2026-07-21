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
    private ?ExpeditionLootPlacementPreferenceService $expeditionLootPreference = null;

    public function __construct(
        private ?PDO $pdo = null,
        ?ContainerPriorityService $priority = null,
        ?GridFreeSpaceFinder $freeSpaceFinder = null,
        ?ExpeditionLootPlacementPreferenceService $expeditionLootPreference = null
    ) {
        $this->priorityService = $priority;
        $this->spaceFinder = $freeSpaceFinder;
        $this->expeditionLootPreference = $expeditionLootPreference;
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
                'current_durability' => $this->durabilityFromDefinition($definition),
                'max_durability' => $this->durabilityFromDefinition($definition),
            ]);

            $this->applyDefinitionBaseProperties($itemId, $definition);

            $item = $items->findByPublicIdAndOwner((string) $this->publicIdForItem($itemId), $request->playerId, true);
            if ($item === null) {
                throw new InventoryException('INVENTORY_ITEM_NOT_FOUND', 'Granted item was not found after creation.', 500);
            }

            $linkedContainer = $this->linkService()->ensureForItem($request->playerId, $item);

            $preferCarry = $this->lootPreference()->shouldPreferCarry($request->playerId, $request->preferExpeditionCarry);
            $result = $this->autoPlaceExistingItem($request->playerId, $item, $preferCarry);
            if ($linkedContainer !== null) {
                $result['linked_container_public_id'] = $linkedContainer['public_id'];
                $result['linked_container_definition_code'] = $linkedContainer['definition_code'];
            }

            return $result;
        });
    }

    /**
     * Concede item e posiciona em coordenadas exatas (sem auto-merge).
     * Usado pela triagem de loot da campanha, onde o jogador decide layout/merge.
     *
     * @return array<string, mixed>
     */
    public function grantAtExact(
        GrantItemRequest $request,
        string $containerDefinitionCode,
        int $gridX,
        int $gridY,
        int $gridW,
        int $gridH
    ): array {
        if ($request->itemDefinitionCode === '') {
            throw new InventoryException('INVENTORY_ITEM_DEFINITION_INVALID', 'Item definition code is required.');
        }
        if ($request->quantity < 1) {
            throw new InventoryException('INVENTORY_QUANTITY_INVALID', 'Grant quantity must be at least one.');
        }

        return $this->transaction(function () use ($request, $containerDefinitionCode, $gridX, $gridY, $gridW, $gridH): array {
            $definitions = new ItemDefinitionRepository($this->pdo());
            $definition = $definitions->findActiveByCode($request->itemDefinitionCode);
            if ($definition === null) {
                throw new InventoryException('INVENTORY_ITEM_DEFINITION_NOT_FOUND', 'Item definition was not found.', 404);
            }

            $containers = new ContainerRepository($this->pdo());
            $container = $containers->findInstanceForPlayer($request->playerId, $containerDefinitionCode);
            if ($container === null) {
                throw new InventoryException('INVENTORY_CONTAINER_NOT_FOUND', 'Target container was not found.', 404);
            }

            $w = max(1, $gridW);
            $h = max(1, $gridH);
            $cols = max(1, (int) ($container['grid_columns'] ?? 1));
            $rows = max(1, (int) ($container['grid_rows'] ?? 1));
            if ($gridX < 0 || $gridY < 0 || ($gridX + $w) > $cols || ($gridY + $h) > $rows) {
                throw new InventoryException('INVENTORY_PLACEMENT_OUT_OF_BOUNDS', 'Placement is outside the container grid.', 422);
            }

            if ($this->rectOverlapsExisting($containers, (int) $container['id'], $gridX, $gridY, $w, $h)) {
                throw new InventoryException('INVENTORY_PLACEMENT_OCCUPIED', 'Target cells are already occupied.', 422);
            }

            $items = new ItemInstanceRepository($this->pdo());
            $itemId = $items->create([
                'item_definition_id' => (int) $definition['id'],
                'owner_player_id' => $request->playerId,
                'quantity' => $request->quantity,
                'quality_value' => $request->qualityValue,
                'quality_bucket' => $request->qualityBucket,
                'material_origin_id' => null,
                'item_name' => (string) $definition['name'],
                'current_durability' => $this->durabilityFromDefinition($definition),
                'max_durability' => $this->durabilityFromDefinition($definition),
            ]);
            $this->applyDefinitionBaseProperties($itemId, $definition);

            $item = $items->findByPublicIdAndOwner((string) $this->publicIdForItem($itemId), $request->playerId, true);
            if ($item === null) {
                throw new InventoryException('INVENTORY_ITEM_NOT_FOUND', 'Granted item was not found after creation.', 500);
            }

            $this->linkService()->ensureForItem($request->playerId, $item);
            $placementId = $containers->placeItem([
                'container_instance_id' => (int) $container['id'],
                'item_instance_id' => (int) $item['id'],
                'grid_x' => $gridX,
                'grid_y' => $gridY,
                'grid_w' => $w,
                'grid_h' => $h,
            ]);
            $stored = $containers->findPlacementById($placementId);

            return [
                'action' => 'placed_exact',
                'item_public_id' => (string) $item['public_id'],
                'quantity' => (int) $item['quantity'],
                'container_public_id' => (string) $container['public_id'],
                'container_definition_code' => (string) ($container['definition_code'] ?? $containerDefinitionCode),
                'grid_x' => (int) ($stored['grid_x'] ?? $gridX),
                'grid_y' => (int) ($stored['grid_y'] ?? $gridY),
                'grid_w' => (int) ($stored['grid_w'] ?? $w),
                'grid_h' => (int) ($stored['grid_h'] ?? $h),
            ];
        });
    }

    private function rectOverlapsExisting(
        ContainerRepository $containers,
        int $containerId,
        int $x,
        int $y,
        int $w,
        int $h
    ): bool {
        foreach ($containers->listPlacements($containerId, true) as $placement) {
            $ox = (int) ($placement['grid_x'] ?? 0);
            $oy = (int) ($placement['grid_y'] ?? 0);
            $ow = max(1, (int) ($placement['grid_w'] ?? 1));
            $oh = max(1, (int) ($placement['grid_h'] ?? 1));
            if (!($x + $w <= $ox || $ox + $ow <= $x || $y + $h <= $oy || $oy + $oh <= $y)) {
                return true;
            }
        }

        return false;
    }

    public function autoPlaceExistingItem(int $playerId, array $item, ?bool $preferExpeditionCarry = null): array
    {
        $preferCarry = $this->lootPreference()->shouldPreferCarry($playerId, $preferExpeditionCarry);
        $containers = new ContainerRepository($this->pdo());
        $containers->deletePlacementByItemId((int) $item['id']);

        $items = new ItemInstanceRepository($this->pdo());
        $this->linkService()->ensureForItem($playerId, $item);
        $compatibility = new StackCompatibilityService();
        $mergeResults = [];
        $remainingQuantity = (int) $item['quantity'];

        if ((int) ($item['stackable'] ?? 0) === 1) {
            foreach ($this->compatibleMergeTargets($playerId, $item, $compatibility, $preferCarry) as $target) {
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

                try {
                    $mergeResult = (new StackMergeService($this->pdo()))->merge(new MergeStackRequest(
                        $playerId,
                        (string) $item['public_id'],
                        (string) $target['public_id'],
                        $mergeQuantity
                    ));
                } catch (InventoryException $e) {
                    // Prefer carry: se merge na bag estiver travado, tenta slot livre na carry.
                    if ($preferCarry && $e->errorCode() === 'INVENTORY_EXPEDITION_CARRY_DEPOSIT_LOCKED') {
                        continue;
                    }
                    throw $e;
                }

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

        $placement = $this->findPlacement($playerId, $item, $containers, $preferCarry);
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

    private function compatibleMergeTargets(int $playerId, array $item, StackCompatibilityService $compatibility, bool $preferExpeditionCarry = false): array
    {
        $items = new ItemInstanceRepository($this->pdo());
        $targets = [];

        foreach ($items->listPlacedForPlayer($playerId, true) as $candidate) {
            if ((int) $candidate['id'] === (int) $item['id']) {
                continue;
            }

            if ($preferExpeditionCarry && !$this->isExpeditionCarryCandidate($candidate)) {
                continue;
            }

            if (!$compatibility->canMerge($item, $candidate, 1)) {
                continue;
            }

            $targets[] = $candidate;
        }

        usort($targets, function (array $left, array $right) use ($preferExpeditionCarry): int {
            return $this->priority($preferExpeditionCarry)->compareForMerge(
                [
                    'container_type' => (string) $left['container_type'],
                    'definition_code' => (string) ($left['container_definition_code'] ?? ''),
                    'sort_order' => (int) $left['container_sort_order'],
                    'id' => (int) $left['container_instance_id'],
                ],
                [
                    'container_type' => (string) $right['container_type'],
                    'definition_code' => (string) ($right['container_definition_code'] ?? ''),
                    'sort_order' => (int) $right['container_sort_order'],
                    'id' => (int) $right['container_instance_id'],
                ]
            );
        });

        return $targets;
    }

    private function findPlacement(int $playerId, array $item, ContainerRepository $containers, bool $preferExpeditionCarry = false): ?array
    {
        $containerList = $containers->listActiveInstancesForPlayer($playerId, true);
        usort($containerList, fn (array $left, array $right): int => $this->priority($preferExpeditionCarry)->compareForPlacement($left, $right, $item));

        foreach ($containerList as $container) {
            if ($preferExpeditionCarry && !$this->isExpeditionCarryCandidate($container)) {
                continue;
            }

            if (!$this->priority($preferExpeditionCarry)->isEligibleForAutoPlacement($container, $item)) {
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

    /** @param array<string, mixed> $containerOrCandidate */
    private function isExpeditionCarryCandidate(array $containerOrCandidate): bool
    {
        return strtoupper((string) ($containerOrCandidate['container_type'] ?? '')) === 'EXPEDITION_CARRY'
            || strtolower((string) ($containerOrCandidate['definition_code'] ?? $containerOrCandidate['container_definition_code'] ?? '')) === 'expedition_carry';
    }

    private function linkService(): PhysicalContainerLinkService
    {
        return new PhysicalContainerLinkService($this->pdo());
    }

    private function priority(bool $preferExpeditionCarry = false): ContainerPriorityService
    {
        if ($preferExpeditionCarry) {
            return $this->basePriorityService()->withPreferExpeditionCarry(true);
        }

        return $this->priorityService ??= $this->basePriorityService();
    }

    private function basePriorityService(): ContainerPriorityService
    {
        return new ContainerPriorityService(
            new ContainerAcceptanceService(null, $this->pdo()),
            new ContainerAcceptanceRuleRepository($this->pdo())
        );
    }

    private function lootPreference(): ExpeditionLootPlacementPreferenceService
    {
        return $this->expeditionLootPreference ??= new ExpeditionLootPlacementPreferenceService($this->pdo());
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

    private function durabilityFromDefinition(array $definition): ?int
    {
        $config = $this->decodeBaseConfig($definition);
        $durability = $config['durability'] ?? null;

        return is_numeric($durability) ? (int) $durability : null;
    }

    private function applyDefinitionBaseProperties(int $itemInstanceId, array $definition): void
    {
        $config = $this->decodeBaseConfig($definition);
        $properties = $config['base_properties'] ?? [];
        if (!is_array($properties) || $properties === []) {
            return;
        }

        foreach ($properties as $property) {
            if (!is_array($property)) {
                continue;
            }

            $code = (string) ($property['code'] ?? '');
            if ($code === '' || !isset($property['value'])) {
                continue;
            }

            $propertyId = $this->propertyDefinitionId($code);
            if ($propertyId === null) {
                continue;
            }

            $value = (int) $property['value'];
            $stmt = $this->pdo()->prepare('INSERT INTO item_instance_properties (
                item_instance_id, property_definition_id, numeric_value, integer_value, text_value, source
            ) VALUES (
                :item_instance_id, :property_definition_id, NULL, :integer_value, NULL, :source
            )');
            $stmt->execute([
                'item_instance_id' => $itemInstanceId,
                'property_definition_id' => $propertyId,
                'integer_value' => $value,
                'source' => 'base',
            ]);
        }
    }

    private function propertyDefinitionId(string $code): ?int
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM item_property_definitions WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => $code]);

        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    /** @return array<string, mixed> */
    private function decodeBaseConfig(array $definition): array
    {
        $raw = $definition['base_config'] ?? null;
        if (is_array($raw)) {
            return $raw;
        }

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
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
