<?php

namespace App\Game\Exploration\Services;

use App\Game\Expeditions\Services\ExpeditionCompletionService;
use App\Game\Expeditions\Services\ExpeditionStateService;
use App\Game\Exploration\ExplorationException;
use App\Support\DB;
use PDO;

class ExplorationExpeditionGateService
{
    public function __construct(
        private ?PDO $pdo = null,
        private ?ExpeditionStateService $expeditions = null,
        private ?ExplorationBiomeCatalogService $catalog = null,
        private ?ExpeditionCompletionService $completion = null
    ) {
        $this->expeditions ??= new ExpeditionStateService($this->pdo);
        $this->catalog ??= new ExplorationBiomeCatalogService($this->pdo);
        $this->completion ??= new ExpeditionCompletionService($this->pdo);
    }

    /** @return array<string, mixed> */
    public function biomeRules(string $biomeCode): array
    {
        $biome = $this->catalog->biome($biomeCode);
        if ($biome === null) {
            return [
                'requires_expedition' => false,
                'default_respawn_minutes' => 15,
                'default_duration_minutes' => 2,
            ];
        }

        return [
            'requires_expedition' => (bool) ($biome['requires_expedition'] ?? false),
            'default_respawn_minutes' => (int) ($biome['default_respawn_minutes'] ?? 15),
            'default_duration_minutes' => max(2, (int) ($biome['default_duration_minutes'] ?? 2)),
        ];
    }

    /** @return array<string, mixed> */
    public function expeditionContextForBiome(int $playerId, string $biomeCode): array
    {
        $rules = $this->biomeRules($biomeCode);
        $this->expeditions->expireFinishedForPlayer($playerId);
        $active = $this->expeditions->activeForPlayerInBiome($playerId, $biomeCode);
        $pending = $this->completion->pendingForPlayer($playerId);
        $pendingNormalized = is_array($pending) ? $pending : null;
        // Finished expeditions block a new run globally — surface claim even if the player
        // switched the selected biome pin before claiming.
        $claimable = $pendingNormalized !== null;
        $lastFailure = $active === null
            ? $this->expeditions->lastFailedForPlayerInBiome($playerId, $biomeCode)
            : null;

        return [
            'required' => (bool) ($rules['requires_expedition'] ?? false),
            'active' => $active !== null && !$claimable,
            'biome_code' => $claimable
                ? (string) ($pendingNormalized['biome_code'] ?? $biomeCode)
                : $biomeCode,
            'public_id' => $active['public_id'] ?? ($pendingNormalized['public_id'] ?? null),
            'ends_at' => $active['ends_at'] ?? ($pendingNormalized['ends_at'] ?? null),
            'started_at' => $active['started_at'] ?? ($pendingNormalized['started_at'] ?? null),
            'loot_bonus_active' => $active !== null && !$claimable,
            'default_duration_minutes' => (int) ($rules['default_duration_minutes'] ?? 2),
            'ready_to_claim' => $claimable,
            'claimable' => $claimable,
            'status' => $claimable ? 'finished' : ($active !== null ? 'active' : 'idle'),
            'pending_completion' => $pendingNormalized,
            'last_failure' => $lastFailure,
        ];
    }

    public function assertCanExplore(int $playerId, string $biomeCode): void
    {
        $rules = $this->biomeRules($biomeCode);
        if (!($rules['requires_expedition'] ?? false)) {
            return;
        }

        if ($this->expeditions->activeForPlayerInBiome($playerId, $biomeCode) === null) {
            throw new ExplorationException('EXPLORATION_EXPEDITION_REQUIRED', 'An active expedition is required to explore this biome.', 422, [
                'biome_code' => $biomeCode,
            ]);
        }
    }

    public function hasActiveExpeditionInBiome(int $playerId, string $biomeCode): bool
    {
        return $this->expeditions->activeForPlayerInBiome($playerId, $biomeCode) !== null;
    }
}
