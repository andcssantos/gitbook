<?php

namespace App\Game\Inventory\Services;

use App\Game\Inventory\InventoryException;
use App\Game\Items\Repositories\ItemInstanceRepository;
use PDO;

class ItemRenameService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function rename(int $playerId, string $itemPublicId, ?string $itemName): array
    {
        $items = new ItemInstanceRepository($this->pdo);
        $item = $items->findByPublicIdAndOwner($itemPublicId, $playerId, true);
        if ($item === null) {
            if ($items->findByPublicId($itemPublicId) !== null) {
                throw new InventoryException('INVENTORY_FORBIDDEN', 'Inventory item does not belong to the authenticated player.', 403);
            }

            throw new InventoryException('INVENTORY_ITEM_NOT_FOUND', 'Inventory item was not found.', 404);
        }

        if ((int) ($item['is_container'] ?? 0) !== 1) {
            throw new InventoryException(
                'INVENTORY_ITEM_RENAME_FORBIDDEN',
                'Apenas baus e bags podem ser renomeados.',
                422
            );
        }

        $normalized = $this->normalizeName($itemName);
        $items->updateItemName((int) $item['id'], $normalized);

        return [
            'item_public_id' => $itemPublicId,
            'item_name' => $normalized,
        ];
    }

    private function normalizeName(?string $itemName): ?string
    {
        if ($itemName === null) {
            return null;
        }

        $trimmed = trim($itemName);
        if ($trimmed === '') {
            return null;
        }

        if (mb_strlen($trimmed) > 48) {
            throw new InventoryException('INVENTORY_ITEM_NAME_TOO_LONG', 'Item name must be 48 characters or fewer.', 422);
        }

        return $trimmed;
    }
}
