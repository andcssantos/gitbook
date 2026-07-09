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
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM container_items WHERE container_instance_id = :container_id');
        $stmt->execute(['container_id' => $containerId]);

        return (int) $stmt->fetchColumn();
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
}
