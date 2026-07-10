<?php

namespace App\Game\Equipment\Services;

use App\Game\Containers\Repositories\ContainerRepository;
use App\Game\Inventory\InventoryException;
use PDO;

class ExpeditionCarryCapacityService
{
    public const BASELINE_COLUMNS = 2;
    public const BASELINE_ROWS = 2;

    public function __construct(private PDO $pdo)
    {
    }

    public function ensureBaselineForPlayer(int $playerId): ?array
    {
        $equippedBackpack = $this->findEquippedBackpackItem($playerId);
        if ($equippedBackpack !== null) {
            return $this->syncForEquippedItem($playerId, $equippedBackpack);
        }

        $containers = new ContainerRepository($this->pdo);
        $expeditionCarry = $containers->findInstanceForPlayer($playerId, 'expedition_carry');
        if ($expeditionCarry === null) {
            return null;
        }

        $containerId = (int) $expeditionCarry['id'];
        $currentColumns = (int) $expeditionCarry['grid_columns'];
        $currentRows = (int) $expeditionCarry['grid_rows'];

        if ($currentColumns === self::BASELINE_COLUMNS && $currentRows === self::BASELINE_ROWS) {
            return null;
        }

        try {
            $this->assertPocketPlacementsFit($containerId);
        } catch (InventoryException) {
            return null;
        }

        $this->resizeContainer($containerId, self::BASELINE_COLUMNS, self::BASELINE_ROWS);

        return [
            'container_public_id' => (string) $expeditionCarry['public_id'],
            'grid_columns' => self::BASELINE_COLUMNS,
            'grid_rows' => self::BASELINE_ROWS,
            'pocket_columns' => self::BASELINE_COLUMNS,
            'pocket_rows' => self::BASELINE_ROWS,
            'backpack_columns' => 0,
            'backpack_rows' => 0,
            'source' => 'baseline_without_backpack',
        ];
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

        $backpackColumns = (int) $backpackContainer['grid_columns'];
        $backpackRows = (int) $backpackContainer['grid_rows'];
        $combined = $this->combinedDimensions($backpackColumns, $backpackRows);
        $backpackId = (int) $backpackContainer['id'];
        $expeditionId = (int) $expeditionCarry['id'];

        $this->assertEquippedLayoutFits($backpackId, $expeditionId, $backpackColumns, $backpackRows);
        $this->resizeContainer($expeditionId, $combined['columns'], $combined['rows']);
        $this->transferBackpackContentsToExpedition($backpackId, $expeditionId);

        return [
            'container_public_id' => (string) $expeditionCarry['public_id'],
            'grid_columns' => $combined['columns'],
            'grid_rows' => $combined['rows'],
            'pocket_columns' => self::BASELINE_COLUMNS,
            'pocket_rows' => self::BASELINE_ROWS,
            'backpack_columns' => $backpackColumns,
            'backpack_rows' => $backpackRows,
            'source' => 'equipped_backpack',
        ];
    }

    public function assertCanUnequipItem(int $playerId, array $item): void
    {
        if ((string) ($item['equip_slot_code'] ?? '') !== 'backpack') {
            return;
        }

        $containers = new ContainerRepository($this->pdo);
        $expeditionCarry = $containers->findInstanceForPlayer($playerId, 'expedition_carry');
        if ($expeditionCarry === null) {
            return;
        }

        $backpackContainer = $containers->findInstanceBySourceItemId((int) $item['id'], true);
        if ($backpackContainer === null) {
            return;
        }

        $this->assertPocketPlacementsFit((int) $expeditionCarry['id']);

        $backpackZone = $this->placementsInBackpackZone($containers->listPlacements((int) $expeditionCarry['id'], true));
        if ($backpackZone === []) {
            return;
        }

        $this->assertPlacementsDoNotOverlap(
            $backpackZone,
            (int) $backpackContainer['grid_columns'],
            (int) $backpackContainer['grid_rows'],
            0
        );
    }

