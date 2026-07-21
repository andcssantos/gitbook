<?php

namespace App\Game\Expeditions\Services;

use App\Support\DB;
use PDO;

/**
 * Simula combate idle enquanto a expedicao estava ativa e o jogador ausente.
 * Rodado no momento em que a expedicao expira (active -> finished).
 */
class ExpeditionOfflineSimulationService
{
    private const EFFICIENCY = 0.78;
    private const MAX_TICKS = 48;
    private const TICK_SECONDS = 0.95;

    public function __construct(
        private ?PDO $pdo = null,
        private ?ExpeditionArenaCombatService $combat = null,
        private ?ExpeditionArenaCatalogService $catalog = null
    ) {
        $this->combat ??= new ExpeditionArenaCombatService($this->pdo);
        $this->catalog ??= new ExpeditionArenaCatalogService($this->pdo);
    }

    /** @param array<string, mixed> $expedition */
    /** @return array<string, mixed>|null */
    public function simulateExpiredExpedition(array $expedition, int $playerId): ?array
    {
        $metadata = $this->parseJson($expedition['metadata_json'] ?? null);
        if (isset($metadata['offline_combat']) && is_array($metadata['offline_combat'])) {
            return $metadata['offline_combat'];
        }

        $biomeCode = (string) ($metadata['biome_code'] ?? 'bosque_inicial');
        $combatMeta = is_array($metadata['combat'] ?? null) ? $metadata['combat'] : [];
        $endsAt = strtotime((string) ($expedition['ends_at'] ?? '')) ?: time();
        $lastTick = $this->parseTickTimestamp((string) ($combatMeta['last_tick_at'] ?? ''));
        $startedAt = strtotime((string) ($expedition['started_at'] ?? '')) ?: ($endsAt - 60);
        $from = $lastTick > 0 ? $lastTick : (float) $startedAt;
        $elapsed = max(0.0, min(1800.0, $endsAt - $from));
        if ($elapsed < 8.0) {
            $summary = [
                'simulated' => false,
                'reason' => 'too_short',
                'elapsed_seconds' => round($elapsed, 1),
                'kills' => 0,
                'gold' => 0,
                'exploration_xp' => 0,
                'efficiency' => self::EFFICIENCY,
            ];
            $this->persistOfflineSummary((int) $expedition['id'], $metadata, $summary);

            return $summary;
        }

        $ticks = (int) min(self::MAX_TICKS, floor(($elapsed / self::TICK_SECONDS) * self::EFFICIENCY));
        $kills = 0;
        $gold = 0;
        $xp = 0;
        $eventsSample = [];

        for ($i = 0; $i < $ticks; $i++) {
            try {
                // Empurra last_tick_at para o passado para o tick processar ~1s de combate.
                $this->bumpLastTickIntoPast((int) $expedition['id'], $metadata, 1.1);
                $result = $this->combat->tick($playerId);
            } catch (\Throwable) {
                break;
            }

            if (($result['player_defeated'] ?? false) === true) {
                $eventsSample[] = 'player_defeated';
                break;
            }

            if (($result['killed'] ?? false) === true) {
                $kills++;
                $gold += (int) ($result['rewards']['gold'] ?? 0);
                $xp += (int) ($result['rewards']['exploration_xp'] ?? 0);
            }

            // Expedicao pode ter falhado no meio.
            $fresh = $this->pdo()->prepare('SELECT status FROM expedition_instances WHERE id = :id LIMIT 1');
            $fresh->execute(['id' => (int) $expedition['id']]);
            $status = (string) $fresh->fetchColumn();
            if ($status !== 'active' && $status !== 'finished') {
                break;
            }
        }

        $summary = [
            'simulated' => true,
            'elapsed_seconds' => round($elapsed, 1),
            'ticks' => $ticks,
            'kills' => $kills,
            'gold' => $gold,
            'exploration_xp' => $xp,
            'efficiency' => self::EFFICIENCY,
            'biome_code' => $biomeCode,
            'boss_name_hint' => (string) (($this->catalog->biome($biomeCode)['name'] ?? $biomeCode)),
        ];
        $this->persistOfflineSummary((int) $expedition['id'], $metadata, $summary);

        return $summary;
    }

    /** @param array<string, mixed> $metadata */
    /** @param array<string, mixed> $summary */
    private function persistOfflineSummary(int $expeditionId, array $metadata, array $summary): void
    {
        $metadata['offline_combat'] = $summary;
        $this->pdo()->prepare('UPDATE expedition_instances SET metadata_json = :metadata_json, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute([
                'id' => $expeditionId,
                'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
    }

    /** @param array<string, mixed> $metadata */
    private function bumpLastTickIntoPast(int $expeditionId, array &$metadata, float $secondsAgo): void
    {
        $combat = is_array($metadata['combat'] ?? null) ? $metadata['combat'] : [];
        $target = microtime(true) - max(0.2, $secondsAgo);
        $micro = sprintf('%06d', (int) (($target - floor($target)) * 1000000));
        $combat['last_tick_at'] = date('Y-m-d H:i:s', (int) floor($target)) . '.' . $micro;
        $metadata['combat'] = $combat;
        $this->pdo()->prepare('UPDATE expedition_instances SET metadata_json = :metadata_json WHERE id = :id')
            ->execute([
                'id' => $expeditionId,
                'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
    }

    private function parseTickTimestamp(string $value): float
    {
        $value = trim($value);
        if ($value === '') {
            return 0.0;
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.(\d+))?$/', $value, $matches) === 1) {
            $base = strtotime($matches[1]);
            if ($base === false) {
                return 0.0;
            }
            $fraction = isset($matches[2]) ? ((float) ('0.' . $matches[2])) : 0.0;

            return (float) $base + $fraction;
        }

        $parsed = strtotime($value);

        return $parsed === false ? 0.0 : (float) $parsed;
    }

    private function parseJson(mixed $value): array
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

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
