<?php

namespace App\Game\Tools\Services;

use App\Support\DB;
use PDO;

class ToolMasteryService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function ensureForItem(int $playerId, int $itemInstanceId): ?array
    {
        if (!$this->tableExists('tool_mastery')) {
            return null;
        }

        $item = $this->toolItem($playerId, $itemInstanceId);
        if ($item === null) {
            return null;
        }

        $toolType = $this->toolType($item);
        if ($toolType === '') {
            return null;
        }

        $driver = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $sql = 'INSERT IGNORE INTO tool_mastery (player_id, item_instance_id, tool_type)
                VALUES (:player_id, :item_instance_id, :tool_type)';
        } else {
            $sql = 'INSERT INTO tool_mastery (player_id, item_instance_id, tool_type)
                VALUES (:player_id, :item_instance_id, :tool_type)
                ON CONFLICT(player_id, item_instance_id) DO NOTHING';
        }

        $this->pdo()->prepare($sql)->execute([
            'player_id' => $playerId,
            'item_instance_id' => $itemInstanceId,
            'tool_type' => $toolType,
        ]);

        return $this->findForItem($playerId, $itemInstanceId);
    }

    public function findForItem(int $playerId, int $itemInstanceId): ?array
    {
        if (!$this->tableExists('tool_mastery')) {
            return null;
        }

        $stmt = $this->pdo()->prepare('SELECT *
            FROM tool_mastery
            WHERE player_id = :player_id AND item_instance_id = :item_instance_id
            LIMIT 1');
        $stmt->execute([
            'player_id' => $playerId,
            'item_instance_id' => $itemInstanceId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->mapMastery($row) : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function listForPlayer(int $playerId): array
    {
        if (!$this->tableExists('tool_mastery')) {
            return [];
        }

        $stmt = $this->pdo()->prepare('SELECT tm.*, ii.public_id AS item_public_id, id.code AS definition_code, COALESCE(ii.item_name, id.name) AS item_name
            FROM tool_mastery tm
            INNER JOIN item_instances ii ON ii.id = tm.item_instance_id
            INNER JOIN item_definitions id ON id.id = ii.item_definition_id
            WHERE tm.player_id = :player_id AND ii.owner_player_id = :player_id
            ORDER BY tm.tool_type ASC, tm.level DESC, tm.id ASC');
        $stmt->execute(['player_id' => $playerId]);

        return array_map(fn (array $row): array => $this->mapMastery($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function grantXp(int $playerId, int $itemInstanceId, int $xpDelta, string $sourceType, ?string $sourceId = null, ?string $actionCode = null, ?array $metadata = null): array
    {
        if ($xpDelta <= 0 || !$this->tableExists('tool_mastery')) {
            return ['updated' => false];
        }

        $this->ensureForItem($playerId, $itemInstanceId);

        $stmt = $this->pdo()->prepare('SELECT *
            FROM tool_mastery
            WHERE player_id = :player_id AND item_instance_id = :item_instance_id
            LIMIT 1' . $this->lockClause());
        $stmt->execute([
            'player_id' => $playerId,
            'item_instance_id' => $itemInstanceId,
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

        $this->pdo()->prepare('UPDATE tool_mastery
            SET level = :level,
                xp = :xp,
                uses_count = uses_count + 1,
                last_used_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id')->execute([
            'id' => (int) $row['id'],
            'level' => $level,
            'xp' => $xp,
        ]);

        if ($this->tableExists('tool_mastery_events')) {
            $this->pdo()->prepare('INSERT INTO tool_mastery_events (
                player_id, item_instance_id, tool_type, xp_delta, level_before, level_after, source_type, source_id, action_code, metadata_json
            ) VALUES (
                :player_id, :item_instance_id, :tool_type, :xp_delta, :level_before, :level_after, :source_type, :source_id, :action_code, :metadata_json
            )')->execute([
                'player_id' => $playerId,
                'item_instance_id' => $itemInstanceId,
                'tool_type' => (string) $row['tool_type'],
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
            'item_instance_id' => $itemInstanceId,
            'tool_type' => (string) $row['tool_type'],
            'level_before' => $levelBefore,
            'level_after' => $level,
            'xp' => $xp,
            'xp_next' => $this->xpForNextLevel($level),
        ];
    }

    public function xpForNextLevel(int $level): int
    {
        return 80 + (($level - 1) * 45);
    }

    private function toolItem(int $playerId, int $itemInstanceId): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT ii.*, id.base_config, ic.code AS category_code
            FROM item_instances ii
            INNER JOIN item_definitions id ON id.id = ii.item_definition_id
            INNER JOIN item_categories ic ON ic.id = id.category_id
            WHERE ii.id = :item_instance_id AND ii.owner_player_id = :player_id
            LIMIT 1');
        $stmt->execute([
            'item_instance_id' => $itemInstanceId,
            'player_id' => $playerId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function toolType(array $item): string
    {
        if (($item['category_code'] ?? null) !== 'tool') {
            return '';
        }

        $config = $this->parseBaseConfig($item['base_config'] ?? null);
        $toolType = strtolower(trim((string) ($config['tool_type'] ?? $config['tool_family'] ?? '')));

        return preg_replace('/[^a-z0-9_]/', '', $toolType) ?: '';
    }

    private function mapMastery(array $row): array
    {
        $level = max(1, (int) $row['level']);
        $xp = max(0, (int) $row['xp']);
        $xpNext = $this->xpForNextLevel($level);

        return [
            'item_instance_id' => (int) $row['item_instance_id'],
            'item_public_id' => isset($row['item_public_id']) ? (string) $row['item_public_id'] : null,
            'definition_code' => isset($row['definition_code']) ? (string) $row['definition_code'] : null,
            'item_name' => isset($row['item_name']) ? (string) $row['item_name'] : null,
            'tool_type' => (string) $row['tool_type'],
            'level' => $level,
            'xp' => $xp,
            'xp_next' => $xpNext,
            'xp_ratio' => $xpNext > 0 ? round(min(1, $xp / $xpNext), 4) : 0.0,
            'uses_count' => (int) $row['uses_count'],
            'last_used_at' => $row['last_used_at'] !== null ? (string) $row['last_used_at'] : null,
        ];
    }

    private function parseBaseConfig(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
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

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
