<?php

namespace App\Game\Containers\Services;

use App\Game\Containers\Repositories\ContainerRepository;
use App\Game\Items\Repositories\ItemInstanceRepository;
use PDO;

class ContainerNestingService
{
    public const MAX_NESTING_DEPTH = 2;

    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function nestingDepth(array $container): int
    {
        $sourceItemId = (int) ($container['source_item_instance_id'] ?? 0);
        if ($sourceItemId <= 0) {
            return 0;
        }

        $containers = new ContainerRepository($this->pdo);
        $placement = $containers->findPlacementByItemId($sourceItemId);
        if ($placement === null) {
            return 0;
        }

        $parent = $containers->findInstanceById((int) $placement['container_instance_id']);
        if ($parent === null) {
            return 0;
        }

        return 1 + $this->nestingDepth($parent);
    }

    public function canPlaceContainerItem(array $targetContainer, array $item): bool
    {
        if ((int) ($item['is_container'] ?? 0) !== 1) {
            return true;
        }

        return ($this->nestingDepth($targetContainer) + 1) <= self::MAX_NESTING_DEPTH;
    }

    public function parentChain(array $container, int $playerId): array
    {
        $chain = [];
        $walker = $container;
        $containers = new ContainerRepository($this->pdo);
        $items = new ItemInstanceRepository($this->pdo);

        while (!empty($walker['source_item_instance_id'])) {
            $sourceItemId = (int) $walker['source_item_instance_id'];
            $placement = $containers->findPlacementByItemId($sourceItemId);
            if ($placement === null) {
                break;
            }

            $parentContainer = $containers->findInstanceById((int) $placement['container_instance_id']);
            if ($parentContainer === null || (int) ($parentContainer['owner_player_id'] ?? 0) !== $playerId) {
                break;
            }

            $sourceItem = $items->findById($sourceItemId);
            array_unshift($chain, [
                'container_public_id' => (string) $parentContainer['public_id'],
                'container_name' => (string) $parentContainer['name'],
                'definition_code' => (string) ($parentContainer['definition_code'] ?? ''),
                'source_item_public_id' => $sourceItem !== null ? (string) $sourceItem['public_id'] : null,
                'source_item_name' => $sourceItem !== null
                    ? (string) ($sourceItem['item_name'] ?? $sourceItem['definition_code'] ?? 'Item')
                    : null,
            ]);

            $walker = $parentContainer;
        }

        return $chain;
    }
}
