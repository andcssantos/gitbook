<?php

namespace App\Game\Expeditions\Services;

use App\Game\Market\Services\PlayerCurrencyService;
use App\Game\Player\Services\PlayerAttributeService;
use App\Support\DB;
use PDO;

class ExpeditionCompletionService
{
    public function __construct(
        private ?PDO $pdo = null,
        private ?ExpeditionStateService $state = null,
        private ?PlayerAttributeService $attributes = null,
        private ?PlayerCurrencyService $currencies = null
    ) {
        $this->state ??= new ExpeditionStateService($this->pdo);
        $this->attributes ??= new PlayerAttributeService($this->pdo);
        $this->currencies ??= new PlayerCurrencyService($this->pdo);
    }

    /** @return array<string, mixed> */
    public function pendingForPlayer(int $playerId): ?array
    {
        $expedition = $this->findClaimableExpedition($playerId);
        if ($expedition === null) {
            return null;
        }

        return $this->mapPending($expedition);
    }

    /** @return array<string, mixed> */
    public function claim(int $playerId): array
    {
        $expedition = $this->findClaimableExpedition($playerId);
        if ($expedition === null) {
            throw new \RuntimeException('No expedition is ready to claim.');
        }

        $stats = $this->interactionStats(
            $playerId,
            (string) ($expedition['started_at'] ?? ''),
            (string) ($expedition['ends_at'] ?? '')
        );

        $metadata = $this->parseJson($expedition['metadata_json'] ?? null);
        $offlineCombat = is_array($metadata['offline_combat'] ?? null) ? $metadata['offline_combat'] : [];
        $rewards = $this->buildRewards($stats, $offlineCombat);
        $this->grantRewards($playerId, (string) $expedition['public_id'], $rewards);

        $now = date('Y-m-d H:i:s');
        $metadata['completion'] = [
            'claimed_at' => $now,
            'stats' => $stats,
            'rewards' => $rewards,
            'offline_combat' => $offlineCombat,
        ];

        $this->pdo()->prepare("UPDATE expedition_instances
            SET status = 'completed',
                ended_at = :ended_at,
                metadata_json = :metadata_json,
                updated_at = :updated_at
            WHERE id = :id AND player_id = :player_id AND status = 'finished'")->execute([
            'id' => (int) $expedition['id'],
            'player_id' => $playerId,
            'ended_at' => $now,
            'updated_at' => $now,
            'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);

        return [
            'claimed' => true,
            'expedition_public_id' => (string) $expedition['public_id'],
            'stats' => $stats,
            'rewards' => $rewards,
        ];
    }

    /** @return array<string, mixed> */
    private function buildRewards(array $stats, array $offlineCombat = []): array
    {
        $interactionCount = (int) ($stats['interaction_count'] ?? 0);
        $collectCount = (int) ($stats['collect_count'] ?? 0);
        $analyzeCount = (int) ($stats['analyze_count'] ?? 0);

        $explorationXp = 25 + ($interactionCount * 8) + ($analyzeCount * 4);
        $gold = 10.0 + ($collectCount * 5);

        // Offline ja concedeu ouro/XP via kills simulados; aqui so mostramos o resumo.
        $offlineKills = (int) ($offlineCombat['kills'] ?? 0);
        $offlineGold = (int) ($offlineCombat['gold'] ?? 0);
        $offlineXp = (int) ($offlineCombat['exploration_xp'] ?? 0);

        return [
            'exploration_xp' => $explorationXp,
            'gold' => round($gold, 2),
            'offline_kills' => $offlineKills,
            'offline_gold_already_granted' => $offlineGold,
            'offline_xp_already_granted' => $offlineXp,
            'offline_simulated' => (bool) ($offlineCombat['simulated'] ?? false),
        ];
    }

    /** @param array<string, mixed> $rewards */
    private function grantRewards(int $playerId, string $expeditionPublicId, array $rewards): void
    {
        if ((int) ($rewards['exploration_xp'] ?? 0) > 0) {
            $this->attributes->grantXp(
                $playerId,
                'exploration',
                (int) $rewards['exploration_xp'],
                'expedition_completion',
                $expeditionPublicId,
                'claim_rewards'
            );
        }

        if ((float) ($rewards['gold'] ?? 0) > 0 && $this->currencyWalletsAvailable()) {
            $this->currencies->credit(
                $playerId,
                'gold',
                (float) $rewards['gold'],
                'expedition_completion',
                'expedition',
                $expeditionPublicId,
                ['reward_type' => 'completion_bonus']
            );
        }
    }

    private function currencyWalletsAvailable(): bool
    {
        $driver = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->pdo()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'player_currency_wallets' LIMIT 1");
            $stmt->execute();

            return (bool) $stmt->fetchColumn();
        }

        $stmt = $this->pdo()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1');
        $stmt->execute(['table' => 'player_currency_wallets']);

        return (bool) $stmt->fetchColumn();
    }

    /** @return array<string, int> */
    private function interactionStats(int $playerId, string $startedAt, string $endsAt): array
    {
        if (!$this->tableExists('exploration_interaction_events') || $startedAt === '' || $endsAt === '') {
            return [
                'interaction_count' => 0,
                'analyze_count' => 0,
                'collect_count' => 0,
            ];
        }

        $stmt = $this->pdo()->prepare('SELECT action_code
            FROM exploration_interaction_events
            WHERE player_id = :player_id
                AND created_at >= :started_at
                AND created_at <= :ends_at');
        $stmt->execute([
            'player_id' => $playerId,
            'started_at' => $startedAt,
            'ends_at' => $endsAt,
        ]);

        $interactionCount = 0;
        $analyzeCount = 0;
        $collectCount = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $interactionCount++;
            $actionCode = (string) ($row['action_code'] ?? '');
            if ($actionCode === 'analyze_magnifier') {
                $analyzeCount++;
                continue;
            }

            $collectCount++;
        }

        return [
            'interaction_count' => $interactionCount,
            'analyze_count' => $analyzeCount,
            'collect_count' => $collectCount,
        ];
    }

    private function findClaimableExpedition(int $playerId): ?array
    {
        if (!$this->tableExists('expedition_instances')) {
            return null;
        }

        $stmt = $this->pdo()->prepare("SELECT *
            FROM expedition_instances
            WHERE player_id = :player_id
                AND status = 'finished'
            ORDER BY ends_at DESC, id DESC
            LIMIT 1");
        $stmt->execute(['player_id' => $playerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $expedition */
    private function mapPending(array $expedition): array
    {
        $metadata = $this->parseJson($expedition['metadata_json'] ?? null);
        $stats = $this->interactionStats(
            (int) $expedition['player_id'],
            (string) ($expedition['started_at'] ?? ''),
            (string) ($expedition['ends_at'] ?? '')
        );
        $offlineCombat = is_array($metadata['offline_combat'] ?? null) ? $metadata['offline_combat'] : [];

        return [
            'public_id' => (string) ($expedition['public_id'] ?? ''),
            'biome_code' => (string) ($metadata['biome_code'] ?? ''),
            'biome_name' => (string) ($metadata['biome_name'] ?? ''),
            'started_at' => $expedition['started_at'] ?? null,
            'ends_at' => $expedition['ends_at'] ?? null,
            'status' => 'finished',
            'claimable' => true,
            'stats' => $stats,
            'offline_combat' => $offlineCombat,
            'preview_rewards' => $this->buildRewards($stats, $offlineCombat),
        ];
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
