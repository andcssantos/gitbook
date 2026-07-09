<?php

namespace App\Game\Inventory\Services;

use App\Game\Containers\Services\ContainerAcceptanceService;
use App\Game\Inventory\InventoryException;

class InventoryPlacementValidator
{
    public function __construct(private ?ContainerAcceptanceService $acceptance = null)
    {
        $this->acceptance ??= new ContainerAcceptanceService();
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
        int $expectedPlacementVersion
    ): array {
        if ($rotated) {
            throw new InventoryException('INVENTORY_ROTATION_DISABLED', 'Inventory rotation is disabled for MVP.');
        }

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

        $rejectionCode = $this->acceptance->rejectionCode($targetContainer, $item);
        if ($rejectionCode !== null) {
            $message = $rejectionCode === 'INVENTORY_CONTAINER_ITEM_BLOCKED'
                ? 'Container items cannot be placed inside this container.'
                : 'This container does not accept the selected item.';
            throw new InventoryException($rejectionCode, $message);
        }

        $width = (int) $item['definition_grid_w'];
        $height = (int) $item['definition_grid_h'];

        if (!$this->insideBounds($targetX, $targetY, $width, $height, $targetContainer)) {
            throw new InventoryException('INVENTORY_OUT_OF_BOUNDS', 'Inventory placement is out of bounds.');
        }

        foreach ($targetPlacements as $placement) {
            if ((int) $placement['item_instance_id'] === (int) $item['id']) {
                continue;
            }

            if ($this->overlaps(
                $targetX,
                $targetY,
                $width,
                $height,
                (int) $placement['grid_x'],
                (int) $placement['grid_y'],
                (int) $placement['grid_w'],
                (int) $placement['grid_h']
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
        bool $rotated = false
    ): array {
        if ($rotated) {
            throw new InventoryException('INVENTORY_ROTATION_DISABLED', 'Inventory rotation is disabled for MVP.');
        }

        $rejectionCode = $this->acceptance->rejectionCode($targetContainer, $item);
        if ($rejectionCode !== null) {
            $message = $rejectionCode === 'INVENTORY_CONTAINER_ITEM_BLOCKED'
                ? 'Container items cannot be placed inside this container.'
                : 'This container does not accept the selected item.';
            throw new InventoryException($rejectionCode, $message);
        }

        $width = (int) $item['definition_grid_w'];
        $height = (int) $item['definition_grid_h'];

        if (!$this->insideBounds($targetX, $targetY, $width, $height, $targetContainer)) {
            throw new InventoryException('INVENTORY_OUT_OF_BOUNDS', 'Inventory placement is out of bounds.');
        }

        foreach ($targetPlacements as $placement) {
            if ($this->overlaps(
                $targetX,
                $targetY,
                $width,
                $height,
                (int) $placement['grid_x'],
                (int) $placement['grid_y'],
                (int) $placement['grid_w'],
                (int) $placement['grid_h']
            )) {
                throw new InventoryException('INVENTORY_OVERLAP', 'Inventory placement overlaps another item.');
            }
        }

        return [
            'grid_w' => $width,
            'grid_h' => $height,
        ];
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
