<?php

namespace App\Game\Crafting\Services;

use App\Game\Containers\Repositories\ContainerRepository;
use App\Game\Inventory\InventoryException;
use App\Game\Items\Repositories\ItemInstanceRepository;
use App\Game\Items\Services\ItemSafetyService;
use App\Game\Materials\Services\PlayerMaterialStashService;
use App\Support\DB;
use PDO;

class CraftingConsumptionService
{
    public function __construct(
        private ?PDO $pdo = null,
        private ?ItemInstanceRepository $items = null,
        private ?PlayerMaterialStashService $stash = null
    ) {
        $this->items ??= new ItemInstanceRepository($this->pdo);
        $this->stash ??= new PlayerMaterialStashService($this->pdo);
    }

    /**
     * @param array<int, array<string, mixed>> $resolvedSlots
     */
    public function consume(int $playerId, array $resolvedSlots, ?array $historyMetadata = null): void
    {
        foreach ($resolvedSlots as $slot) {
            if (!is_array($slot)) {
                continue;
            }

            $kind = (string) ($slot['source_kind'] ?? '');
            if ($kind === 'material_stack') {
                $this->consumeMaterialStack($playerId, $slot);
                continue;
            }

            if ($kind === 'item_instance') {
                $this->consumeItemInstance($playerId, $slot, $historyMetadata);
            }
        }
    }

    private function consumeMaterialStack(int $playerId, array $slot): void
    {
        $familyId = (int) ($slot['material_family_id'] ?? 0);
        $originId = (int) ($slot['material_origin_id'] ?? 0);
        $quantity = max(1, (int) ($slot['consume_quantity'] ?? 1));

        if ($familyId <= 0 || $originId <= 0) {
            throw new InventoryException('CRAFT_MATERIAL_INVALID', 'Material invalido para crafting.', 422);
        }

        $stmt = $this->pdo()->prepare('SELECT quantity, stash_tab FROM player_material_stacks
            WHERE player_id = :player_id AND material_family_id = :material_family_id AND material_origin_id = :material_origin_id
            LIMIT 1');
        $stmt->execute([
            'player_id' => $playerId,
            'material_family_id' => $familyId,
            'material_origin_id' => $originId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (int) $row['quantity'] < $quantity) {
            throw new InventoryException('CRAFT_MATERIAL_NOT_ENOUGH', 'Quantidade insuficiente de material.', 422);
        }

        $remaining = (int) $row['quantity'] - $quantity;
        if ($remaining <= 0) {
            $this->pdo()->prepare('DELETE FROM player_material_stacks
                WHERE player_id = :player_id AND material_family_id = :material_family_id AND material_origin_id = :material_origin_id')
                ->execute([
                    'player_id' => $playerId,
                    'material_family_id' => $familyId,
                    'material_origin_id' => $originId,
                ]);

            return;
        }

        $this->pdo()->prepare('UPDATE player_material_stacks SET quantity = :quantity
            WHERE player_id = :player_id AND material_family_id = :material_family_id AND material_origin_id = :material_origin_id')
            ->execute([
                'quantity' => $remaining,
                'player_id' => $playerId,
                'material_family_id' => $familyId,
                'material_origin_id' => $originId,
            ]);
    }

    private function consumeItemInstance(int $playerId, array $slot, ?array $historyMetadata = null): void
    {
        $publicId = (string) ($slot['public_id'] ?? '');
        $item = $this->items->findByPublicIdAndOwner($publicId, $playerId, true);
        if ($item === null) {
            throw new InventoryException('CRAFT_ITEM_NOT_FOUND', 'Item nao encontrado para consumo.', 404);
        }

        if ((string) ($item['state'] ?? 'available') !== 'available') {
            throw new InventoryException('CRAFT_ITEM_UNAVAILABLE', 'Item indisponivel para crafting.', 422);
        }

        $safety = new ItemSafetyService($this->pdo());
        $safety->assertNotLocked($playerId, (int) $item['id'], 'CRAFT');

        $quantity = max(1, (int) ($slot['consume_quantity'] ?? 1));
        $currentQty = max(1, (int) ($item['quantity'] ?? 1));
        $itemId = (int) $item['id'];

        $metadata = array_merge($historyMetadata ?? [], [
            'quantity' => $quantity,
        ]);
        $safety->record($item, $playerId, 'crafted_consumed', $metadata);

        if ((int) ($item['stackable'] ?? 0) === 1 && $currentQty > $quantity) {
            $this->items->updateQuantity($itemId, $currentQty - $quantity);

            return;
        }

        $containers = new ContainerRepository($this->pdo());
        $containers->deletePlacementByItemId($itemId);

        if ((int) ($item['is_container'] ?? 0) === 1) {
            $linked = $containers->findInstanceBySourceItemId($itemId, true);
            if ($linked !== null && $containers->countItems((int) $linked['id']) > 0) {
                throw new InventoryException('CRAFT_CONTAINER_NOT_EMPTY', 'Esvazie o container antes de usar no crafting.', 422);
            }
            if ($linked !== null) {
                $this->pdo()->prepare('UPDATE container_instances SET status = :status WHERE id = :id')
                    ->execute(['id' => (int) $linked['id'], 'status' => 'inactive']);
            }
        }

        $this->items->deleteById($itemId);
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
