<?php

namespace App\Game\Inventory\Services;

use App\Game\Inventory\InventoryException;

class GridFreeSpaceFinder
{
    public function __construct(private ?InventoryPlacementValidator $validator = null)
    {
        $this->validator ??= new InventoryPlacementValidator();
    }

    public function findFirst(array $item, array $container, array $placements, bool $rotated = false): ?array
    {
        $width = (int) $item['definition_grid_w'];
        $height = (int) $item['definition_grid_h'];
        if ($rotated) {
            [$width, $height] = [$height, $width];
        }

        $maxY = (int) $container['grid_rows'] - $height;
        $maxX = (int) $container['grid_columns'] - $width;

        if ($maxY < 0 || $maxX < 0) {
            return null;
        }

        for ($y = 0; $y <= $maxY; $y++) {
            for ($x = 0; $x <= $maxX; $x++) {
                if (!$this->canPlace($item, $container, $placements, $x, $y, $rotated)) {
                    continue;
                }

                return [
                    'grid_x' => $x,
                    'grid_y' => $y,
                    'grid_w' => $width,
                    'grid_h' => $height,
                ];
            }
        }

        return null;
    }

    private function canPlace(array $item, array $container, array $placements, int $x, int $y, bool $rotated = false): bool
    {
        try {
            $this->validator->validateNewPlacement($item, $container, $placements, $x, $y, $rotated);

            return true;
        } catch (InventoryException) {
            return false;
        }
    }
}
