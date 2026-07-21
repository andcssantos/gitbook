<?php

namespace App\Game\Admin\Services;

use App\Game\Inventory\InventoryException;
use App\Support\DB;
use PDO;
use Throwable;

class AdminMonsterDefinitionService
{
    private const STATUSES = ['active', 'inactive', 'draft'];

    public function __construct(private ?PDO $pdo = null)
    {
    }

    /** @return array{definitions: list<array<string, mixed>>, total: int, limit: int, offset: int} */
    public function list(array $filters = []): array
    {
        $q = trim((string) ($filters['q'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));
        $limit = max(1, min(200, (int) ($filters['limit'] ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $where = ['1=1'];
        $params = [];
        if ($q !== '') {
            $where[] = '(code LIKE :q OR name LIKE :q OR sprite_key LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        if ($status !== '') {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }
        $whereSql = implode(' AND ', $where);

        $countStmt = $this->pdo()->prepare("SELECT COUNT(*) FROM monster_definitions WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->pdo()->prepare(
            "SELECT * FROM monster_definitions WHERE {$whereSql} ORDER BY code ASC LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'definitions' => array_map(fn (array $row): array => $this->mapRow($row), $rows),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /** @return array<string, mixed> */
    public function getByCode(string $code): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM monster_definitions WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => $this->normalizeCode($code)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new InventoryException('ADMIN_MONSTER_NOT_FOUND', 'Monster definition was not found.', 404);
        }

        return $this->mapRow($row);
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        return $this->transaction(function () use ($payload): array {
            $code = $this->normalizeCode((string) ($payload['code'] ?? ''));
            if ($code === '') {
                throw new InventoryException('ADMIN_MONSTER_INVALID', 'Monster code is required.', 422);
            }
            $existing = $this->pdo()->prepare('SELECT id FROM monster_definitions WHERE code = :code LIMIT 1');
            $existing->execute(['code' => $code]);
            if ($existing->fetchColumn() !== false) {
                throw new InventoryException('ADMIN_MONSTER_CODE_EXISTS', 'Monster code already exists.', 409);
            }

            $fields = $this->normalizeWritableFields($payload, true);
            $this->pdo()->prepare(
                'INSERT INTO monster_definitions (
                    code, name, sprite_key, element, resistance, base_hp, base_attack, base_defense,
                    dodge_rate, attack_rate, crit_rate, reward_gold_min, reward_gold_max,
                    reward_xp_min, reward_xp_max, loot_json, status
                ) VALUES (
                    :code, :name, :sprite_key, :element, :resistance, :base_hp, :base_attack, :base_defense,
                    :dodge_rate, :attack_rate, :crit_rate, :reward_gold_min, :reward_gold_max,
                    :reward_xp_min, :reward_xp_max, :loot_json, :status
                )'
            )->execute(['code' => $code, ...$fields]);

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

            $this->pdo()->prepare(
                'UPDATE monster_definitions SET
                    name = :name, sprite_key = :sprite_key, element = :element, resistance = :resistance,
                    base_hp = :base_hp, base_attack = :base_attack, base_defense = :base_defense,
                    dodge_rate = :dodge_rate, attack_rate = :attack_rate, crit_rate = :crit_rate,
                    reward_gold_min = :reward_gold_min, reward_gold_max = :reward_gold_max,
                    reward_xp_min = :reward_xp_min, reward_xp_max = :reward_xp_max,
                    loot_json = :loot_json, status = :status, updated_at = CURRENT_TIMESTAMP
                 WHERE code = :code'
            )->execute(['code' => $normalized, ...$fields]);

            return $this->getByCode($normalized);
        });
    }

    /** @return array{statuses: list<string>, sprite_keys: list<string>} */
    public function meta(): array
    {
        return [
            'statuses' => self::STATUSES,
            'sprite_keys' => ['treant', 'brute', 'crab', 'lurker', 'bat', 'golem', 'specter', 'toad', 'wisp', 'mob'],
        ];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'code' => (string) ($row['code'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
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
            throw new InventoryException('ADMIN_MONSTER_INVALID', 'Monster name is required.', 422);
        }

        $status = trim((string) ($payload['status'] ?? ($current['status'] ?? 'active')));
        if (!in_array($status, self::STATUSES, true)) {
            throw new InventoryException('ADMIN_MONSTER_INVALID', 'Invalid status.', 422);
        }

        $loot = array_key_exists('loot', $payload) ? $payload['loot'] : ($current['loot'] ?? []);
        if (is_string($loot)) {
            try {
                $loot = json_decode($loot, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                throw new InventoryException('ADMIN_MONSTER_INVALID', 'loot must be valid JSON.', 422);
            }
        }
        if (!is_array($loot)) {
            throw new InventoryException('ADMIN_MONSTER_INVALID', 'loot must be an array.', 422);
        }

        return [
            'name' => $name,
            'sprite_key' => trim((string) ($payload['sprite_key'] ?? ($current['sprite_key'] ?? 'mob'))) ?: 'mob',
            'element' => trim((string) ($payload['element'] ?? ($current['element'] ?? 'neutral'))) ?: 'neutral',
            'resistance' => trim((string) ($payload['resistance'] ?? ($current['resistance'] ?? 'neutral'))) ?: 'neutral',
            'base_hp' => max(1, (int) ($payload['base_hp'] ?? ($current['base_hp'] ?? 100))),
            'base_attack' => max(0, (int) ($payload['base_attack'] ?? ($current['base_attack'] ?? 10))),
            'base_defense' => max(0, (int) ($payload['base_defense'] ?? ($current['base_defense'] ?? 5))),
            'dodge_rate' => max(0, min(1, (float) ($payload['dodge_rate'] ?? ($current['dodge_rate'] ?? 0.1)))),
            'attack_rate' => max(0, min(1, (float) ($payload['attack_rate'] ?? ($current['attack_rate'] ?? 0.5)))),
            'crit_rate' => max(0, min(1, (float) ($payload['crit_rate'] ?? ($current['crit_rate'] ?? 0.08)))),
            'reward_gold_min' => max(0, (int) ($payload['reward_gold_min'] ?? ($current['reward_gold_min'] ?? 3))),
            'reward_gold_max' => max(0, (int) ($payload['reward_gold_max'] ?? ($current['reward_gold_max'] ?? 6))),
            'reward_xp_min' => max(0, (int) ($payload['reward_xp_min'] ?? ($current['reward_xp_min'] ?? 10))),
            'reward_xp_max' => max(0, (int) ($payload['reward_xp_max'] ?? ($current['reward_xp_max'] ?? 16))),
            'loot_json' => json_encode($loot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'status' => $status,
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
