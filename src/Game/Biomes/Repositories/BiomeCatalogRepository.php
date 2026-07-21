<?php

namespace App\Game\Biomes\Repositories;

use App\Support\DB;
use PDO;
use Throwable;

class BiomeCatalogRepository
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function hasTables(): bool
    {
        try {
            $this->pdo()->query('SELECT 1 FROM biomes LIMIT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function countBiomes(): int
    {
        if (!$this->hasTables()) {
            return 0;
        }

        return (int) $this->pdo()->query('SELECT COUNT(*) FROM biomes')->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public function listExplorationSummaries(): array
    {
        $rows = $this->pdo()->query(
            'SELECT * FROM biomes ORDER BY sort_order ASC, code ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(fn (array $row): array => $this->mapExplorationSummary($row), $rows);
    }

    /** @return array<string, mixed>|null */
    public function getExplorationBiome(string $code): ?array
    {
        $row = $this->findBiomeRow($code);
        if ($row === null) {
            return null;
        }

        return $this->mapExplorationFull($row);
    }

    /** @return array<string, mixed>|null */
    public function getArenaBiome(string $code): ?array
    {
        $row = $this->findBiomeRow($code);
        if ($row === null) {
            return null;
        }

        return $this->mapArenaBiome($row);
    }

    /** @return array<string, mixed>|null */
    public function getMonster(string $code): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM monster_definitions WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => $this->normalizeCode($code)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return $this->mapMonster($row);
    }

    /** @return list<array<string, mixed>> */
    public function listMonsters(): array
    {
        $rows = $this->pdo()->query(
            'SELECT * FROM monster_definitions ORDER BY code ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(fn (array $row): array => $this->mapMonster($row), $rows);
    }

    /** @return array<string, mixed>|null */
    private function findBiomeRow(string $code): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM biomes WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => $this->normalizeCode($code)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapExplorationSummary(array $row): array
    {
        return [
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'summary' => (string) ($row['summary'] ?? ''),
            'status' => (string) ($row['status'] ?? 'locked'),
            'requires_expedition' => (bool) ($row['requires_expedition'] ?? true),
            'default_duration_minutes' => (int) ($row['default_duration_minutes'] ?? 30),
            'map_node' => [
                'x' => (int) ($row['map_node_x'] ?? 0),
                'y' => (int) ($row['map_node_y'] ?? 0),
            ],
            'map' => [
                'width' => max(1, (int) ($row['map_width'] ?? 6)),
                'height' => max(1, (int) ($row['map_height'] ?? 4)),
            ],
            'entry_requirements' => $this->decodeJson($row['entry_requirements_json'] ?? null),
            'world_art_url' => $row['world_art_url'] !== null ? (string) $row['world_art_url'] : null,
            'world_pin_url' => $row['world_pin_url'] !== null ? (string) $row['world_pin_url'] : null,
            'world_structure_url' => $row['world_structure_url'] !== null ? (string) $row['world_structure_url'] : null,
            'landmarks' => $this->decodeJson($row['landmarks_json'] ?? null) ?? [],
            'background_url' => $row['background_url'] !== null ? (string) $row['background_url'] : null,
            'season_featured' => (bool) ($row['season_featured'] ?? false),
        ];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapExplorationFull(array $row): array
    {
        $summary = $this->mapExplorationSummary($row);

        return array_merge($summary, [
            'default_respawn_minutes' => (int) ($row['default_respawn_minutes'] ?? 15),
            'discovery_radius' => (float) ($row['discovery_radius'] ?? 1.5),
            'map' => [
                'width' => max(1, (int) ($row['map_width'] ?? 6)),
                'height' => max(1, (int) ($row['map_height'] ?? 4)),
                'spawn' => [
                    'x' => (float) ($row['spawn_x'] ?? 0),
                    'y' => (float) ($row['spawn_y'] ?? 0),
                ],
            ],
            'unlock' => $this->decodeJson($row['unlock_json'] ?? null),
            'season_featured' => (bool) ($row['season_featured'] ?? false),
        ]);
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapArenaBiome(array $row): array
    {
        $biomeId = (int) $row['id'];
        $stmt = $this->pdo()->prepare(
            'SELECT m.code
             FROM biome_monsters bm
             INNER JOIN monster_definitions m ON m.id = bm.monster_id
             WHERE bm.biome_id = :biome_id AND bm.enabled = 1
             ORDER BY bm.sort_order ASC, m.code ASC'
        );
        $stmt->execute(['biome_id' => $biomeId]);
        $pool = array_map(static fn ($code): string => (string) $code, $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

        $data = [
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'map_width' => (float) ($row['map_width'] ?? 6),
            'map_height' => (float) ($row['map_height'] ?? 4),
            'spawn' => [
                'x' => (float) ($row['spawn_x'] ?? 0),
                'y' => (float) ($row['spawn_y'] ?? 0),
            ],
            'background_url' => (string) ($row['background_url'] ?? ''),
            'monster_spawn_count' => (int) ($row['monster_spawn_count'] ?? 6),
            'monster_elite_chance' => (float) ($row['monster_elite_chance'] ?? 0.18),
            'monster_rare_chance' => (float) ($row['monster_rare_chance'] ?? 0.04),
            'move_trap_chance' => (float) ($row['move_trap_chance'] ?? 0.05),
            'move_trap_damage_min' => (int) ($row['move_trap_damage_min'] ?? 6),
            'move_trap_damage_max' => (int) ($row['move_trap_damage_max'] ?? 12),
            'engage_radius' => (float) ($row['engage_radius'] ?? 2),
            'kills_to_boss' => (int) ($row['kills_to_boss'] ?? 10),
            'heal_on_kill_pct' => (float) ($row['heal_on_kill_pct'] ?? 0.03),
            'monster_pool' => $pool,
            'discovery_radius' => (float) ($row['discovery_radius'] ?? 1.5),
        ];

        $combatMode = (string) ($row['combat_mode'] ?? 'open');
        if ($combatMode === 'waves') {
            $data['combat_mode'] = 'waves';
            $data['wave_size'] = (int) ($row['wave_size'] ?? 3);
            $data['wave_pause_kills'] = (int) ($row['wave_pause_kills'] ?? 3);
        }

        return $data;
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapMonster(array $row): array
    {
        return [
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'sprite_key' => (string) ($row['sprite_key'] ?? 'mob'),
            'element' => (string) ($row['element'] ?? 'neutral'),
            'resistance' => (string) ($row['resistance'] ?? 'neutral'),
            'base_hp' => (int) ($row['base_hp'] ?? 100),
            'base_attack' => (int) ($row['base_attack'] ?? 10),
            'base_defense' => (int) ($row['base_defense'] ?? 5),
            'dodge_rate' => (float) ($row['dodge_rate'] ?? 0.1),
            'attack_rate' => (float) ($row['attack_rate'] ?? 0.5),
            'crit_rate' => (float) ($row['crit_rate'] ?? 0.08),
            'reward_gold_min' => (int) ($row['reward_gold_min'] ?? 3),
            'reward_gold_max' => (int) ($row['reward_gold_max'] ?? 6),
            'reward_xp_min' => (int) ($row['reward_xp_min'] ?? 10),
            'reward_xp_max' => (int) ($row['reward_xp_max'] ?? 16),
            'loot' => $this->decodeJson($row['loot_json'] ?? null) ?? [],
            'status' => (string) ($row['status'] ?? 'active'),
        ];
    }

    private function decodeJson(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return null;
        }
        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));

        return preg_replace('/[^a-z0-9_]/', '', $code) ?: '';
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
