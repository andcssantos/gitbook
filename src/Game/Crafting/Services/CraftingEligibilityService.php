<?php

namespace App\Game\Crafting\Services;

use App\Game\Inventory\InventoryException;

class CraftingEligibilityService
{
    public function assertItemEligible(array $item, bool $isEquipped = false): void
    {
        if ($isEquipped) {
            throw new InventoryException('CRAFT_ITEM_EQUIPPED', 'Desequipe o item antes de usa-lo na forja ou alquimia.', 422);
        }

        if ((bool) ($item['is_collectible'] ?? $item['definition']['is_collectible'] ?? false)) {
            throw new InventoryException('CRAFT_ITEM_COLLECTIBLE', 'Itens colecionaveis nao podem ser usados em crafting.', 422);
        }

        if ((bool) ($item['is_event_item'] ?? $item['definition']['is_event_item'] ?? false)) {
            throw new InventoryException('CRAFT_ITEM_EVENT', 'Itens de evento nao podem ser usados em crafting.', 422);
        }

        if ($this->isContainerWithContents($item)) {
            throw new InventoryException('CRAFT_CONTAINER_NOT_EMPTY', 'Esvazie o bau ou container antes de anexar.', 422);
        }

        if ((string) ($item['state'] ?? 'available') !== 'available') {
            throw new InventoryException('CRAFT_ITEM_UNAVAILABLE', 'Item indisponivel para crafting.', 422);
        }
    }

    public function isItemEligible(array $item, bool $isEquipped = false): bool
    {
        try {
            $this->assertItemEligible($item, $isEquipped);

            return true;
        } catch (InventoryException) {
            return false;
        }
    }

    public function rejectionReason(array $item, bool $isEquipped = false): ?string
    {
        try {
            $this->assertItemEligible($item, $isEquipped);

            return null;
        } catch (InventoryException $e) {
            return $e->getMessage();
        }
    }

    private function isContainerWithContents(array $item): bool
    {
        if (!((int) ($item['is_container'] ?? $item['definition']['is_container'] ?? 0) === 1)) {
            return false;
        }

        $linked = $item['linked_container'] ?? null;
        if (!is_array($linked)) {
            return false;
        }

        return (int) ($linked['item_count'] ?? 0) > 0;
    }
}
