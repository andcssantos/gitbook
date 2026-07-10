<?php

namespace App\Game\Equipment\Services;

use App\Game\Containers\Repositories\ContainerRepository;
use App\Game\Inventory\InventoryException;
use PDO;

class ExpeditionCarryCapacityService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function syncForEquippedItem(int $playerId, array $item): ?array
    {
        if ((string) ($item['equip_slot_code'] ?? '') !== 'backpack') {
            return null;
        }

        $containers = new ContainerRepository($this->pdo);
        $backpackContainer = $containers->findInstanceBySourceItemId((int) $item['id'], true);
        if ($backpackContainer === null) {
            throw new InventoryException('EQUIPMENT_BACKPACK_CONTAINER_NOT_FOUND', 'Equipped backpack has no linked container.', 500);
        }

        $expeditionCarry = $containers->findInstanceForPlayer($playerId, 'expedition_carry');
        if ($expeditionCarry === null) {
            return null;
        }

        $this->assertPlacementsFit(
            (int) $expeditionCarry['id'],
            (int) $backpackContainer['grid_columns'],
            (int) $backpackContainer['grid_rows']
        );

        $this->resizeContainer(
            (int) $expeditionCarry['id'],
            (int) $backpackContainer['grid_columns'],
            (int) $backpackContainer['grid_rows']
        );

        return [
            'container_public_id' => (string) $expeditionCarry['public_id'],
            'grid_columns' => (int) $backpackContainer['grid_columns'],
            'grid_rows' => (int) $backpackContainer['grid_rows'],
            'source' => 'equipped_backpack',
        ];
    }

    public function assertCanUnequipItem(int $playerId, array $item): void
    {
        if ((string) ($item['equip_slot_code'] ?? '') !== 'backpack') {
            return;
        }

        $expeditionCarry = (new ContainerRepository($this->pdo))->findInstanceForPlayer($playerId, 'expedition_carry');
        if ($expeditionCarry === null) {
            return;
        }

        $count = (int) $this->pdo
            ->query('SELECT COUNT(*) FROM container_items WHERE container_instance_id = ' . (int) $expeditionCarry['id'])
            ->fetchColumn();

        if ($count > 0) {
            throw new InventoryException(
                'EQUIPMENT_BACKPACK_EXPEDITION_CARRY_NOT_EMPTY',
                'Empty expedition carry before unequipping the backpack.',
                422
            );
        }
    }

    public function resetAfterUnequip(int $playerId, array $item): ?array
    {
        if ((string) ($item['equip_slot_code'] ?? '') !== 'backpack') {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT ci.id, ci.public_id, cd.grid_columns, cd.grid_rows
            FROM container_instances ci
            INNER JOIN container_definitions cd ON cd.id = ci.container_definition_id
            WHERE ci.owner_player_id = :player_id
                AND cd.code = :code
                AND ci.status = :status
            LIMIT 1');
        $stmt->execute([
            'player_id' => $playerId,
            'code' => 'expedition_carry',
            'status' => 'active',
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $this->resizeContainer((int) $row['id'], (int) $row['grid_columns'], (int) $row['grid_rows']);

        return [
            'container_public_id' => (string) $row['public_id'],
            'grid_columns' => (int) $row['grid_columns'],
            'grid_rows' => (int) $row['grid_rows'],
            'source' => 'default_definition',
        ];
    }

    private function assertPlacementsFit(int $containerId, int $columns, int $rows): void
    {
        $stmt = $this->pdo->prepare('SELECT grid_x, grid_y, grid_w, grid_h FROM container_items WHERE container_instance_id = :container_id');
        $stmt->execute(['container_id' => $containerId]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $placement) {
            if (((int) $placement['grid_x'] + (int) $placement['grid_w']) > $columns || ((int) $placement['grid_y'] + (int) $placement['grid_h']) > $rows) {
                throw new InventoryException(
                    'EQUIPMENT_BACKPACK_EXPEDITION_CARRY_TOO_SMALL',
                    'The equipped backpack is too small for the current expedition carry contents.',
                    422
                );
            }
        }
    }

    private function resizeContainer(int $containerId, int $columns, int $rows): void
    {
        $stmt = $this->pdo->prepare('UPDATE container_instances SET grid_columns = :grid_columns, grid_rows = :grid_rows, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([
            'id' => $containerId,
            'grid_columns' => $columns,
            'grid_rows' => $rows,
        ]);
    }
}
