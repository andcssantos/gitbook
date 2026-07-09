<?php

namespace App\Game\Inventory\Validators;

use App\Game\Inventory\InventoryException;

class StackRequestValidator
{
    public function validatePublicId(string $value, string $field): void
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{2,63}$/', $value)) {
            throw new InventoryException('INVENTORY_INVALID_REQUEST', "Invalid {$field}.", 422, ['field' => $field]);
        }
    }

    public function validateQuantity(int $quantity): void
    {
        if ($quantity < 1) {
            throw new InventoryException('STACK_QUANTITY_INVALID', 'Stack quantity must be greater than zero.');
        }
    }

    public function validateGrid(int $x, int $y, int $expectedPlacementVersion): void
    {
        if ($x < 0 || $y < 0) {
            throw new InventoryException('INVENTORY_INVALID_REQUEST', 'Grid coordinates must be greater than or equal to zero.');
        }

        if ($expectedPlacementVersion < 1) {
            throw new InventoryException('INVENTORY_INVALID_REQUEST', 'Expected placement version must be greater than or equal to one.');
        }
    }
}
