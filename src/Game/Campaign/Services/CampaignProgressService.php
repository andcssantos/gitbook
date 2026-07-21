<?php

namespace App\Game\Campaign\Services;

use App\Support\DB;
use PDO;

class CampaignProgressService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function touchPlayed(int $playerId, int $nodeId, int $highestWave = 0): void
    {
        $this->upsert($playerId, $nodeId, [
            'last_played_at' => date('Y-m-d H:i:s'),
            'highest_wave' => $highestWave,
        ]);
    }

    public function markCleared(int $playerId, int $nodeId, int $waveCount): void
    {
        $this->markClearedWithTime($playerId, $nodeId, $waveCount, null);
    }

    /**
     * @return array{best_clear_ms:int|null,is_best:bool}
     */
    public function markClearedWithTime(int $playerId, int $nodeId, int $waveCount, ?int $durationMs, array $runStats = []): array
    {
        $existing = $this->row($playerId, $nodeId);
        $clearCount = (int) ($existing['clear_count'] ?? 0) + 1;
        $firstCleared = $existing['first_cleared_at'] ?? date('Y-m-d H:i:s');
        $highest = max($waveCount, (int) ($existing['highest_wave'] ?? 0));
        $flags = $this->parseFlags($existing['flags_json'] ?? null);
        $previousBest = isset($flags['best_clear_ms']) ? (int) $flags['best_clear_ms'] : null;
        $isBest = false;
        if ($durationMs !== null && $durationMs > 0) {
            if ($previousBest === null || $durationMs < $previousBest) {
                $flags['best_clear_ms'] = $durationMs;
                $isBest = true;
            }
            $history = array_values(array_filter((array) ($flags['clear_history'] ?? []), 'is_array'));
            array_unshift($history, [
                'duration_ms' => $durationMs,
                'duration_label' => $this->formatDuration($durationMs),
                'at' => date('c'),
                'kills' => (int) ($runStats['kills'] ?? 0),
                'gold' => (int) ($runStats['gold'] ?? 0),
                'exploration_xp' => (int) ($runStats['exploration_xp'] ?? 0),
                'is_best' => $isBest,
            ]);
            $flags['clear_history'] = array_slice($history, 0, 12);
        }

        $this->upsert($playerId, $nodeId, [
            'status' => 'cleared',
            'highest_wave' => $highest,
            'clear_count' => $clearCount,
            'first_cleared_at' => $firstCleared,
            'last_played_at' => date('Y-m-d H:i:s'),
            'flags_json' => $flags,
        ]);

        return [
            'best_clear_ms' => isset($flags['best_clear_ms']) ? (int) $flags['best_clear_ms'] : $previousBest,
            'is_best' => $isBest,
        ];
    }

    /**
     * Registra monstros/itens vistos na campanha (por no; merge global na UI).
     *
     * @param list<string> $monsterCodes
     * @param list<string> $itemCodes
     */
    public function discover(int $playerId, int $nodeId, array $monsterCodes = [], array $itemCodes = []): void
    {
        if ($nodeId < 1) {
            return;
        }
        $monsters = array_values(array_unique(array_filter(array_map('strval', $monsterCodes))));
        $items = array_values(array_unique(array_filter(array_map('strval', $itemCodes))));
        if ($monsters === [] && $items === []) {
            return;
        }

        $existing = $this->row($playerId, $nodeId);
        $flags = $this->parseFlags($existing['flags_json'] ?? null);
        $knownM = array_values(array_unique(array_merge(
            array_map('strval', (array) ($flags['discovered_monsters'] ?? [])),
            $monsters
        )));
        $knownI = array_values(array_unique(array_merge(
            array_map('strval', (array) ($flags['discovered_items'] ?? [])),
            $items
        )));
        $flags['discovered_monsters'] = $knownM;
        $flags['discovered_items'] = $knownI;

        $this->upsert($playerId, $nodeId, [
            'status' => (string) ($existing['status'] ?? 'available'),
            'highest_wave' => (int) ($existing['highest_wave'] ?? 0),
            'clear_count' => (int) ($existing['clear_count'] ?? 0),
            'first_cleared_at' => $existing['first_cleared_at'] ?? null,
            'last_played_at' => date('Y-m-d H:i:s'),
            'flags_json' => $flags,
        ]);
    }

    private function formatDuration(int $ms): string
    {
        $totalSec = max(0, (int) floor($ms / 1000));
        $min = (int) floor($totalSec / 60);
        $sec = $totalSec % 60;

        return sprintf('%d:%02d', $min, $sec);
    }

    /** @return array<string, mixed> */
    private function parseFlags(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
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

    public function updateHighestWave(int $playerId, int $nodeId, int $wave): void
    {
        $existing = $this->row($playerId, $nodeId);
        $highest = max($wave, (int) ($existing['highest_wave'] ?? 0));
        $this->upsert($playerId, $nodeId, [
            'highest_wave' => $highest,
            'last_played_at' => date('Y-m-d H:i:s'),
            'status' => (string) ($existing['status'] ?? 'available'),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function row(int $playerId, int $nodeId): ?array
    {
        if (!$this->tableExists('campaign_node_progress')) {
            return null;
        }

        $stmt = $this->pdo()->prepare('SELECT * FROM campaign_node_progress WHERE player_id = :player_id AND node_id = :node_id LIMIT 1');
        $stmt->execute(['player_id' => $playerId, 'node_id' => $nodeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $flags
     */
    public function mergeFlags(int $playerId, int $nodeId, array $flags): void
    {
        $existing = $this->row($playerId, $nodeId);
        $current = $this->parseFlags($existing['flags_json'] ?? null);
        foreach ($flags as $key => $value) {
            $current[(string) $key] = $value;
        }
        $this->upsert($playerId, $nodeId, [
            'status' => (string) ($existing['status'] ?? 'available'),
            'highest_wave' => (int) ($existing['highest_wave'] ?? 0),
            'clear_count' => (int) ($existing['clear_count'] ?? 0),
            'first_cleared_at' => $existing['first_cleared_at'] ?? null,
            'last_played_at' => date('Y-m-d H:i:s'),
            'flags_json' => $current,
        ]);
    }

    /** @param array<string, mixed> $fields */
    private function upsert(int $playerId, int $nodeId, array $fields): void
    {
        if (!$this->tableExists('campaign_node_progress')) {
            return;
        }

        $existing = $this->row($playerId, $nodeId);
        if ($existing === null) {
            $this->pdo()->prepare('INSERT INTO campaign_node_progress (
                player_id, node_id, status, highest_wave, clear_count, flags_json, first_cleared_at, last_played_at
            ) VALUES (
                :player_id, :node_id, :status, :highest_wave, :clear_count, :flags_json, :first_cleared_at, :last_played_at
            )')->execute([
                'player_id' => $playerId,
                'node_id' => $nodeId,
                'status' => (string) ($fields['status'] ?? 'available'),
                'highest_wave' => (int) ($fields['highest_wave'] ?? 0),
                'clear_count' => (int) ($fields['clear_count'] ?? 0),
                'flags_json' => isset($fields['flags_json']) ? json_encode($fields['flags_json'], JSON_THROW_ON_ERROR) : null,
                'first_cleared_at' => $fields['first_cleared_at'] ?? null,
                'last_played_at' => $fields['last_played_at'] ?? date('Y-m-d H:i:s'),
            ]);
            return;
        }

        $status = (string) ($fields['status'] ?? $existing['status'] ?? 'available');
        if (($existing['status'] ?? '') === 'cleared') {
            $status = 'cleared';
        }

        $flagsJson = $existing['flags_json'] ?? null;
        if (array_key_exists('flags_json', $fields)) {
            $flagsJson = json_encode($fields['flags_json'], JSON_THROW_ON_ERROR);
        }

        $this->pdo()->prepare('UPDATE campaign_node_progress SET
            status = :status,
            highest_wave = :highest_wave,
            clear_count = :clear_count,
            flags_json = :flags_json,
            first_cleared_at = COALESCE(first_cleared_at, :first_cleared_at),
            last_played_at = :last_played_at,
            updated_at = CURRENT_TIMESTAMP
            WHERE player_id = :player_id AND node_id = :node_id
        ')->execute([
            'status' => $status,
            'highest_wave' => max((int) ($existing['highest_wave'] ?? 0), (int) ($fields['highest_wave'] ?? 0)),
            'clear_count' => (int) ($fields['clear_count'] ?? $existing['clear_count'] ?? 0),
            'flags_json' => $flagsJson,
            'first_cleared_at' => $fields['first_cleared_at'] ?? $existing['first_cleared_at'] ?? null,
            'last_played_at' => $fields['last_played_at'] ?? date('Y-m-d H:i:s'),
            'player_id' => $playerId,
            'node_id' => $nodeId,
        ]);
    }

    private function tableExists(string $table): bool
    {
        try {
            $driver = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $this->pdo()->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name");
                $stmt->execute(['name' => $table]);
                return (bool) $stmt->fetchColumn();
            }
            $stmt = $this->pdo()->query('SHOW TABLES LIKE ' . $this->pdo()->quote($table));
            return (bool) ($stmt && $stmt->fetchColumn());
        } catch (\Throwable) {
            return false;
        }
    }

    private function pdo(): PDO
    {
        return $this->pdo ??= DB::pdo();
    }
}
