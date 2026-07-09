<?php

namespace App\Game\Inventory\Validators;

use App\Game\Inventory\DTO\MoveItemRequest;
use App\Game\Inventory\InventoryException;

class MoveItemValidator
{
    public function validate(MoveItemRequest $request): void
    {
        foreach ([
            'item_public_id' => $request->itemPublicId,
            'source_container_public_id' => $request->sourceContainerPublicId,
            'target_container_public_id' => $request->targetContainerPublicId,
        ] as $field => $value) {
            if (!$this->isPublicId($value)) {
                throw new InventoryException('INVENTORY_INVALID_REQUEST', "Invalid {$field}.", 422, ['field' => $field]);
            }
        }

        if ($request->gridX < 0 || $request->gridY < 0) {
            throw new InventoryException('INVENTORY_INVALID_REQUEST', 'Grid coordinates must be greater than or equal to zero.');
        }

        if ($request->expectedPlacementVersion < 1) {
            throw new InventoryException('INVENTORY_INVALID_REQUEST', 'Expected placement version must be greater than or equal to one.');
        }
    }

    private function isPublicId(string $value): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{2,63}$/', $value);
    }
}
