<?php

namespace App\Game\Admin\Services;

use App\Game\Inventory\InventoryException;
use App\Support\DB;
use PDO;
use Throwable;

class AdminBiomeService
{
    private const STATUSES = ['available', 'locked', 'inactive', 'draft'];
    private const COMBAT_MODES = ['open', 'waves'];

    public function __construct(private ?PDO $pdo = null)
    {
    }

    /** @return array{biomes: list<array<string, mixed>>, total: int, limit: int, offset: int} */
    public function list(array $filters = []): array
    {
        $q = trim((string) ($filters['q'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));
        $limit = max(1, min(200, (int) ($filters['limit'] ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $where = ['1=1'];
        $params = [];
        if ($q !== '') {
            $where[] = '(code LIKE :q OR name LIKE :q OR COALESCE(summary, \'\') LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        if ($status !== '') {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }
        $whereSql = implode(' AND ', $where);

        $countStmt = $this->pdo()->prepare("SELECT COUNT(*) FROM biomes WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->pdo()->prepare(
            "SELECT * FROM biomes WHERE {$whereSql} ORDER BY sort_order ASC, code ASC LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'biomes' => array_map(fn (array $row): array => $this->mapSummary($row), $rows),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /** @return array<string, mixed> */
    public function getByCode(string $code): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM biomes WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => $this->normalizeCode($code)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new InventoryException('ADMIN_BIOME_NOT_FOUND', 'Biome was not found.', 404);
        }

        return $this->mapFull($row);
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        return $this->transaction(function () use ($payload): array {
            $code = $this->normalizeCode((string) ($payload['code'] ?? ''));
            if ($code === '') {
                throw new InventoryException('ADMIN_BIOME_INVALID', 'Biome code is required.', 422);
            }
            $existing = $this->pdo()->prepare('SELECT id FROM biomes WHERE code = :code LIMIT 1');
            $existing->execute(['code' => $code]);
            if ($existing->fetchColumn() !== false) {
                throw new InventoryException('ADMIN_BIOME_CODE_EXISTS', 'Biome code already exists.', 409);
            }

            $fields = $this->normalizeWritableFields($payload, true);
            $stmt = $this->pdo()->prepare(
                'INSERT INTO biomes (
                    code, name, summary, status, sort_order, requires_expedition,
                    default_duration_minutes, default_respawn_minutes, discovery_radius,
                    map_width, map_height, spawn_x, spawn_y, map_node_x, map_node_y,
                    background_url, world_art_url, world_pin_url, world_structure_url,
                    monster_spawn_count, monster_elite_chance, monster_rare_chance,
                    move_trap_chance, move_trap_damage_min, move_trap_damage_max,
                    engage_radius, kills_to_boss, heal_on_kill_pct, combat_mode,
                    wave_size, wave_pause_kills, season_featured,
                    unlock_json, entry_requirements_json, landmarks_json, settings_json
                ) VALUES (
                    :code, :name, :summary, :status, :sort_order, :requires_expedition,
                    :default_duration_minutes, :default_respawn_minutes, :discovery_radius,
                    :map_width, :map_height, :spawn_x, :spawn_y, :map_node_x, :map_node_y,
                    :background_url, :world_art_url, :world_pin_url, :world_structure_url,
                    :monster_spawn_count, :monster_elite_chance, :monster_rare_chance,
                    :move_trap_chance, :move_trap_damage_min, :move_trap_damage_max,
                    :engage_radius, :kills_to_boss, :heal_on_kill_pct, :combat_mode,
                    :wave_size, :wave_pause_kills, :season_featured,
                    :unlock_json, :entry_requirements_json, :landmarks_json, :settings_json
                )'
            );
            $stmt->execute(['code' => $code, ...$fields]);
            $biomeId = (int) $this->pdo()->lastInsertId();
            $this->replaceMonsters($biomeId, $payload['monsters'] ?? []);

            return $this->getByCode($code);
        });
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(string $code, array $payload): array
    {
        return $this->transaction(function () use ($code, $payload): array {
            $normalized = $this->normalizeCode($code);
            $current = $this->getByCode($normalized);
            $fields = $this->normalizeWritableFields($payload, false, $current);

            $stmt = $this->pdo()->prepare(
                'UPDATE biomes SET
                    name = :name, summary = :summary, status = :status, sort_order = :sort_order,
                    requires_expedition = :requires_expedition,
                    default_duration_minutes = :default_duration_minutes,
                    default_respawn_minutes = :default_respawn_minutes,
                    discovery_radius = :discovery_radius, map_width = :map_width, map_height = :map_height,
                    spawn_x = :spawn_x, spawn_y = :spawn_y, map_node_x = :map_node_x, map_node_y = :map_node_y,
                    background_url = :background_url, world_art_url = :world_art_url,
                    world_pin_url = :world_pin_url, world_structure_url = :world_structure_url,
                    monster_spawn_count = :monster_spawn_count, monster_elite_chance = :monster_elite_chance,
                    monster_rare_chance = :monster_rare_chance, move_trap_chance = :move_trap_chance,
                    move_trap_damage_min = :move_trap_damage_min, move_trap_damage_max = :move_trap_damage_max,
                    engage_radius = :engage_radius, kills_to_boss = :kills_to_boss,
                    heal_on_kill_pct = :heal_on_kill_pct, combat_mode = :combat_mode,
                    wave_size = :wave_size, wave_pause_kills = :wave_pause_kills,
                    season_featured = :season_featured, unlock_json = :unlock_json,
                    entry_requirements_json = :entry_requirements_json, landmarks_json = :landmarks_json,
                    settings_json = :settings_json, updated_at = CURRENT_TIMESTAMP
                 WHERE code = :code'
            );
            $stmt->execute(['code' => $normalized, ...$fields]);

            if (array_key_exists('monsters', $payload)) {
                $this->replaceMonsters((int) $current['id'], $payload['monsters']);
            }

            return $this->getByCode($normalized);
        });
    }

    /** @return array{statuses: list<string>, combat_modes: list<string>, monsters: list<array<string, mixed>>} */
    public function meta(): array
    {
        $monsters = $this->pdo()->query(
            'SELECT code, name, status FROM monster_definitions ORDER BY code ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'statuses' => self::STATUSES,
            'combat_modes' => self::COMBAT_MODES,
            'monsters' => array_map(static fn (array $row): array => [
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'status' => (string) $row['status'],
            ], $monsters),
            'sprite_keys' => ['treant', 'brute', 'crab', 'lurker', 'bat', 'golem', 'specter', 'toad', 'wisp', 'mob'],
        ];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapSummary(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'summary' => (string) ($row['summary'] ?? ''),
            'status' => (string) ($row['status'] ?? 'locked'),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'season_featured' => (bool) ($row['season_featured'] ?? false),
            'monster_spawn_count' => (int) ($row['monster_spawn_count'] ?? 0),
            'map_width' => (float) ($row['map_width'] ?? 0),
            'map_height' => (float) ($row['map_height'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapFull(array $row): array
    {
        $biomeId = (int) $row['id'];
        $stmt = $this->pdo()->prepare(
            'SELECT m.code AS monster_code, m.name AS monster_name, bm.spawn_weight, bm.is_boss_candidate, bm.enabled, bm.sort_order
             FROM biome_monsters bm
             INNER JOIN monster_definitions m ON m.id = bm.monster_id
             WHERE bm.biome_id = :biome_id
             ORDER BY bm.sort_order ASC, m.code ASC'
        );
        $stmt->execute(['biome_id' => $biomeId]);
        $monsters = array_map(static function (array $entry): array {
            return [
                'monster_code' => (string) $entry['monster_code'],
                'monster_name' => (string) $entry['monster_name'],
                'spawn_weight' => (int) $entry['spawn_weight'],
                'is_boss_candidate' => (bool) $entry['is_boss_candidate'],
                'enabled' => (bool) $entry['enabled'],
                'sort_order' => (int) $entry['sort_order'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

        return [
            'id' => $biomeId,
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'summary' => $row['summary'] !== null ? (string) $row['summary'] : null,
            'status' => (string) ($row['status'] ?? 'locked'),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'requires_expedition' => (bool) ($row['requires_expedition'] ?? true),
            'default_duration_minutes' => (int) ($row['default_duration_minutes'] ?? 30),
            'default_respawn_minutes' => (int) ($row['default_respawn_minutes'] ?? 15),
            'discovery_radius' => (float) ($row['discovery_radius'] ?? 1.5),
            'map_width' => (float) ($row['map_width'] ?? 6),
            'map_height' => (float) ($row['map_height'] ?? 4),
            'spawn_x' => (float) ($row['spawn_x'] ?? 1),
            'spawn_y' => (float) ($row['spawn_y'] ?? 2),
            'map_node_x' => (int) ($row['map_node_x'] ?? 0),
            'map_node_y' => (int) ($row['map_node_y'] ?? 0),
            'background_url' => $row['background_url'] !== null ? (string) $row['background_url'] : null,
            'world_art_url' => $row['world_art_url'] !== null ? (string) $row['world_art_url'] : null,
            'world_pin_url' => $row['world_pin_url'] !== null ? (string) $row['world_pin_url'] : null,
            'world_structure_url' => $row['world_structure_url'] !== null ? (string) $row['world_structure_url'] : null,
            'monster_spawn_count' => (int) ($row['monster_spawn_count'] ?? 6),
            'monster_elite_chance' => (float) ($row['monster_elite_chance'] ?? 0.18),
            'monster_rare_chance' => (float) ($row['monster_rare_chance'] ?? 0.04),
            'move_trap_chance' => (float) ($row['move_trap_chance'] ?? 0.05),
            'move_trap_damage_min' => (int) ($row['move_trap_damage_min'] ?? 6),
            'move_trap_damage_max' => (int) ($row['move_trap_damage_max'] ?? 12),
            'engage_radius' => (float) ($row['engage_radius'] ?? 2),
            'kills_to_boss' => (int) ($row['kills_to_boss'] ?? 10),
            'heal_on_kill_pct' => (float) ($row['heal_on_kill_pct'] ?? 0.03),
            'combat_mode' => (string) ($row['combat_mode'] ?? 'open'),
            'wave_size' => $row['wave_size'] !== null ? (int) $row['wave_size'] : null,
            'wave_pause_kills' => $row['wave_pause_kills'] !== null ? (int) $row['wave_pause_kills'] : null,
            'season_featured' => (bool) ($row['season_featured'] ?? false),
            'unlock' => $this->decodeJson($row['unlock_json'] ?? null),
            'entry_requirements' => $this->decodeJson($row['entry_requirements_json'] ?? null),
            'landmarks' => $this->decodeJson($row['landmarks_json'] ?? null) ?? [],
            'settings' => $this->decodeJson($row['settings_json'] ?? null),
            'monsters' => $monsters,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /** @param array<string, mixed> $payload
     * @param array<string, mixed>|null $current
     * @return array<string, mixed>
     */
    private function normalizeWritableFields(array $payload, bool $creating, ?array $current = null): array
    {
        $name = trim((string) ($payload['name'] ?? ($current['name'] ?? '')));
        if ($name === '') {
            throw new InventoryException('ADMIN_BIOME_INVALID', 'Biome name is required.', 422);
        }

        $status = trim((string) ($payload['status'] ?? ($current['status'] ?? 'locked')));
        if (!in_array($status, self::STATUSES, true)) {
            throw new InventoryException('ADMIN_BIOME_INVALID', 'Invalid status.', 422);
        }

        $combatMode = trim((string) ($payload['combat_mode'] ?? ($current['combat_mode'] ?? 'open')));
        if (!in_array($combatMode, self::COMBAT_MODES, true)) {
            throw new InventoryException('ADMIN_BIOME_INVALID', 'Invalid combat_mode.', 422);
        }

        $nullableString = static function (array $payload, string $key, ?array $current): ?string {
            if (array_key_exists($key, $payload)) {
                $value = trim((string) ($payload[$key] ?? ''));

                return $value !== '' ? $value : null;
            }

            $currentValue = $current[$key] ?? null;

            return $currentValue !== null && $currentValue !== '' ? (string) $currentValue : null;
        };

        $jsonField = function (array $payload, string $key, ?array $current, bool $asArray = false): ?string {
            $value = array_key_exists($key, $payload) ? $payload[$key] : ($current[$key] ?? ($asArray ? [] : null));
            if (is_string($value) && $value !== '') {
                try {
                    $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                } catch (Throwable) {
                    throw new InventoryException('ADMIN_BIOME_INVALID', "{$key} must be valid JSON.", 422);
                }
            }
            if ($value === null || $value === '') {
                return $asArray ? json_encode([], JSON_UNESCAPED_UNICODE) : null;
            }
            if (!is_array($value) && !($value instanceof \stdClass)) {
                throw new InventoryException('ADMIN_BIOME_INVALID', "{$key} must be an object/array.", 422);
            }

            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        };

        return [
            'name' => $name,
            'summary' => $nullableString($payload, 'summary', $current),
            'status' => $status,
            'sort_order' => (int) ($payload['sort_order'] ?? ($current['sort_order'] ?? 0)),
            'requires_expedition' => (int) (bool) ($payload['requires_expedition'] ?? ($current['requires_expedition'] ?? true)),
            'default_duration_minutes' => max(1, (int) ($payload['default_duration_minutes'] ?? ($current['default_duration_minutes'] ?? 30))),
            'default_respawn_minutes' => max(0, (int) ($payload['default_respawn_minutes'] ?? ($current['default_respawn_minutes'] ?? 15))),
            'discovery_radius' => max(0.1, (float) ($payload['discovery_radius'] ?? ($current['discovery_radius'] ?? 1.5))),
            'map_width' => max(1, (float) ($payload['map_width'] ?? ($current['map_width'] ?? 6))),
            'map_height' => max(1, (float) ($payload['map_height'] ?? ($current['map_height'] ?? 4))),
            'spawn_x' => (float) ($payload['spawn_x'] ?? ($current['spawn_x'] ?? 1)),
            'spawn_y' => (float) ($payload['spawn_y'] ?? ($current['spawn_y'] ?? 2)),
            'map_node_x' => (int) ($payload['map_node_x'] ?? ($current['map_node_x'] ?? 0)),
            'map_node_y' => (int) ($payload['map_node_y'] ?? ($current['map_node_y'] ?? 0)),
            'background_url' => $nullableString($payload, 'background_url', $current),
            'world_art_url' => $nullableString($payload, 'world_art_url', $current),
            'world_pin_url' => $nullableString($payload, 'world_pin_url', $current),
            'world_structure_url' => $nullableString($payload, 'world_structure_url', $current),
            'monster_spawn_count' => max(0, (int) ($payload['monster_spawn_count'] ?? ($current['monster_spawn_count'] ?? 6))),
            'monster_elite_chance' => max(0, min(1, (float) ($payload['monster_elite_chance'] ?? ($current['monster_elite_chance'] ?? 0.18)))),
            'monster_rare_chance' => max(0, min(1, (float) ($payload['monster_rare_chance'] ?? ($current['monster_rare_chance'] ?? 0.04)))),
            'move_trap_chance' => max(0, min(1, (float) ($payload['move_trap_chance'] ?? ($current['move_trap_chance'] ?? 0.05)))),
            'move_trap_damage_min' => max(0, (int) ($payload['move_trap_damage_min'] ?? ($current['move_trap_damage_min'] ?? 6))),
            'move_trap_damage_max' => max(0, (int) ($payload['move_trap_damage_max'] ?? ($current['move_trap_damage_max'] ?? 12))),
            'engage_radius' => max(0.1, (float) ($payload['engage_radius'] ?? ($current['engage_radius'] ?? 2))),
            'kills_to_boss' => max(1, (int) ($payload['kills_to_boss'] ?? ($current['kills_to_boss'] ?? 10))),
            'heal_on_kill_pct' => max(0, min(1, (float) ($payload['heal_on_kill_pct'] ?? ($current['heal_on_kill_pct'] ?? 0.03)))),
            'combat_mode' => $combatMode,
            'wave_size' => ($payload['wave_size'] ?? ($current['wave_size'] ?? null)) !== null && ($payload['wave_size'] ?? ($current['wave_size'] ?? '')) !== ''
                ? max(1, (int) ($payload['wave_size'] ?? $current['wave_size']))
                : null,
            'wave_pause_kills' => ($payload['wave_pause_kills'] ?? ($current['wave_pause_kills'] ?? null)) !== null && ($payload['wave_pause_kills'] ?? ($current['wave_pause_kills'] ?? '')) !== ''
                ? max(0, (int) ($payload['wave_pause_kills'] ?? $current['wave_pause_kills']))
                : null,
            'season_featured' => (int) (bool) ($payload['season_featured'] ?? ($current['season_featured'] ?? false)),
            'unlock_json' => $jsonField($payload, 'unlock', $current),
            'entry_requirements_json' => $jsonField($payload, 'entry_requirements', $current),
            'landmarks_json' => $jsonField($payload, 'landmarks', $current, true),
            'settings_json' => $jsonField($payload, 'settings', $current),
        ];
    }

    /** @param list<mixed>|mixed $monsters */
    private function replaceMonsters(int $biomeId, mixed $monsters): void
    {
        if (is_string($monsters)) {
            try {
                $monsters = json_decode($monsters, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                throw new InventoryException('ADMIN_BIOME_INVALID', 'monsters must be valid JSON.', 422);
            }
        }
        if (!is_array($monsters)) {
            throw new InventoryException('ADMIN_BIOME_INVALID', 'monsters must be an array.', 422);
        }

        $this->pdo()->prepare('DELETE FROM biome_monsters WHERE biome_id = :biome_id')->execute(['biome_id' => $biomeId]);
        $insert = $this->pdo()->prepare(
            'INSERT INTO biome_monsters (biome_id, monster_id, spawn_weight, is_boss_candidate, enabled, sort_order)
             VALUES (:biome_id, :monster_id, :spawn_weight, :is_boss_candidate, :enabled, :sort_order)'
        );

        foreach (array_values($monsters) as $index => $entry) {
            if (is_string($entry)) {
                $entry = ['monster_code' => $entry];
            }
            if (!is_array($entry)) {
                continue;
            }
            $monsterCode = $this->normalizeCode((string) ($entry['monster_code'] ?? $entry['code'] ?? ''));
            $monsterId = $this->monsterIdByCode($monsterCode);
            if ($monsterId === null) {
                throw new InventoryException('ADMIN_BIOME_INVALID', "Unknown monster_code: {$monsterCode}", 422);
            }
            $insert->execute([
                'biome_id' => $biomeId,
                'monster_id' => $monsterId,
                'spawn_weight' => max(1, (int) ($entry['spawn_weight'] ?? 100)),
                'is_boss_candidate' => (int) (bool) ($entry['is_boss_candidate'] ?? true),
                'enabled' => (int) (bool) ($entry['enabled'] ?? true),
                'sort_order' => (int) ($entry['sort_order'] ?? (($index + 1) * 10)),
            ]);
        }
    }

    private function monsterIdByCode(string $code): ?int
    {
        if ($code === '') {
            return null;
        }
        $stmt = $this->pdo()->prepare('SELECT id FROM monster_definitions WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => $code]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
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

    private function transaction(callable $callback): mixed
    {
        if ($this->pdo instanceof PDO) {
            $started = !$this->pdo->inTransaction();
            if ($started) {
                $this->pdo->beginTransaction();
            }
            try {
                $result = $callback();
                if ($started && $this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }

                return $result;
            } catch (Throwable $e) {
                if ($started && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
        }

        return DB::transaction(fn (): mixed => $callback());
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
