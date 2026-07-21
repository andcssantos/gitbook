<?php

namespace App\Game\Exploration\Services;

use App\Support\DB;
use PDO;

class ExplorationRespawnService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function refreshDueInstances(int $playerId, ?string $biomeCode = null): int
    {
        if (!$this->tableExists('investigable_instances') || !$this->columnExists('respawn_at')) {
            return 0;
        }

        $sql = "UPDATE investigable_instances
            SET state = 'active',
                depleted_at = NULL,
                respawn_at = NULL,
                uses_remaining = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE player_id = :player_id
                AND state = 'depleted'
                AND respawn_at IS NOT NULL
                AND respawn_at <= :now";
        $params = [
            'player_id' => $playerId,
            'now' => date('Y-m-d H:i:s'),
        ];

        if ($biomeCode !== null && $biomeCode !== '') {
            $sql .= ' AND biome_code = :biome_code';
            $params['biome_code'] = $biomeCode;
        }

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    public function scheduleRespawn(int $instanceId, int $playerId, int $respawnMinutes): void
    {
        if (!$this->columnExists('respawn_at')) {
            $this->pdo()->prepare('UPDATE investigable_instances
                SET state = :state,
                    uses_remaining = 0,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id AND player_id = :player_id')->execute([
                'id' => $instanceId,
                'player_id' => $playerId,
                'state' => 'depleted',
            ]);

            return;
        }

        $minutes = max(1, $respawnMinutes);
        $now = time();
        $depletedAt = date('Y-m-d H:i:s', $now);
        $respawnAt = date('Y-m-d H:i:s', $now + ($minutes * 60));

        $this->pdo()->prepare('UPDATE investigable_instances
            SET state = :state,
                uses_remaining = 0,
                depleted_at = :depleted_at,
                respawn_at = :respawn_at,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND player_id = :player_id')->execute([
            'id' => $instanceId,
            'player_id' => $playerId,
            'state' => 'depleted',
            'depleted_at' => $depletedAt,
            'respawn_at' => $respawnAt,
        ]);
    }

    public function respawnSecondsRemaining(?string $respawnAt): ?int
    {
        if ($respawnAt === null || trim($respawnAt) === '') {
            return null;
        }

        $timestamp = strtotime($respawnAt);
        if ($timestamp === false) {
            return null;
        }

        return max(0, $timestamp - time());
    }

    private function columnExists(string $column): bool
    {
        if (!$this->tableExists('investigable_instances')) {
            return false;
        }

        $driver = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->pdo()->query('PRAGMA table_info(investigable_instances)');
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (($row['name'] ?? null) === $column) {
                    return true;
                }
            }

            return false;
        }

        $stmt = $this->pdo()->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column LIMIT 1');
        $stmt->execute([
            'table' => 'investigable_instances',
            'column' => $column,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    private function tableExists(string $table): bool
    {
        $driver = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->pdo()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
            $stmt->execute(['table' => $table]);

            return (bool) $stmt->fetchColumn();
        }

        $stmt = $this->pdo()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1');
        $stmt->execute(['table' => $table]);

        return (bool) $stmt->fetchColumn();
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
