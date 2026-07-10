<?php

namespace App\Game\Equipment\Services;

use App\Game\Containers\Repositories\ContainerRepository;
use App\Game\Inventory\Services\InventoryAutoPlacementService;
use App\Game\Inventory\InventoryException;
use App\Game\Items\Repositories\ItemInstanceRepository;
use App\Support\DB;
use PDO;
use Throwable;

class EquipmentService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function equip(int $playerId, string $itemPublicId): array
    {
        return $this->transaction(function () use ($playerId, $itemPublicId): array {
            $items = new ItemInstanceRepository($this->pdo());
            $item = $items->findByPublicIdAndOwner($itemPublicId, $playerId, true);
            if ($item === null) {
                throw new InventoryException('EQUIPMENT_ITEM_NOT_FOUND', 'Equipment item was not found.', 404);
            }

            $slotCode = trim((string) ($item['equip_slot_code'] ?? ''));
            if ($slotCode === '') {
                throw new InventoryException('EQUIPMENT_ITEM_NOT_EQUIPPABLE', 'This item cannot be equipped.', 422);
            }

            if ($slotCode !== 'potion' && ((int) ($item['stackable'] ?? 0) === 1 || (int) ($item['quantity'] ?? 1) !== 1)) {
                throw new InventoryException('EQUIPMENT_STACK_NOT_EQUIPPABLE', 'Stacked items cannot be equipped.', 422);
            }

            $slot = $this->availableSlotForItem($playerId, $item, $slotCode);
            if ($slot === null) {
                throw new InventoryException('EQUIPMENT_SLOT_NOT_AVAILABLE', 'No compatible equipment slot is available.', 409);
            }

            $existing = $this->equippedInSlot($playerId, (int) $slot['id'], true);
            if ($existing !== null && (int) $existing['item_instance_id'] !== (int) $item['id']) {
                throw new InventoryException('EQUIPMENT_SLOT_OCCUPIED', 'Unequip the current item before equipping another one in this slot.', 409);
            }

            $containers = new ContainerRepository($this->pdo());
            $containers->deletePlacementByItemId((int) $item['id']);

            if ($existing === null) {
                $stmt = $this->pdo()->prepare('INSERT INTO player_equipment (player_id, equipment_slot_id, item_instance_id) VALUES (:player_id, :equipment_slot_id, :item_instance_id)');
                $stmt->execute([
                    'player_id' => $playerId,
                    'equipment_slot_id' => (int) $slot['id'],
                    'item_instance_id' => (int) $item['id'],
                ]);
            }

            $expeditionCarry = (new ExpeditionCarryCapacityService($this->pdo()))->syncForEquippedItem($playerId, $item);

            return [
                'action' => 'EQUIP',
                'item_public_id' => (string) $item['public_id'],
                'slot_code' => (string) $slot['code'],
                'slot_name' => (string) $slot['name'],
                'equipped' => true,
                'expedition_carry' => $expeditionCarry,
            ];
        });
    }

    public function unequip(int $playerId, string $itemPublicId): array
    {
        return $this->transaction(function () use ($playerId, $itemPublicId): array {
            $items = new ItemInstanceRepository($this->pdo());
            $item = $items->findByPublicIdAndOwner($itemPublicId, $playerId, true);
            if ($item === null) {
                throw new InventoryException('EQUIPMENT_ITEM_NOT_FOUND', 'Equipment item was not found.', 404);
            }

            $equipped = $this->equippedItem($playerId, (int) $item['id'], true);
            if ($equipped === null) {
                throw new InventoryException('EQUIPMENT_ITEM_NOT_EQUIPPED', 'This item is not equipped.', 422);
            }

            $capacity = new ExpeditionCarryCapacityService($this->pdo());
            $capacity->assertCanUnequipItem($playerId, $item);

            $delete = $this->pdo()->prepare('DELETE FROM player_equipment WHERE player_id = :player_id AND equipment_slot_id = :equipment_slot_id AND item_instance_id = :item_instance_id');
            $delete->execute([
                'player_id' => $playerId,
                'equipment_slot_id' => (int) $equipped['equipment_slot_id'],
                'item_instance_id' => (int) $item['id'],
            ]);

            $containers = new ContainerRepository($this->pdo());
            $containers->deletePlacementByItemId((int) $item['id']);

            try {
                $capacity->restoreBackpackContentsAfterUnequip($playerId, $item);
                $placement = (new InventoryAutoPlacementService($this->pdo()))->autoPlaceExistingItem($playerId, $item);
                $expeditionCarry = $capacity->resetAfterUnequip($playerId, $item);
            } catch (Throwable $e) {
                $restore = $this->pdo()->prepare('INSERT INTO player_equipment (player_id, equipment_slot_id, item_instance_id) VALUES (:player_id, :equipment_slot_id, :item_instance_id)');
                $restore->execute([
                    'player_id' => $playerId,
                    'equipment_slot_id' => (int) $equipped['equipment_slot_id'],
                    'item_instance_id' => (int) $item['id'],
                ]);

                throw $e;
            }

            return [
                'action' => 'UNEQUIP',
                'item_public_id' => (string) $item['public_id'],
                'slot_code' => (string) $equipped['slot_code'],
                'slot_name' => (string) $equipped['slot_name'],
                'equipped' => false,
                'placement' => $placement,
                'expedition_carry' => $expeditionCarry,
            ];
        });
    }

    private function slotByCode(string $slotCode): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM equipment_slots WHERE code = :code AND status = :status LIMIT 1');
        $stmt->execute([
            'code' => $slotCode,
            'status' => 'active',
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function availableSlotForItem(int $playerId, array $item, string $slotCode): ?array
    {
        $conflicts = new EquipmentConflictService($this->pdo());
        $lastConflict = null;

        foreach ($this->compatibleSlotCodes($slotCode, $item) as $compatibleCode) {
            $slot = $this->slotByCode($compatibleCode);
            if ($slot === null) {
                continue;
            }

            try {
                $conflicts->assertCanEquip($playerId, $item, $compatibleCode);
            } catch (InventoryException $e) {
                $lastConflict = $e;
                continue;
            }

            $existing = $this->equippedInSlot($playerId, (int) $slot['id'], true);
            if ($existing === null || (int) $existing['item_instance_id'] === (int) $item['id']) {
                return $slot;
            }
        }

        if ($lastConflict instanceof InventoryException) {
            throw $lastConflict;
        }

        return null;
    }

    private function compatibleSlotCodes(string $slotCode, array $item): array
    {
        return match ($slotCode) {
            'ring' => ['ring', 'ring_2'],
            'potion' => ['potion_1', 'potion_2', 'potion_3', 'potion_4'],
            'weapon' => (new EquipmentConflictService($this->pdo()))->isTwoHanded($item) ? ['weapon'] : ['weapon', 'weapon_offhand'],
            default => [$slotCode],
        };
    }

    private function equippedInSlot(int $playerId, int $slotId, bool $lock = false): ?array
    {
        $sql = 'SELECT * FROM player_equipment WHERE player_id = :player_id AND equipment_slot_id = :slot_id LIMIT 1';
        if ($lock && $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([
            'player_id' => $playerId,
            'slot_id' => $slotId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function equippedItem(int $playerId, int $itemInstanceId, bool $lock = false): ?array
    {
        $sql = 'SELECT
                pe.player_id,
                pe.equipment_slot_id,
                pe.item_instance_id,
                pe.equipped_at,
                es.code AS slot_code,
                es.name AS slot_name
            FROM player_equipment pe
            INNER JOIN equipment_slots es ON es.id = pe.equipment_slot_id
            WHERE pe.player_id = :player_id AND pe.item_instance_id = :item_instance_id
            LIMIT 1';
        if ($lock && $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([
            'player_id' => $playerId,
            'item_instance_id' => $itemInstanceId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function transaction(callable $callback): mixed
    {
        if ($this->pdo instanceof PDO) {
            $started = !$this->pdo->inTransaction();
            if ($started) {
                $this->pdo->beginTransaction();
            }

            try {
                $result = $callback();
                if ($started && $this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }

                return $result;
            } catch (Throwable $e) {
                if ($started && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                throw $e;
            }
        }

        return DB::transaction(fn (): mixed => $callback());
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
