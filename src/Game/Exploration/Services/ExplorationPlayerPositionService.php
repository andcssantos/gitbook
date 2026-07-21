<?php

namespace App\Game\Exploration\Services;

use App\Game\Exploration\ExplorationException;
use App\Support\DB;
use PDO;

class ExplorationPlayerPositionService
{
    public function __construct(
        private ?PDO $pdo = null,
        private ?ExplorationBiomeCatalogService $catalog = null,
        private ?ExplorationBiomeProgressionService $progression = null
    ) {
        $this->catalog ??= new ExplorationBiomeCatalogService($this->pdo);
        $this->progression ??= new ExplorationBiomeProgressionService($this->pdo, $this->catalog);
    }

    /** @return array<string, mixed> */
    public function positionForBiome(int $playerId, string $biomeCode, ?string $expeditionPublicId = null): array
    {
        $biomeCode = $this->catalog->normalizeBiomeCode($biomeCode);
        if (!$this->tableExists()) {
            $spawn = $this->catalog->spawnPosition($biomeCode);

            return [
                'biome_code' => $biomeCode,
                'map_x' => $spawn['x'],
                'map_y' => $spawn['y'],
            ];
        }

        $stmt = $this->pdo()->prepare('SELECT map_x, map_y, expedition_public_id
            FROM player_exploration_positions
            WHERE player_id = :player_id AND biome_code = :biome_code
            LIMIT 1');
        $stmt->execute([
            'player_id' => $playerId,
            'biome_code' => $biomeCode,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return $this->resetToSpawn($playerId, $biomeCode, $expeditionPublicId);
        }

        if ($expeditionPublicId !== null && ($row['expedition_public_id'] ?? null) !== $expeditionPublicId) {
            return $this->resetToSpawn($playerId, $biomeCode, $expeditionPublicId);
        }

        return [
            'biome_code' => $biomeCode,
            'map_x' => (float) $row['map_x'],
            'map_y' => (float) $row['map_y'],
        ];
    }

    /** @return array<string, mixed> */
    public function moveTo(int $playerId, string $biomeCode, float $mapX, float $mapY, ?string $expeditionPublicId = null): array
    {
        $biomeCode = $this->catalog->normalizeBiomeCode($biomeCode);
        if (!$this->progression->isAvailableForPlayer($playerId, $biomeCode)) {
            throw new ExplorationException('EXPLORATION_BIOME_LOCKED', 'This biome is not available yet.', 422, [
                'biome_code' => $biomeCode,
            ]);
        }

        $map = $this->catalog->mapConfig($biomeCode);
        $maxX = max(0, (int) $map['width'] - 1);
        $maxY = max(0, (int) $map['height'] - 1);
        $mapX = round(max(0, min($maxX, $mapX)), 2);
        $mapY = round(max(0, min($maxY, $mapY)), 2);

        if (!$this->tableExists()) {
            return [
                'biome_code' => $biomeCode,
                'map_x' => $mapX,
                'map_y' => $mapY,
            ];
        }

        $driver = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $sql = 'INSERT INTO player_exploration_positions (
                player_id, biome_code, map_x, map_y, expedition_public_id
            ) VALUES (
                :player_id, :biome_code, :map_x, :map_y, :expedition_public_id
            ) ON DUPLICATE KEY UPDATE
                map_x = VALUES(map_x),
                map_y = VALUES(map_y),
                expedition_public_id = VALUES(expedition_public_id),
                updated_at = CURRENT_TIMESTAMP';
        } else {
            $sql = 'INSERT INTO player_exploration_positions (
                player_id, biome_code, map_x, map_y, expedition_public_id
            ) VALUES (
                :player_id, :biome_code, :map_x, :map_y, :expedition_public_id
            ) ON CONFLICT(player_id, biome_code) DO UPDATE SET
                map_x = excluded.map_x,
                map_y = excluded.map_y,
                expedition_public_id = excluded.expedition_public_id,
                updated_at = CURRENT_TIMESTAMP';
        }

        $this->pdo()->prepare($sql)->execute([
            'player_id' => $playerId,
            'biome_code' => $biomeCode,
            'map_x' => $mapX,
            'map_y' => $mapY,
            'expedition_public_id' => $expeditionPublicId,
        ]);

        return [
            'biome_code' => $biomeCode,
            'map_x' => $mapX,
            'map_y' => $mapY,
        ];
    }

    /** @return array<string, mixed> */
    public function resetToSpawn(int $playerId, string $biomeCode, ?string $expeditionPublicId = null): array
    {
        $spawn = $this->catalog->spawnPosition($biomeCode);

        return $this->moveTo($playerId, $biomeCode, $spawn['x'], $spawn['y'], $expeditionPublicId);
    }

    private function tableExists(): bool
    {
        $driver = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->pdo()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'player_exploration_positions' LIMIT 1");
            $stmt->execute();

            return (bool) $stmt->fetchColumn();
        }

        $stmt = $this->pdo()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1');
        $stmt->execute(['table' => 'player_exploration_positions']);

        return (bool) $stmt->fetchColumn();
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
