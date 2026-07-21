<?php

namespace App\Game\Inventory\Services;

use App\Game\Containers\Services\ContainerAcceptanceService;
use App\Game\Containers\Services\ContainerNestingService;
use App\Game\Inventory\InventoryException;

class InventoryPlacementValidator
{
    public function __construct(
        private ?ContainerAcceptanceService $acceptance = null,
        private ?ContainerNestingService $nesting = null
    ) {
        $this->acceptance ??= new ContainerAcceptanceService();
        $this->nesting ??= new ContainerNestingService();
    }

    public function validateMove(
        array $item,
        array $sourceContainer,
        array $targetContainer,
        array $currentPlacement,
        array $targetPlacements,
        int $targetX,
        int $targetY,
        bool $rotated,
        int $expectedPlacementVersion,
        bool $skipAcceptance = false
    ): array {
        if ((int) ($currentPlacement['locked'] ?? 0) === 1) {
            throw new InventoryException('INVENTORY_ITEM_LOCKED', 'Inventory item placement is locked.');
        }

        if ((int) $currentPlacement['container_instance_id'] !== (int) $sourceContainer['id']) {
            throw new InventoryException('INVENTORY_ITEM_NOT_IN_SOURCE_CONTAINER', 'Item is not in the provided source container.');
        }

        if ((int) $currentPlacement['placement_version'] !== $expectedPlacementVersion) {
            throw new InventoryException('INVENTORY_STALE_PLACEMENT', 'Inventory placement version is stale.', 409, [
                'current_placement_version' => (int) $currentPlacement['placement_version'],
            ]);
        }

        if (!$skipAcceptance) {
            $rejectionCode = $this->acceptance->rejectionCode($targetContainer, $item);
            if ($rejectionCode !== null) {
                $message = $rejectionCode === 'INVENTORY_CONTAINER_ITEM_BLOCKED'
                    ? 'Itens-container nao podem ser colocados dentro deste container.'
                    : 'Este container nao aceita o item selecionado.';
                throw new InventoryException($rejectionCode, $message);
            }
        }

        if (!$this->nesting->canPlaceContainerItem($targetContainer, $item)) {
            throw new InventoryException(
                'INVENTORY_CONTAINER_NESTING_LIMIT',
                'Este container atingiu o limite de aninhamento (max 2 niveis).'
            );
        }

        [$width, $height] = $this->dimensions($item, $rotated);

        if (!$this->insideBounds($targetX, $targetY, $width, $height, $targetContainer)) {
            throw new InventoryException('INVENTORY_OUT_OF_BOUNDS', 'Inventory placement is out of bounds.');
        }

        foreach ($targetPlacements as $placement) {
            if ((int) $placement['item_instance_id'] === (int) $item['id']) {
                continue;
            }

            [$otherW, $otherH] = $this->placementFootprint($placement);

            if ($this->overlaps(
                $targetX,
                $targetY,
                $width,
                $height,
                (int) $placement['grid_x'],
                (int) $placement['grid_y'],
                $otherW,
                $otherH
            )) {
                throw new InventoryException('INVENTORY_OVERLAP', 'Inventory placement overlaps another item.');
            }
        }

        return [
            'grid_w' => $width,
            'grid_h' => $height,
        ];
    }

    public function validateNewPlacement(
        array $item,
        array $targetContainer,
        array $targetPlacements,
        int $targetX,
        int $targetY,
        bool $rotated = false,
        bool $skipAcceptance = false
    ): array {
        if (!$skipAcceptance) {
            $rejectionCode = $this->acceptance->rejectionCode($targetContainer, $item);
            if ($rejectionCode !== null) {
                $message = $rejectionCode === 'INVENTORY_CONTAINER_ITEM_BLOCKED'
                    ? 'Itens-container nao podem ser colocados dentro deste container.'
                    : 'Este container nao aceita o item selecionado.';
                throw new InventoryException($rejectionCode, $message);
            }
        }

        if (!$this->nesting->canPlaceContainerItem($targetContainer, $item)) {
            throw new InventoryException(
                'INVENTORY_CONTAINER_NESTING_LIMIT',
                'Este container atingiu o limite de aninhamento (max 2 niveis).'
            );
        }

        [$width, $height] = $this->dimensions($item, $rotated);

        if (!$this->insideBounds($targetX, $targetY, $width, $height, $targetContainer)) {
            throw new InventoryException('INVENTORY_OUT_OF_BOUNDS', 'Inventory placement is out of bounds.');
        }

        foreach ($targetPlacements as $placement) {
            [$otherW, $otherH] = $this->placementFootprint($placement);

            if ($this->overlaps(
                $targetX,
                $targetY,
                $width,
                $height,
                (int) $placement['grid_x'],
                (int) $placement['grid_y'],
                $otherW,
                $otherH
            )) {
                throw new InventoryException('INVENTORY_OVERLAP', 'Inventory placement overlaps another item.');
            }
        }

        return [
            'grid_w' => $width,
            'grid_h' => $height,
        ];
    }

    /**
     * @param array<string, mixed> $placement
     * @return array{0:int,1:int}
     */
    private function placementFootprint(array $placement): array
    {
        if (isset($placement['definition_grid_w'], $placement['definition_grid_h'])) {
            return $this->dimensions([
                'definition_grid_w' => $placement['definition_grid_w'],
                'definition_grid_h' => $placement['definition_grid_h'],
            ], (bool) ($placement['rotated'] ?? false));
        }

        return [
            max(1, (int) ($placement['grid_w'] ?? 1)),
            max(1, (int) ($placement['grid_h'] ?? 1)),
        ];
    }

    private function dimensions(array $item, bool $rotated): array
    {
        $width = (int) ($item['definition_grid_w'] ?? $item['grid_w'] ?? 1);
        $height = (int) ($item['definition_grid_h'] ?? $item['grid_h'] ?? 1);

        if ($rotated) {
            return [$height, $width];
        }

        return [$width, $height];
    }

    private function insideBounds(int $x, int $y, int $w, int $h, array $container): bool
    {
        return $x >= 0
            && $y >= 0
            && ($x + $w) <= (int) $container['grid_columns']
            && ($y + $h) <= (int) $container['grid_rows'];
    }

    private function overlaps(int $aX, int $aY, int $aW, int $aH, int $bX, int $bY, int $bW, int $bH): bool
    {
        return $aX < ($bX + $bW)
            && ($aX + $aW) > $bX
            && $aY < ($bY + $bH)
            && ($aY + $aH) > $bY;
    }
}
