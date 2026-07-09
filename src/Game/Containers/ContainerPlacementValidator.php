<?php

namespace App\Game\Containers;

class ContainerPlacementValidator
{
    public function canPlaceItemDefinition(array $containerDefinition, array $itemDefinition): bool
    {
        if (!empty($itemDefinition['is_container']) && empty($containerDefinition['allow_container_items'])) {
            return false;
        }

        return true;
    }
}
