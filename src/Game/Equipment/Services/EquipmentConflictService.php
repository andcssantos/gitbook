<?php

namespace App\Game\Equipment\Services;

use App\Game\Inventory\InventoryException;
use PDO;

class EquipmentConflictService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function assertCanEquip(int $playerId, array $item, string $targetSlotCode): void
    {
        $equipped = $this->equippedItems($playerId);
        $mainWeapon = $equipped['weapon'] ?? null;
        $offhandSlots = ['weapon_offhand', 'shield', 'quiver'];

        if ($targetSlotCode === 'weapon' && $this->isTwoHanded($item)) {
            foreach ($offhandSlots as $slotCode) {
                if (isset($equipped[$slotCode])) {
                    throw new InventoryException(
                        'EQUIPMENT_TWO_HANDED_REQUIRES_FREE_OFFHAND',
                        'Two-handed weapons require all offhand slots to be empty.',
                        409
                    );
                }
            }
        }

        if (in_array($targetSlotCode, $offhandSlots, true) && $mainWeapon !== null && $this->isTwoHanded($mainWeapon)) {
            throw new InventoryException(
                'EQUIPMENT_OFFHAND_BLOCKED_BY_TWO_HANDED',
                'Offhand slots are blocked by the equipped two-handed weapon.',
                409
            );
        }

        if ($targetSlotCode === 'weapon_offhand') {
            if (!$this->allowsDualWield($item) || $mainWeapon === null || !$this->allowsDualWield($mainWeapon)) {
                throw new InventoryException(
                    'EQUIPMENT_DUAL_WIELD_NOT_ALLOWED',
                    'This weapon combination cannot be dual-wielded.',
                    409
                );
            }
        }

        if ($targetSlotCode === 'shield' && $this->offhandType($item) !== 'shield') {
            throw new InventoryException('EQUIPMENT_INVALID_OFFHAND_TYPE', 'Only shields can use the shield slot.', 422);
        }

        if ($targetSlotCode === 'quiver' && $this->offhandType($item) !== 'quiver') {
            throw new InventoryException('EQUIPMENT_INVALID_OFFHAND_TYPE', 'Only quivers can use the quiver slot.', 422);
        }
    }

    public function isTwoHanded(array $item): bool
    {
        return (int) ($this->config($item)['hands'] ?? 1) >= 2;
    }

    private function allowsDualWield(array $item): bool
    {
        $config = $this->config($item);

        return !$this->isTwoHanded($item) && (bool) ($config['allow_dual_wield'] ?? false);
    }

    private function offhandType(array $item): string
    {
        return (string) ($this->config($item)['offhand_type'] ?? '');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function equippedItems(int $playerId): array
    {
        $stmt = $this->pdo->prepare('SELECT
                es.code AS slot_code,
                ii.*,
                id.code AS definition_code,
                id.equip_slot_code,
                id.base_config
            FROM player_equipment pe
            INNER JOIN equipment_slots es ON es.id = pe.equipment_slot_id
            INNER JOIN item_instances ii ON ii.id = pe.item_instance_id
            INNER JOIN item_definitions id ON id.id = ii.item_definition_id
            WHERE pe.player_id = :player_id');
        $stmt->execute(['player_id' => $playerId]);

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[(string) $row['slot_code']] = $row;
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function config(array $item): array
    {
        $raw = $item['base_config'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
