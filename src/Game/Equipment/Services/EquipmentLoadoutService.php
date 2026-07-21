<?php

namespace App\Game\Equipment\Services;

use App\Game\Inventory\InventoryException;
use App\Support\DB;
use App\Support\PublicId;
use PDO;
use Throwable;

class EquipmentLoadoutService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function listForPlayer(int $playerId): array
    {
        $stmt = $this->pdo()->prepare('SELECT l.*, s.equipment_slot_code, s.item_public_id, s.definition_code
            FROM player_equipment_loadouts l
            LEFT JOIN player_equipment_loadout_slots s ON s.loadout_id = l.id
            WHERE l.player_id = :player_id
            ORDER BY l.slot_index ASC, s.equipment_slot_code ASC');
        $stmt->execute(['player_id' => $playerId]);
        $byIndex = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $index = (int) $row['slot_index'];
            $byIndex[$index] ??= [
                'public_id' => (string) $row['public_id'],
                'slot_index' => $index,
                'name' => (string) $row['name'],
                'items' => [],
            ];
            if ($row['equipment_slot_code'] !== null) {
                $byIndex[$index]['items'][] = [
                    'equipment_slot_code' => (string) $row['equipment_slot_code'],
                    'item_public_id' => $row['item_public_id'] !== null ? (string) $row['item_public_id'] : null,
                    'definition_code' => $row['definition_code'] !== null ? (string) $row['definition_code'] : null,
                ];
            }
        }

        $loadouts = [];
        for ($index = 0; $index < 5; $index++) {
            $loadouts[] = $byIndex[$index] ?? [
                'public_id' => null,
                'slot_index' => $index,
                'name' => 'Loadout ' . ($index + 1),
                'items' => [],
            ];
        }

        return ['loadouts' => $loadouts];
    }

    public function saveFromCurrent(int $playerId, int $slotIndex, string $name): array
    {
        $this->assertSlotIndex($slotIndex);
        $name = trim($name);
        if ($name === '') {
            throw new InventoryException('LOADOUT_NAME_REQUIRED', 'A loadout name is required.', 422);
        }

        return $this->transaction(function () use ($playerId, $slotIndex, $name): array {
            $loadout = $this->findBySlot($playerId, $slotIndex, true);
            if ($loadout === null) {
                $insert = $this->pdo()->prepare('INSERT INTO player_equipment_loadouts (public_id, player_id, slot_index, name)
                    VALUES (:public_id, :player_id, :slot_index, :name)');
                $insert->execute([
                    'public_id' => PublicId::uuid(),
                    'player_id' => $playerId,
                    'slot_index' => $slotIndex,
                    'name' => mb_substr($name, 0, 48),
                ]);
                $loadout = $this->findBySlot($playerId, $slotIndex, true);
            } else {
                $update = $this->pdo()->prepare('UPDATE player_equipment_loadouts SET name = :name, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                $update->execute(['id' => (int) $loadout['id'], 'name' => mb_substr($name, 0, 48)]);
            }

            $delete = $this->pdo()->prepare('DELETE FROM player_equipment_loadout_slots WHERE loadout_id = :loadout_id');
            $delete->execute(['loadout_id' => (int) $loadout['id']]);
            $copy = $this->pdo()->prepare('INSERT INTO player_equipment_loadout_slots
                (loadout_id, equipment_slot_code, item_instance_id, item_public_id, definition_code)
                SELECT :loadout_id, es.code, ii.id, ii.public_id, id.code
                FROM player_equipment pe
                INNER JOIN equipment_slots es ON es.id = pe.equipment_slot_id
                INNER JOIN item_instances ii ON ii.id = pe.item_instance_id
                INNER JOIN item_definitions id ON id.id = ii.item_definition_id
                WHERE pe.player_id = :player_id');
            $copy->execute(['loadout_id' => (int) $loadout['id'], 'player_id' => $playerId]);

            return $this->loadoutById((int) $loadout['id']);
        });
    }

    public function apply(int $playerId, string $loadoutPublicId): array
    {
        return $this->transaction(function () use ($playerId, $loadoutPublicId): array {
            $stmt = $this->pdo()->prepare('SELECT * FROM player_equipment_loadouts WHERE public_id = :public_id AND player_id = :player_id LIMIT 1' . $this->lockClause());
            $stmt->execute(['public_id' => $loadoutPublicId, 'player_id' => $playerId]);
            $loadout = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($loadout)) {
                throw new InventoryException('LOADOUT_NOT_FOUND', 'Equipment loadout was not found.', 404);
            }

            $slots = $this->pdo()->prepare('SELECT * FROM player_equipment_loadout_slots WHERE loadout_id = :loadout_id ORDER BY equipment_slot_code ASC');
            $slots->execute(['loadout_id' => (int) $loadout['id']]);
            $equipment = new EquipmentService($this->pdo());
            $applied = [];
            $skipped = [];
            foreach ($slots->fetchAll(PDO::FETCH_ASSOC) ?: [] as $slot) {
                $itemPublicId = (string) ($slot['item_public_id'] ?? '');
                if ($itemPublicId === '') {
                    $skipped[] = ['equipment_slot_code' => (string) $slot['equipment_slot_code'], 'reason' => 'MISSING_ITEM_REFERENCE'];
                    continue;
                }
                try {
                    $equipped = $this->pdo()->prepare('SELECT es.code FROM player_equipment pe
                        INNER JOIN equipment_slots es ON es.id = pe.equipment_slot_id
                        INNER JOIN item_instances ii ON ii.id = pe.item_instance_id
                        WHERE pe.player_id = :player_id AND ii.public_id = :item_public_id LIMIT 1');
                    $equipped->execute(['player_id' => $playerId, 'item_public_id' => $itemPublicId]);
                    $currentSlotCode = $equipped->fetchColumn();
                    if ($currentSlotCode !== false && $currentSlotCode !== (string) $slot['equipment_slot_code']) {
                        $equipment->unequip($playerId, $itemPublicId);
                    }
                    $applied[] = $equipment->equip($playerId, $itemPublicId, (string) $slot['equipment_slot_code']);
                } catch (InventoryException $e) {
                    $skipped[] = [
                        'equipment_slot_code' => (string) $slot['equipment_slot_code'],
                        'item_public_id' => $itemPublicId,
                        'reason' => $e->errorCode(),
                    ];
                }
            }

            return ['loadout_public_id' => (string) $loadout['public_id'], 'applied' => $applied, 'skipped' => $skipped];
        });
    }

    public function delete(int $playerId, string $loadoutPublicId): array
    {
        $stmt = $this->pdo()->prepare('DELETE FROM player_equipment_loadouts WHERE public_id = :public_id AND player_id = :player_id');
        $stmt->execute(['public_id' => $loadoutPublicId, 'player_id' => $playerId]);
        if ($stmt->rowCount() === 0) {
            throw new InventoryException('LOADOUT_NOT_FOUND', 'Equipment loadout was not found.', 404);
        }
        return ['deleted' => true, 'loadout_public_id' => $loadoutPublicId];
    }

    private function loadoutById(int $loadoutId): array
    {
        $stmt = $this->pdo()->prepare('SELECT l.public_id, l.slot_index, l.name, s.equipment_slot_code, s.item_public_id, s.definition_code
            FROM player_equipment_loadouts l LEFT JOIN player_equipment_loadout_slots s ON s.loadout_id = l.id WHERE l.id = :id ORDER BY s.equipment_slot_code');
        $stmt->execute(['id' => $loadoutId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return [
            'public_id' => (string) $rows[0]['public_id'],
            'slot_index' => (int) $rows[0]['slot_index'],
            'name' => (string) $rows[0]['name'],
            'items' => array_values(array_filter(array_map(static fn (array $row): ?array => $row['equipment_slot_code'] === null ? null : [
                'equipment_slot_code' => (string) $row['equipment_slot_code'],
                'item_public_id' => $row['item_public_id'] !== null ? (string) $row['item_public_id'] : null,
                'definition_code' => $row['definition_code'] !== null ? (string) $row['definition_code'] : null,
            ], $rows))),
        ];
    }

    private function findBySlot(int $playerId, int $slotIndex, bool $lock): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM player_equipment_loadouts WHERE player_id = :player_id AND slot_index = :slot_index LIMIT 1' . ($lock ? $this->lockClause() : ''));
        $stmt->execute(['player_id' => $playerId, 'slot_index' => $slotIndex]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function assertSlotIndex(int $slotIndex): void
    {
        if ($slotIndex < 0 || $slotIndex > 4) {
            throw new InventoryException('LOADOUT_SLOT_INVALID', 'Loadout slot must be between 0 and 4.', 422);
        }
    }

    private function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        $started = !$pdo->inTransaction();
        if ($started) $pdo->beginTransaction();
        try {
            $result = $callback();
            if ($started) $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($started && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private function lockClause(): string { return $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''; }
    private function pdo(): PDO { return $this->pdo ?? DB::pdo(); }
}
