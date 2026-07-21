<?php

namespace App\Game\Player\Services;

use App\Support\DB;
use PDO;
use RuntimeException;

class PlayerAttributeService
{
    public const POINTS_PER_PLAYER_LEVEL = 5;

    /** @var array<string, array{name:string,base:float,growth:float,icon:string,mode:string}> */
    private const ATTRIBUTES = [
        'strength' => ['name' => 'Forca', 'base' => 5.0, 'growth' => 1.0, 'icon' => 'STR', 'mode' => 'combat'],
        'defense' => ['name' => 'Defesa', 'base' => 5.0, 'growth' => 1.0, 'icon' => 'DEF', 'mode' => 'combat'],
        'agility' => ['name' => 'Agilidade', 'base' => 5.0, 'growth' => 0.8, 'icon' => 'AGI', 'mode' => 'combat'],
        'energy' => ['name' => 'Energia', 'base' => 5.0, 'growth' => 1.0, 'icon' => 'ENE', 'mode' => 'combat'],
        'investigation' => ['name' => 'Investigacao', 'base' => 1.0, 'growth' => 0.75, 'icon' => 'INV', 'mode' => 'skill'],
        'exploration' => ['name' => 'Exploracao', 'base' => 1.0, 'growth' => 0.75, 'icon' => 'EXP', 'mode' => 'skill'],
        'mining' => ['name' => 'Mineracao', 'base' => 1.0, 'growth' => 0.75, 'icon' => 'MIN', 'mode' => 'skill'],
        'botany' => ['name' => 'Botanica', 'base' => 1.0, 'growth' => 0.75, 'icon' => 'BOT', 'mode' => 'skill'],
        'lockpicking' => ['name' => 'Arrombamento', 'base' => 1.0, 'growth' => 0.75, 'icon' => 'LOCK', 'mode' => 'skill'],
    ];

    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function ensureDefaults(int $playerId): void
    {
        if (!$this->tableExists('player_attributes')) {
            return;
        }

        $driver = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        $hasAllocated = $this->columnExists('player_attributes', 'allocated_points');
        foreach (self::ATTRIBUTES as $code => $definition) {
            if ($driver === 'mysql') {
                $sql = $hasAllocated
                    ? 'INSERT IGNORE INTO player_attributes (
                        player_id, attribute_code, level, xp, base_value, allocated_points
                    ) VALUES (
                        :player_id, :attribute_code, 1, 0, :base_value, 0
                    )'
                    : 'INSERT IGNORE INTO player_attributes (
                        player_id, attribute_code, level, xp, base_value
                    ) VALUES (
                        :player_id, :attribute_code, 1, 0, :base_value
                    )';
            } else {
                $sql = $hasAllocated
                    ? 'INSERT INTO player_attributes (
                        player_id, attribute_code, level, xp, base_value, allocated_points
                    ) VALUES (
                        :player_id, :attribute_code, 1, 0, :base_value, 0
                    ) ON CONFLICT(player_id, attribute_code) DO NOTHING'
                    : 'INSERT INTO player_attributes (
                        player_id, attribute_code, level, xp, base_value
                    ) VALUES (
                        :player_id, :attribute_code, 1, 0, :base_value
                    ) ON CONFLICT(player_id, attribute_code) DO NOTHING';
            }

            $stmt = $this->pdo()->prepare($sql);
            $stmt->execute([
                'player_id' => $playerId,
                'attribute_code' => $code,
                'base_value' => $definition['base'],
            ]);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function listForPlayer(int $playerId): array
    {
        $this->ensureDefaults($playerId);
        if (!$this->tableExists('player_attributes')) {
            return $this->fallbackAttributes();
        }

        $hasAllocated = $this->columnExists('player_attributes', 'allocated_points');
        $select = $hasAllocated
            ? 'SELECT attribute_code, level, xp, base_value, allocated_points FROM player_attributes'
            : 'SELECT attribute_code, level, xp, base_value FROM player_attributes';

        $stmt = $this->pdo()->prepare($select . '
            WHERE player_id = :player_id
            ORDER BY id ASC');
        $stmt->execute(['player_id' => $playerId]);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = $this->mapAttributeRow($row, $hasAllocated);
        }

        return $rows;
    }

    public function unspentPoints(int $playerId): int
    {
        if (!$this->columnExists('players', 'unspent_attribute_points')) {
            return 0;
        }

        $stmt = $this->pdo()->prepare('SELECT unspent_attribute_points FROM players WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $playerId]);

        return max(0, (int) $stmt->fetchColumn());
    }

    public function grantUnspentPoints(int $playerId, int $points, string $source = 'level_up'): array
    {
        if ($points <= 0 || !$this->columnExists('players', 'unspent_attribute_points')) {
            return ['updated' => false, 'unspent_attribute_points' => $this->unspentPoints($playerId)];
        }

        $this->pdo()->prepare('UPDATE players
            SET unspent_attribute_points = unspent_attribute_points + :points,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id')->execute([
            'id' => $playerId,
            'points' => $points,
        ]);

        return [
            'updated' => true,
            'granted' => $points,
            'source' => $source,
            'unspent_attribute_points' => $this->unspentPoints($playerId),
        ];
    }

    public function allocate(int $playerId, string $attributeCode, int $points = 1): array
    {
        $this->ensureDefaults($playerId);
        $code = strtolower(trim($attributeCode));
        $definition = self::ATTRIBUTES[$code] ?? null;
        if ($definition === null || ($definition['mode'] ?? '') !== 'combat') {
            throw new RuntimeException('Atributo invalido para alocacao.');
        }
        if ($points < 1 || $points > 50) {
            throw new RuntimeException('Quantidade de pontos invalida.');
        }
        if (!$this->columnExists('players', 'unspent_attribute_points')
            || !$this->columnExists('player_attributes', 'allocated_points')) {
            throw new RuntimeException('Sistema de pontos indisponivel.');
        }

        $playerStmt = $this->pdo()->prepare('SELECT unspent_attribute_points FROM players WHERE id = :id LIMIT 1' . $this->lockClause());
        $playerStmt->execute(['id' => $playerId]);
        $unspent = (int) $playerStmt->fetchColumn();
        if ($unspent < $points) {
            throw new RuntimeException('Pontos insuficientes.');
        }

        $attrStmt = $this->pdo()->prepare('SELECT id, allocated_points, base_value, level, xp
            FROM player_attributes
            WHERE player_id = :player_id AND attribute_code = :attribute_code
            LIMIT 1' . $this->lockClause());
        $attrStmt->execute([
            'player_id' => $playerId,
            'attribute_code' => $code,
        ]);
        $row = $attrStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Atributo nao encontrado.');
        }

        $allocated = max(0, (int) ($row['allocated_points'] ?? 0)) + $points;
        $this->pdo()->prepare('UPDATE player_attributes
            SET allocated_points = :allocated_points, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id')->execute([
            'id' => (int) $row['id'],
            'allocated_points' => $allocated,
        ]);

        $this->pdo()->prepare('UPDATE players
            SET unspent_attribute_points = unspent_attribute_points - :points,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id')->execute([
            'id' => $playerId,
            'points' => $points,
        ]);

        if ($this->tableExists('player_attribute_events')) {
            $this->pdo()->prepare('INSERT INTO player_attribute_events (
                player_id, attribute_code, xp_delta, level_before, level_after, source_type, source_id, action_code, metadata_json
            ) VALUES (
                :player_id, :attribute_code, 0, :level_before, :level_after, :source_type, NULL, :action_code, :metadata_json
            )')->execute([
                'player_id' => $playerId,
                'attribute_code' => $code,
                'level_before' => (int) ($row['level'] ?? 1),
                'level_after' => (int) ($row['level'] ?? 1),
                'source_type' => 'attribute_allocate',
                'action_code' => 'allocate_point',
                'metadata_json' => json_encode([
                    'points' => $points,
                    'allocated_points' => $allocated,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
        }

        $mapped = $this->mapAttributeRow([
            'attribute_code' => $code,
            'level' => (int) ($row['level'] ?? 1),
            'xp' => (int) ($row['xp'] ?? 0),
            'base_value' => (float) ($row['base_value'] ?? $definition['base']),
            'allocated_points' => $allocated,
        ], true);

        return [
            'updated' => true,
            'attribute' => $mapped,
            'unspent_attribute_points' => $this->unspentPoints($playerId),
            'attributes' => $this->listForPlayer($playerId),
        ];
    }

    public const RESET_BASE_GOLD_COST = 150;

    public function resetCount(int $playerId): int
    {
        if (!$this->columnExists('players', 'attribute_reset_count')) {
            return 0;
        }

        $stmt = $this->pdo()->prepare('SELECT attribute_reset_count FROM players WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $playerId]);

        return max(0, (int) $stmt->fetchColumn());
    }

    public function nextResetGoldCost(int $playerId): int
    {
        $count = $this->resetCount($playerId);
        // 150, 300, 600, 1200... cap no expoente 8 (= 38400).
        return (int) (self::RESET_BASE_GOLD_COST * (2 ** min($count, 8)));
    }

    public function resetAllocated(int $playerId): array
    {
        $this->ensureDefaults($playerId);
        if (!$this->columnExists('players', 'unspent_attribute_points')
            || !$this->columnExists('player_attributes', 'allocated_points')) {
            throw new RuntimeException('Sistema de pontos indisponivel.');
        }

        $stmt = $this->pdo()->prepare('SELECT id, attribute_code, allocated_points, level
            FROM player_attributes
            WHERE player_id = :player_id' . $this->lockClause());
        $stmt->execute(['player_id' => $playerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $refunded = 0;
        foreach ($rows as $row) {
            $code = strtolower(trim((string) ($row['attribute_code'] ?? '')));
            $definition = self::ATTRIBUTES[$code] ?? null;
            if ($definition === null || ($definition['mode'] ?? '') !== 'combat') {
                continue;
            }

            $allocated = max(0, (int) ($row['allocated_points'] ?? 0));
            if ($allocated <= 0) {
                continue;
            }

            $this->pdo()->prepare('UPDATE player_attributes
                SET allocated_points = 0, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id')->execute([
                'id' => (int) $row['id'],
            ]);

            $refunded += $allocated;

            if ($this->tableExists('player_attribute_events')) {
                $this->pdo()->prepare('INSERT INTO player_attribute_events (
                    player_id, attribute_code, xp_delta, level_before, level_after, source_type, source_id, action_code, metadata_json
                ) VALUES (
                    :player_id, :attribute_code, 0, :level_before, :level_after, :source_type, NULL, :action_code, :metadata_json
                )')->execute([
                    'player_id' => $playerId,
                    'attribute_code' => $code,
                    'level_before' => (int) ($row['level'] ?? 1),
                    'level_after' => (int) ($row['level'] ?? 1),
                    'source_type' => 'attribute_reset',
                    'action_code' => 'reset_points',
                    'metadata_json' => json_encode([
                        'refunded_points' => $allocated,
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
            }
        }

        if ($refunded <= 0) {
            return [
                'updated' => false,
                'refunded_points' => 0,
                'gold_cost' => 0,
                'attribute_reset_count' => $this->resetCount($playerId),
                'next_reset_gold_cost' => $this->nextResetGoldCost($playerId),
                'unspent_attribute_points' => $this->unspentPoints($playerId),
                'attributes' => $this->listForPlayer($playerId),
            ];
        }

        $goldCost = $this->nextResetGoldCost($playerId);
        if ($goldCost > 0 && $this->tableExists('player_currency_wallets')) {
            (new \App\Game\Market\Services\PlayerCurrencyService($this->pdo()))->debit(
                $playerId,
                'gold',
                $goldCost,
                'ATTRIBUTE_RESET_FEE',
                'player',
                (string) $playerId,
                ['refunded_points' => $refunded]
            );
        } elseif ($goldCost > 0) {
            $goldCost = 0;
        }

        if ($this->columnExists('players', 'attribute_reset_count')) {
            $this->pdo()->prepare('UPDATE players
                SET unspent_attribute_points = unspent_attribute_points + :points,
                    attribute_reset_count = attribute_reset_count + 1,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id')->execute([
                'id' => $playerId,
                'points' => $refunded,
            ]);
        } else {
            $this->pdo()->prepare('UPDATE players
                SET unspent_attribute_points = unspent_attribute_points + :points,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id')->execute([
                'id' => $playerId,
                'points' => $refunded,
            ]);
        }

        return [
            'updated' => true,
            'refunded_points' => $refunded,
            'gold_cost' => $goldCost,
            'attribute_reset_count' => $this->resetCount($playerId),
            'next_reset_gold_cost' => $this->nextResetGoldCost($playerId),
            'unspent_attribute_points' => $this->unspentPoints($playerId),
            'attributes' => $this->listForPlayer($playerId),
        ];
    }

    public function grantXp(int $playerId, string $attributeCode, int $xpDelta, string $sourceType, ?string $sourceId = null, ?string $actionCode = null, ?array $metadata = null): array
    {
        $this->ensureDefaults($playerId);
        $code = strtolower(trim($attributeCode));
        $definition = self::ATTRIBUTES[$code] ?? null;
        if ($definition === null || $xpDelta <= 0 || !$this->tableExists('player_attributes')) {
            return ['updated' => false];
        }

        // Combate sobe por pontos; skills sobem por uso/EXP.
        if (($definition['mode'] ?? '') === 'combat') {
            return ['updated' => false, 'skipped' => 'combat_uses_points'];
        }

        $stmt = $this->pdo()->prepare('SELECT * FROM player_attributes WHERE player_id = :player_id AND attribute_code = :attribute_code LIMIT 1' . $this->lockClause());
        $stmt->execute([
            'player_id' => $playerId,
            'attribute_code' => $code,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['updated' => false];
        }

        $levelBefore = (int) $row['level'];
        $level = $levelBefore;
        $xp = (int) $row['xp'] + $xpDelta;
        while ($xp >= $this->xpForNextLevel($level)) {
            $xp -= $this->xpForNextLevel($level);
            $level++;
        }

        $this->pdo()->prepare('UPDATE player_attributes
            SET level = :level, xp = :xp, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id')->execute([
            'id' => (int) $row['id'],
            'level' => $level,
            'xp' => $xp,
        ]);

        if ($this->tableExists('player_attribute_events')) {
            $this->pdo()->prepare('INSERT INTO player_attribute_events (
                player_id, attribute_code, xp_delta, level_before, level_after, source_type, source_id, action_code, metadata_json
            ) VALUES (
                :player_id, :attribute_code, :xp_delta, :level_before, :level_after, :source_type, :source_id, :action_code, :metadata_json
            )')->execute([
                'player_id' => $playerId,
                'attribute_code' => $code,
                'xp_delta' => $xpDelta,
                'level_before' => $levelBefore,
                'level_after' => $level,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'action_code' => $actionCode,
                'metadata_json' => $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
            ]);
        }

        return [
            'updated' => true,
            'attribute_code' => $code,
            'level_before' => $levelBefore,
            'level_after' => $level,
            'xp' => $xp,
            'xp_next' => $this->xpForNextLevel($level),
        ];
    }

    /** @param array<string, mixed> $row */
    private function mapAttributeRow(array $row, bool $hasAllocated): array
    {
        $code = (string) $row['attribute_code'];
        $definition = self::ATTRIBUTES[$code] ?? [
            'name' => $code,
            'base' => 0.0,
            'growth' => 1.0,
            'icon' => strtoupper(substr($code, 0, 3)),
            'mode' => 'skill',
        ];
        $level = max(1, (int) ($row['level'] ?? 1));
        $xp = max(0, (int) ($row['xp'] ?? 0));
        $xpNext = $this->xpForNextLevel($level);
        $allocated = $hasAllocated ? max(0, (int) ($row['allocated_points'] ?? 0)) : 0;
        $base = (float) ($row['base_value'] ?? $definition['base']);
        $mode = (string) ($definition['mode'] ?? 'skill');
        $value = $mode === 'combat'
            ? round($base + $allocated, 2)
            : round($base + (($level - 1) * (float) $definition['growth']), 2);

        return [
            'code' => $code,
            'name' => $definition['name'],
            'icon' => $definition['icon'],
            'mode' => $mode,
            'allocatable' => $mode === 'combat',
            'level' => $level,
            'xp' => $xp,
            'xp_next' => $xpNext,
            'xp_ratio' => $xpNext > 0 ? round(min(1, $xp / $xpNext), 4) : 0.0,
            'base_value' => $base,
            'allocated_points' => $allocated,
            'value' => $value,
        ];
    }

    private function xpForNextLevel(int $level): int
    {
        return 100 + (($level - 1) * 35);
    }

    /** @return array<int, array<string, mixed>> */
    private function fallbackAttributes(): array
    {
        return array_map(fn (string $code, array $definition): array => [
            'code' => $code,
            'name' => $definition['name'],
            'icon' => $definition['icon'],
            'mode' => $definition['mode'],
            'allocatable' => $definition['mode'] === 'combat',
            'level' => 1,
            'xp' => 0,
            'xp_next' => 100,
            'xp_ratio' => 0.0,
            'base_value' => $definition['base'],
            'allocated_points' => 0,
            'value' => $definition['base'],
        ], array_keys(self::ATTRIBUTES), array_values(self::ATTRIBUTES));
    }

    private function lockClause(): string
    {
        return $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
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

    private function columnExists(string $table, string $column): bool
    {
        $driver = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->pdo()->query('PRAGMA table_info(' . $table . ')');
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (($row['name'] ?? '') === $column) {
                    return true;
                }
            }

            return false;
        }

        $stmt = $this->pdo()->prepare(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column
             LIMIT 1'
        );
        $stmt->execute(['table' => $table, 'column' => $column]);

        return (bool) $stmt->fetchColumn();
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
