<?php

namespace App\Game\Containers\Repositories;

use App\Support\DB;
use PDO;

class ContainerItemRepository
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function countByContainerId(int $containerId): int
    {
        $stmt = $this->pdo()->prepare(
            'SELECT COUNT(*)
             FROM container_items ci
             INNER JOIN item_instances ii ON ii.id = ci.item_instance_id
             WHERE ci.container_instance_id = :container_id
               AND ii.state = :state
               AND ii.quantity > 0'
        );
        $stmt->execute([
            'container_id' => $containerId,
            'state' => 'available',
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function findByItemAndContainer(int $itemInstanceId, int $containerInstanceId, bool $lock = false): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM container_items WHERE item_instance_id = :item_instance_id AND container_instance_id = :container_instance_id LIMIT 1' . $this->lockClause($lock));
        $stmt->execute([
            'item_instance_id' => $itemInstanceId,
            'container_instance_id' => $containerInstanceId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function listByContainerId(int $containerId, bool $lock = false): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT ci.*,
                    id.grid_w AS definition_grid_w,
                    id.grid_h AS definition_grid_h
             FROM container_items ci
             INNER JOIN item_instances ii ON ii.id = ci.item_instance_id
             INNER JOIN item_definitions id ON id.id = ii.item_definition_id
             WHERE ci.container_instance_id = :container_id
               AND ii.state = :state
               AND ii.quantity > 0
             ORDER BY ci.id ASC' . $this->lockClause($lock)
        );
        $stmt->execute([
            'container_id' => $containerId,
            'state' => 'available',
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updatePlacement(int $placementId, array $data): void
    {
        $stmt = $this->pdo()->prepare('UPDATE container_items
            SET container_instance_id = :container_instance_id,
                grid_x = :grid_x,
                grid_y = :grid_y,
                grid_w = :grid_w,
                grid_h = :grid_h,
                rotated = :rotated,
                placement_version = placement_version + 1,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id');

        $stmt->execute([
            'container_instance_id' => $data['container_instance_id'],
            'grid_x' => $data['grid_x'],
            'grid_y' => $data['grid_y'],
            'grid_w' => $data['grid_w'],
            'grid_h' => $data['grid_h'],
            'rotated' => $data['rotated'] ?? 0,
            'id' => $placementId,
        ]);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM container_items WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function findByItemId(int $itemInstanceId, bool $lock = false): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM container_items WHERE item_instance_id = :item_instance_id LIMIT 1' . $this->lockClause($lock));
        $stmt->execute(['item_instance_id' => $itemInstanceId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function deleteByItemId(int $itemInstanceId): void
    {
        $stmt = $this->pdo()->prepare('DELETE FROM container_items WHERE item_instance_id = :item_instance_id');
        $stmt->execute(['item_instance_id' => $itemInstanceId]);
    }

    public function place(array $data): int
    {
        $stmt = $this->pdo()->prepare('INSERT INTO container_items (
            container_instance_id,
            item_instance_id,
            grid_x,
            grid_y,
            grid_w,
            grid_h,
            rotated,
            locked,
            placement_version
        ) VALUES (
            :container_instance_id,
            :item_instance_id,
            :grid_x,
            :grid_y,
            :grid_w,
            :grid_h,
            :rotated,
            :locked,
            :placement_version
        )');

        $stmt->execute([
            'container_instance_id' => $data['container_instance_id'],
            'item_instance_id' => $data['item_instance_id'],
            'grid_x' => $data['grid_x'],
            'grid_y' => $data['grid_y'],
            'grid_w' => $data['grid_w'],
            'grid_h' => $data['grid_h'],
            'rotated' => $data['rotated'] ?? 0,
            'locked' => $data['locked'] ?? 0,
            'placement_version' => $data['placement_version'] ?? 1,
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }

    private function lockClause(bool $lock): string
    {
        return $lock && $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }
}