    public function restoreBackpackContentsAfterUnequip(int $playerId, array $item): void
    {
        if ((string) ($item['equip_slot_code'] ?? '') !== 'backpack') {
            return;
        }

        $containers = new ContainerRepository($this->pdo);
        $backpackContainer = $containers->findInstanceBySourceItemId((int) $item['id'], true);
        $expeditionCarry = $containers->findInstanceForPlayer($playerId, 'expedition_carry');
        if ($backpackContainer === null || $expeditionCarry === null) {
            return;
        }

        $expeditionId = (int) $expeditionCarry['id'];
        $backpackId = (int) $backpackContainer['id'];
        $backpackColumns = (int) $backpackContainer['grid_columns'];
        $backpackRows = (int) $backpackContainer['grid_rows'];

        foreach ($containers->listPlacements($expeditionId, true) as $placement) {
            if (!$this->isBackpackZonePlacement($placement)) {
                continue;
            }

            $containers->updatePlacement((int) $placement['id'], [
                'container_instance_id' => $backpackId,
                'grid_x' => (int) $placement['grid_x'] - self::BASELINE_COLUMNS,
                'grid_y' => (int) $placement['grid_y'],
                'grid_w' => (int) $placement['grid_w'],
                'grid_h' => (int) $placement['grid_h'],
                'rotated' => (int) ($placement['rotated'] ?? 0),
            ]);
        }
    }

    public function resetAfterUnequip(int $playerId, array $item): ?array
    {
        if ((string) ($item['equip_slot_code'] ?? '') !== 'backpack') {
            return null;
        }

        $containers = new ContainerRepository($this->pdo);
        $expeditionCarry = $containers->findInstanceForPlayer($playerId, 'expedition_carry');
        if ($expeditionCarry === null) {
            return null;
        }

        $this->assertPocketPlacementsFit((int) $expeditionCarry['id']);
        $this->resizeContainer((int) $expeditionCarry['id'], self::BASELINE_COLUMNS, self::BASELINE_ROWS);

        return [
            'container_public_id' => (string) $expeditionCarry['public_id'],
            'grid_columns' => self::BASELINE_COLUMNS,
            'grid_rows' => self::BASELINE_ROWS,
            'pocket_columns' => self::BASELINE_COLUMNS,
            'pocket_rows' => self::BASELINE_ROWS,
            'backpack_columns' => 0,
            'backpack_rows' => 0,
            'source' => 'baseline_without_backpack',
        ];
    }

    /** @return array{columns:int,rows:int} */
    public function combinedDimensions(int $backpackColumns, int $backpackRows): array
    {
        return [
            'columns' => self::BASELINE_COLUMNS + max(1, $backpackColumns),
            'rows' => max(self::BASELINE_ROWS, max(1, $backpackRows)),
        ];
    }

