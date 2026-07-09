<?php

namespace App\Game\Inventory\Services;

use App\Game\Inventory\InventoryException;

class StackCompatibilityService
{
    public function assertCanMerge(array $source, array $target, int $quantity): void
    {
        if ((int) $source['id'] === (int) $target['id']) {
            throw new InventoryException('STACK_NOT_COMPATIBLE', 'A stack cannot be merged into itself.');
        }

        if ((int) ($source['stackable'] ?? 0) !== 1 || (int) ($target['stackable'] ?? 0) !== 1) {
            throw new InventoryException('STACK_ITEM_NOT_STACKABLE', 'Item is not stackable.');
        }

        if ((int) $source['item_definition_id'] !== (int) $target['item_definition_id']) {
            throw new InventoryException('STACK_NOT_COMPATIBLE', 'Stacks use different item definitions.');
        }

        if ((string) ($source['quality_bucket'] ?? '') !== (string) ($target['quality_bucket'] ?? '')) {
            throw new InventoryException('STACK_NOT_COMPATIBLE', 'Stacks use different quality buckets.');
        }

        if (($source['material_origin_id'] ?? null) !== ($target['material_origin_id'] ?? null)) {
            throw new InventoryException('STACK_NOT_COMPATIBLE', 'Stacks use different material origins.');
        }

        if ((string) $source['bind_type'] !== (string) $target['bind_type'] || (string) $source['state'] !== (string) $target['state']) {
            throw new InventoryException('STACK_NOT_COMPATIBLE', 'Stacks use different binding or state.');
        }

        if ($quantity < 1 || $quantity > (int) $source['quantity']) {
            throw new InventoryException('STACK_QUANTITY_INVALID', 'Stack quantity is invalid.');
        }

        if (((int) $target['quantity'] + $quantity) > (int) $target['max_stack']) {
            throw new InventoryException('STACK_MAX_EXCEEDED', 'Stack max quantity would be exceeded.');
        }
    }

    public function assertCanSplit(array $source, int $quantity): void
    {
        if ((int) ($source['stackable'] ?? 0) !== 1) {
            throw new InventoryException('STACK_ITEM_NOT_STACKABLE', 'Item is not stackable.');
        }

        if ($quantity < 1 || $quantity >= (int) $source['quantity']) {
            throw new InventoryException('STACK_QUANTITY_INVALID', 'Split quantity must leave at least one item in the source stack.');
        }
    }
}
