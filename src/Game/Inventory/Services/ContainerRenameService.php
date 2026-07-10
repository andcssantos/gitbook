<?php

namespace App\Game\Inventory\Services;

use App\Game\Containers\Repositories\ContainerRepository;
use App\Game\Inventory\InventoryException;
use PDO;

class ContainerRenameService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function rename(int $playerId, string $containerPublicId, ?string $containerName): array
    {
        $containers = new ContainerRepository($this->pdo);
        $container = $containers->findInstanceByPublicIdForPlayer($containerPublicId, $playerId, true);
        if ($container === null) {
            if ($containers->findInstanceByPublicId($containerPublicId) !== null) {
                throw new InventoryException('INVENTORY_FORBIDDEN', 'Inventory container does not belong to the authenticated player.', 403);
            }

            throw new InventoryException('INVENTORY_CONTAINER_NOT_FOUND', 'Inventory container was not found.', 404);
        }

        if (empty($container['source_item_instance_id'])) {
            throw new InventoryException(
                'INVENTORY_CONTAINER_RENAME_FORBIDDEN',
                'Apenas baus e bags fisicos podem ser renomeados.',
                422
            );
        }

        $normalized = $this->normalizeName($containerName);
        $containers->updateInstanceName((int) $container['id'], $normalized);

        return [
            'container_public_id' => $containerPublicId,
            'name' => $normalized ?? (string) $container['name'],
        ];
    }

    private function normalizeName(?string $containerName): ?string
    {
        if ($containerName === null) {
            return null;
        }

        $trimmed = trim($containerName);
        if ($trimmed === '') {
            return null;
        }

        if (mb_strlen($trimmed) > 48) {
            throw new InventoryException('INVENTORY_CONTAINER_NAME_TOO_LONG', 'Container name must be 48 characters or fewer.', 422);
        }

        return $trimmed;
    }
}