    private function findEquippedBackpackItem(int $playerId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT
                ii.*,
                id.equip_slot_code
            FROM player_equipment pe
            INNER JOIN item_instances ii ON ii.id = pe.item_instance_id
            INNER JOIN item_definitions id ON id.id = ii.item_definition_id
            WHERE pe.player_id = :player_id
                AND id.equip_slot_code = :slot_code
            LIMIT 1');
        $stmt->execute([
            'player_id' => $playerId,
            'slot_code' => 'backpack',
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function assertEquippedLayoutFits(int $backpackContainerId, int $expeditionContainerId, int $backpackColumns, int $backpackRows): void
    {
        $containers = new ContainerRepository($this->pdo);
        $combined = $this->combinedDimensions($backpackColumns, $backpackRows);
        $placements = [];

        foreach ($containers->listPlacements($expeditionContainerId, true) as $placement) {
            if ($this->isPocketZonePlacement($placement)) {
                $placements[] = $placement;
            }
        }

        foreach ($containers->listPlacements($backpackContainerId, true) as $placement) {
            $placements[] = [
                'grid_x' => (int) $placement['grid_x'] + self::BASELINE_COLUMNS,
                'grid_y' => (int) $placement['grid_y'],
                'grid_w' => (int) $placement['grid_w'],
                'grid_h' => (int) $placement['grid_h'],
            ];
        }

        $this->assertPlacementsDoNotOverlap($placements, $combined['columns'], $combined['rows'], 0);
    }

    private function transferBackpackContentsToExpedition(int $backpackContainerId, int $expeditionContainerId): void
    {
        if ($backpackContainerId === $expeditionContainerId) {
            return;
        }

        $containers = new ContainerRepository($this->pdo);
        $fromPlacements = $containers->listPlacements($backpackContainerId, true);
        if ($fromPlacements === []) {
            return;
        }

        foreach ($fromPlacements as $placement) {
            $containers->updatePlacement((int) $placement['id'], [
                'container_instance_id' => $expeditionContainerId,
                'grid_x' => (int) $placement['grid_x'] + self::BASELINE_COLUMNS,
                'grid_y' => (int) $placement['grid_y'],
                'grid_w' => (int) $placement['grid_w'],
                'grid_h' => (int) $placement['grid_h'],
                'rotated' => (int) ($placement['rotated'] ?? 0),
            ]);
        }
    }

    private function assertPocketPlacementsFit(int $containerId): void
    {
        $containers = new ContainerRepository($this->pdo);
        $pocketPlacements = array_filter(
            $containers->listPlacements($containerId, true),
            fn (array $placement): bool => $this->isPocketZonePlacement($placement)
        );

        $this->assertPlacementsDoNotOverlap(
            array_values($pocketPlacements),
            self::BASELINE_COLUMNS,
            self::BASELINE_ROWS,
            0
        );
    }

    private function placementsInBackpackZone(array $placements): array
    {
        $normalized = [];
        foreach ($placements as $placement) {
            if (!$this->isBackpackZonePlacement($placement)) {
                continue;
            }

            $normalized[] = [
                'grid_x' => (int) $placement['grid_x'] - self::BASELINE_COLUMNS,
                'grid_y' => (int) $placement['grid_y'],
                'grid_w' => (int) $placement['grid_w'],
                'grid_h' => (int) $placement['grid_h'],
            ];
        }

        return $normalized;
    }

    private function isPocketZonePlacement(array $placement): bool
    {
        return (int) $placement['grid_x'] < self::BASELINE_COLUMNS
            && (int) $placement['grid_y'] < self::BASELINE_ROWS;
    }

    private function isBackpackZonePlacement(array $placement): bool
    {
        return (int) $placement['grid_x'] >= self::BASELINE_COLUMNS;
    }

    private function assertPlacementsDoNotOverlap(array $placements, int $columns, int $rows, int $offsetX = 0): void
    {
        $occupied = [];
        foreach ($placements as $placement) {
            $x = (int) $placement['grid_x'] - $offsetX;
            $y = (int) $placement['grid_y'];
            $w = (int) $placement['grid_w'];
            $h = (int) $placement['grid_h'];

            if ($x < 0 || $y < 0 || ($x + $w) > $columns || ($y + $h) > $rows) {
                throw new InventoryException(
                    'EQUIPMENT_BACKPACK_EXPEDITION_CARRY_TOO_SMALL',
                    'The equipped backpack is too small for the current expedition carry contents.',
                    422
                );
            }

            for ($row = $y; $row < $y + $h; $row += 1) {
                for ($col = $x; $col < $x + $w; $col += 1) {
                    $key = $row . ':' . $col;
                    if (isset($occupied[$key])) {
                        throw new InventoryException(
                            'EQUIPMENT_BACKPACK_EXPEDITION_CARRY_TOO_SMALL',
                            'The equipped backpack is too small for the current expedition carry contents.',
                            422
                        );
                    }

                    $occupied[$key] = true;
                }
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
