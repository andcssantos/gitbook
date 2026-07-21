<?php

namespace App\Game\Socketing\Repositories;

use App\Support\DB;
use PDO;

class ItemInstanceSocketRepository
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function listForItem(int $itemInstanceId): array
    {
        $stmt = $this->pdo()->prepare('SELECT *
            FROM item_instance_sockets
            WHERE item_instance_id = :item_instance_id
            ORDER BY socket_index ASC');
        $stmt->execute(['item_instance_id' => $itemInstanceId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countEmpty(int $itemInstanceId): int
    {
        $stmt = $this->pdo()->prepare('SELECT COUNT(*)
            FROM item_instance_sockets
            WHERE item_instance_id = :item_instance_id
                AND status = :status');
        $stmt->execute([
            'item_instance_id' => $itemInstanceId,
            'status' => 'empty',
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function findFirstEmpty(int $itemInstanceId, bool $lock = false): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT *
            FROM item_instance_sockets
            WHERE item_instance_id = :item_instance_id
                AND status = :status
            ORDER BY socket_index ASC
            LIMIT 1' . $this->lockClause($lock));
        $stmt->execute([
            'item_instance_id' => $itemInstanceId,
            'status' => 'empty',
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function markFilled(int $socketId): void
    {
        $stmt = $this->pdo()->prepare('UPDATE item_instance_sockets
            SET status = :status
            WHERE id = :id');
        $stmt->execute([
            'id' => $socketId,
            'status' => 'filled',
        ]);
    }

    public function findFilledByIndex(int $itemInstanceId, int $socketIndex, bool $lock = false): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT s.*, sg.gem_item_instance_id
            FROM item_instance_sockets s
            INNER JOIN item_socketed_gems sg ON sg.socket_id = s.id
            WHERE s.item_instance_id = :item_instance_id
                AND s.socket_index = :socket_index
                AND s.status = :status
            LIMIT 1' . $this->lockClause($lock));
        $stmt->execute([
            'item_instance_id' => $itemInstanceId,
            'socket_index' => $socketIndex,
            'status' => 'filled',
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function findSocketedGem(int $socketId, bool $lock = false): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM item_socketed_gems WHERE socket_id = :socket_id LIMIT 1' . $this->lockClause($lock));
        $stmt->execute(['socket_id' => $socketId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function clearSocketedGem(int $socketId): void
    {
        $stmt = $this->pdo()->prepare('DELETE FROM item_socketed_gems WHERE socket_id = :socket_id');
        $stmt->execute(['socket_id' => $socketId]);
    }

    public function markEmpty(int $socketId): void
    {
        $stmt = $this->pdo()->prepare('UPDATE item_instance_sockets SET status = :status WHERE id = :id');
        $stmt->execute(['id' => $socketId, 'status' => 'empty']);
    }

    public function insertSocketedGem(int $socketId, int $gemItemInstanceId): void
    {
        $stmt = $this->pdo()->prepare('INSERT INTO item_socketed_gems (
            socket_id,
            gem_item_instance_id
        ) VALUES (
            :socket_id,
            :gem_item_instance_id
        )');
        $stmt->execute([
            'socket_id' => $socketId,
            'gem_item_instance_id' => $gemItemInstanceId,
        ]);
    }

    public function ensureCount(int $itemInstanceId, int $requiredCount): int
    {
        if ($requiredCount <= 0) {
            return 0;
        }

        $existing = $this->listForItem($itemInstanceId);
        $nextIndex = 0;
        foreach ($existing as $row) {
            $nextIndex = max($nextIndex, ((int) $row['socket_index']) + 1);
        }

        while (count($existing) < $requiredCount) {
            $stmt = $this->pdo()->prepare('INSERT INTO item_instance_sockets (
                item_instance_id,
                socket_index,
                socket_type,
                status
            ) VALUES (
                :item_instance_id,
                :socket_index,
                :socket_type,
                :status
            )');
            $stmt->execute([
                'item_instance_id' => $itemInstanceId,
                'socket_index' => $nextIndex,
                'socket_type' => 'generic',
                'status' => 'empty',
            ]);

            $nextIndex += 1;
            $existing[] = ['socket_index' => $nextIndex - 1];
        }

        return count($existing);
    }

    private function lockClause(bool $lock): string
    {
        if (!$lock) {
            return '';
        }

        return $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? ' FOR UPDATE'
            : '';
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
