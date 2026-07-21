<?php

namespace App\Game\Expeditions\Services;

use App\Support\DB;
use PDO;

class ExpeditionStateService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function activeForPlayer(int $playerId): ?array
    {
        if (!$this->tableExists('expedition_instances')) {
            return null;
        }

        $stmt = $this->pdo()->prepare('SELECT *
            FROM expedition_instances
            WHERE player_id = :player_id
                AND status = :status
                AND ends_at >= :now
            ORDER BY ends_at DESC, id DESC
            LIMIT 1');
        $stmt->execute([
            'player_id' => $playerId,
            'status' => 'active',
            'now' => date('Y-m-d H:i:s'),
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function hasActiveForPlayer(int $playerId): bool
    {
        return $this->activeForPlayer($playerId) !== null;
    }

    public function expireFinishedForPlayer(int $playerId): void
    {
        if (!$this->tableExists('expedition_instances')) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo()->prepare("SELECT *
            FROM expedition_instances
            WHERE player_id = :player_id
                AND status = 'active'
                AND ends_at < :now
            ORDER BY id ASC");
        $stmt->execute([
            'player_id' => $playerId,
            'now' => $now,
        ]);

        $offline = new ExpeditionOfflineSimulationService($this->pdo());
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $expedition) {
            if (!is_array($expedition)) {
                continue;
            }

            $originalEndsAt = (string) ($expedition['ends_at'] ?? $now);
            // Mantem a expedicao ativa por alguns minutos para a simulacao offline poder tickar.
            $this->pdo()->prepare('UPDATE expedition_instances SET ends_at = :ends_at, updated_at = :updated_at WHERE id = :id AND status = \'active\'')
                ->execute([
                    'id' => (int) $expedition['id'],
                    'ends_at' => date('Y-m-d H:i:s', time() + 180),
                    'updated_at' => $now,
                ]);

            $expedition['ends_at'] = $originalEndsAt;
            try {
                $offline->simulateExpiredExpedition($expedition, $playerId);
            } catch (\Throwable) {
                // Nao bloqueia o encerramento da expedicao.
            }

            $this->pdo()->prepare("UPDATE expedition_instances
                SET status = 'finished',
                    ends_at = :ends_at,
                    ended_at = :ended_at,
                    updated_at = :updated_at
                WHERE id = :id AND player_id = :player_id AND status = 'active'")->execute([
                'id' => (int) $expedition['id'],
                'player_id' => $playerId,
                'ends_at' => $originalEndsAt,
                'ended_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function activeForPlayerInBiome(int $playerId, string $biomeCode): ?array
    {
        $active = $this->activeForPlayer($playerId);
        if ($active === null) {
            return null;
        }

        $metadata = $this->parseJson($active['metadata_json'] ?? null);
        $activeBiome = strtolower(trim((string) ($metadata['biome_code'] ?? '')));
        $expectedBiome = strtolower(trim($biomeCode));

        if ($activeBiome === '' || $activeBiome !== $expectedBiome) {
            return null;
        }

        return $active;
    }

    /** @return array<string, mixed>|null */
    public function failActiveForPlayer(int $playerId, string $reason = 'player_defeated'): ?array
    {
        $active = $this->activeForPlayer($playerId);
        if ($active === null) {
            return null;
        }

        $now = date('Y-m-d H:i:s');
        $metadata = $this->parseJson($active['metadata_json'] ?? null);
        $metadata['failure'] = [
            'reason' => $reason,
            'ended_at' => $now,
            'message' => $reason === 'arena_defeat'
                ? 'Voce foi derrotado na arena e a expedicao foi encerrada.'
                : 'A expedicao foi encerrada antes do previsto.',
        ];

        $this->pdo()->prepare("UPDATE expedition_instances
            SET status = 'failed',
                ended_at = :ended_at,
                updated_at = :updated_at,
                metadata_json = :metadata_json
            WHERE id = :id AND player_id = :player_id AND status = 'active'")->execute([
            'id' => (int) $active['id'],
            'player_id' => $playerId,
            'ended_at' => $now,
            'updated_at' => $now,
            'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);

        $stmt = $this->pdo()->prepare('SELECT * FROM expedition_instances WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $active['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function lastFailedForPlayerInBiome(int $playerId, string $biomeCode): ?array
    {
        if (!$this->tableExists('expedition_instances')) {
            return null;
        }

        $expectedBiome = strtolower(trim($biomeCode));
        $stmt = $this->pdo()->prepare("SELECT *
            FROM expedition_instances
            WHERE player_id = :player_id
                AND status = 'failed'
            ORDER BY ended_at DESC, id DESC
            LIMIT 5");
        $stmt->execute(['player_id' => $playerId]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $metadata = $this->parseJson($row['metadata_json'] ?? null);
            $rowBiome = strtolower(trim((string) ($metadata['biome_code'] ?? '')));
            if ($rowBiome !== $expectedBiome) {
                continue;
            }

            $failure = is_array($metadata['failure'] ?? null) ? $metadata['failure'] : [];

            return [
                'public_id' => (string) ($row['public_id'] ?? ''),
                'reason' => (string) ($failure['reason'] ?? 'unknown'),
                'message' => (string) ($failure['message'] ?? 'A ultima expedicao falhou.'),
                'ended_at' => $row['ended_at'] ?? ($failure['ended_at'] ?? null),
            ];
        }

        return null;
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
