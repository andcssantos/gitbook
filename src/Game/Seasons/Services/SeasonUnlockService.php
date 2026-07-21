<?php

namespace App\Game\Seasons\Services;

use App\Support\DB;
use PDO;

class SeasonUnlockService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    /**
     * Avalia regras ativas de temporada para um bioma.
     *
     * @return array{
     *   applicable: bool,
     *   unlocked: bool,
     *   season_code: ?string,
     *   requirements: list<array<string, mixed>>,
     *   progress: list<array<string, mixed>>
     * }
     */
    public function evaluateBiome(int $playerId, string $biomeCode): array
    {
        if (!$this->tableExists('season_definitions') || !$this->tableExists('season_biome_unlock_rules')) {
            return [
                'applicable' => false,
                'unlocked' => false,
                'season_code' => null,
                'requirements' => [],
                'progress' => [],
            ];
        }

        $stmt = $this->pdo()->prepare("SELECT
                sd.id AS season_id,
                sd.code AS season_code,
                sd.name AS season_name,
                r.unlock_type,
                r.reference_code,
                r.required_quantity
            FROM season_biome_unlock_rules r
            INNER JOIN season_definitions sd ON sd.id = r.season_id
            WHERE r.biome_code = :biome_code
              AND r.status = 'active'
              AND sd.status = 'active'
              AND (sd.starts_at IS NULL OR sd.starts_at <= CURRENT_TIMESTAMP)
              AND (sd.ends_at IS NULL OR sd.ends_at >= CURRENT_TIMESTAMP)
            ORDER BY r.sort_order ASC, r.id ASC");
        $stmt->execute(['biome_code' => $biomeCode]);
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rules === []) {
            return [
                'applicable' => false,
                'unlocked' => false,
                'season_code' => null,
                'requirements' => [],
                'progress' => [],
            ];
        }

        $requirements = [];
        $progress = [];
        $allMet = true;
        $seasonCode = (string) ($rules[0]['season_code'] ?? '');

        foreach ($rules as $rule) {
            $type = (string) ($rule['unlock_type'] ?? '');
            $reference = (string) ($rule['reference_code'] ?? '');
            $required = max(1, (int) ($rule['required_quantity'] ?? 1));
            $current = 0;
            $met = false;

            if ($type === 'item_owned') {
                $current = $this->ownedItemQuantity($playerId, $reference);
                $met = $current >= $required;
            } elseif ($type === 'mission_complete') {
                $mission = $this->missionProgress($playerId, $reference);
                $current = (string) ($mission['status'] ?? '') === 'completed' ? 1 : 0;
                $met = $current >= 1;
            } elseif ($type === 'craft_recipe') {
                $current = $this->craftCount($playerId, $reference);
                $met = $current >= $required;
            }

            if (!$met) {
                $allMet = false;
            }

            $requirements[] = [
                'unlock_type' => $type,
                'reference_code' => $reference,
                'required_quantity' => $required,
                'season_code' => (string) ($rule['season_code'] ?? ''),
                'season_name' => (string) ($rule['season_name'] ?? ''),
            ];
            $progress[] = [
                'unlock_type' => $type,
                'reference_code' => $reference,
                'current' => $current,
                'required' => $required,
                'met' => $met,
            ];
        }

        return [
            'applicable' => true,
            'unlocked' => $allMet,
            'season_code' => $seasonCode !== '' ? $seasonCode : null,
            'requirements' => $requirements,
            'progress' => $progress,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function listActiveSeasons(): array
    {
        if (!$this->tableExists('season_definitions')) {
            return [];
        }

        $stmt = $this->pdo()->query("SELECT code, name, summary, status, starts_at, ends_at, config_json
            FROM season_definitions
            WHERE status = 'active'
              AND (starts_at IS NULL OR starts_at <= CURRENT_TIMESTAMP)
              AND (ends_at IS NULL OR ends_at >= CURRENT_TIMESTAMP)
            ORDER BY starts_at DESC, id DESC");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        return array_map(function (array $row): array {
            $config = [];
            if (is_string($row['config_json'] ?? null) && trim((string) $row['config_json']) !== '') {
                try {
                    $decoded = json_decode((string) $row['config_json'], true, 512, JSON_THROW_ON_ERROR);
                    $config = is_array($decoded) ? $decoded : [];
                } catch (\JsonException) {
                    $config = [];
                }
            }

            return [
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'summary' => (string) ($row['summary'] ?? ''),
                'status' => (string) $row['status'],
                'starts_at' => $row['starts_at'] ?? null,
                'ends_at' => $row['ends_at'] ?? null,
                'config' => $config,
            ];
        }, $rows);
    }

    /**
     * Completa missao (helper para seeds/testes/API futura).
     */
    public function completeMission(int $playerId, string $missionCode): void
    {
        if (!$this->tableExists('player_mission_progress')) {
            return;
        }

        $existing = $this->pdo()->prepare('SELECT id FROM player_mission_progress WHERE player_id = :player_id AND mission_code = :mission_code LIMIT 1');
        $existing->execute([
            'player_id' => $playerId,
            'mission_code' => $missionCode,
        ]);
        $id = (int) $existing->fetchColumn();
        if ($id > 0) {
            $this->pdo()->prepare("UPDATE player_mission_progress
                SET status = 'completed', progress_value = required_value, completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id")->execute(['id' => $id]);

            return;
        }

        $this->pdo()->prepare("INSERT INTO player_mission_progress
            (player_id, mission_code, status, progress_value, required_value, completed_at)
            VALUES (:player_id, :mission_code, 'completed', 1, 1, CURRENT_TIMESTAMP)")
            ->execute([
                'player_id' => $playerId,
                'mission_code' => $missionCode,
            ]);
    }

    private function ownedItemQuantity(int $playerId, string $definitionCode): int
    {
        if (!$this->tableExists('item_instances') || !$this->tableExists('item_definitions')) {
            return 0;
        }

        $stmt = $this->pdo()->prepare('SELECT COALESCE(SUM(ii.quantity), 0)
            FROM item_instances ii
            INNER JOIN item_definitions id ON id.id = ii.item_definition_id
            WHERE ii.owner_player_id = :player_id AND id.code = :code');
        $stmt->execute([
            'player_id' => $playerId,
            'code' => $definitionCode,
        ]);

        return (int) $stmt->fetchColumn();
    }

    private function craftCount(int $playerId, string $recipeCode): int
    {
        if ($recipeCode === '' || !$this->tableExists('player_craft_log')) {
            return 0;
        }

        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM player_craft_log
            WHERE player_id = :player_id AND recipe_code = :recipe_code');
        $stmt->execute([
            'player_id' => $playerId,
            'recipe_code' => $recipeCode,
        ]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function missionProgress(int $playerId, string $missionCode): array
    {
        if (!$this->tableExists('player_mission_progress')) {
            return [];
        }

        $stmt = $this->pdo()->prepare('SELECT * FROM player_mission_progress
            WHERE player_id = :player_id AND mission_code = :mission_code LIMIT 1');
        $stmt->execute([
            'player_id' => $playerId,
            'mission_code' => $missionCode,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
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
