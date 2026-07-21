<?php

namespace App\Game\Exploration\Services;

use App\Game\Expeditions\Services\ExpeditionEntryRequirementService;
use App\Game\Player\Services\PlayerAttributeService;
use App\Game\Seasons\Services\SeasonUnlockService;
use App\Support\DB;
use PDO;

class ExplorationBiomeProgressionService
{
    public function __construct(
        private ?PDO $pdo = null,
        private ?ExplorationBiomeCatalogService $catalog = null,
        private ?PlayerAttributeService $attributes = null,
        private ?SeasonUnlockService $seasons = null,
        private ?ExpeditionEntryRequirementService $entryRequirements = null
    ) {
        $this->catalog ??= new ExplorationBiomeCatalogService($this->pdo);
        $this->attributes ??= new PlayerAttributeService($this->pdo);
        $this->seasons ??= new SeasonUnlockService($this->pdo);
        $this->entryRequirements ??= new ExpeditionEntryRequirementService($this->pdo, $this->catalog);
    }

    public function isAvailableForPlayer(int $playerId, string $biomeCode): bool
    {
        return $this->statusForPlayer($playerId, $biomeCode)['unlocked'];
    }

    /** @return array<string, mixed> */
    public function statusForPlayer(int $playerId, string $biomeCode): array
    {
        $biomeCode = $this->catalog->normalizeBiomeCode($biomeCode);
        $biome = $this->catalog->biome($biomeCode);
        if ($biome === null) {
            return [
                'biome_code' => $biomeCode,
                'status' => 'locked',
                'unlocked' => false,
                'requirements' => [],
                'progress' => [],
                'entry' => null,
            ];
        }

        $seasonEval = $this->seasons->evaluateBiome($playerId, $biomeCode);
        if (($seasonEval['applicable'] ?? false) === true) {
            $base = [
                'biome_code' => $biomeCode,
                'status' => ($seasonEval['unlocked'] ?? false) ? 'available' : 'locked',
                'unlocked' => (bool) ($seasonEval['unlocked'] ?? false),
                'requirements' => $seasonEval['requirements'] ?? [],
                'progress' => $seasonEval['progress'] ?? [],
                'season_code' => $seasonEval['season_code'] ?? null,
                'unlock_source' => 'season',
            ];

            return $this->withEntry($playerId, $biomeCode, $base);
        }

        $unlock = is_array($biome['unlock'] ?? null) ? $biome['unlock'] : null;
        if ($unlock === null) {
            $available = (string) ($biome['status'] ?? 'locked') === 'available';
            $base = [
                'biome_code' => $biomeCode,
                'status' => $available ? 'available' : 'locked',
                'unlocked' => $available,
                'requirements' => [],
                'progress' => [],
                'unlock_source' => 'catalog',
            ];

            return $this->withEntry($playerId, $biomeCode, $base);
        }

        $explorationLevel = $this->attributeLevel($playerId, 'exploration');
        $completedExpeditions = $this->completedExpeditionCount($playerId);
        $requiredExploration = max(1, (int) ($unlock['exploration_level_min'] ?? 1));
        $requiredCompleted = max(0, (int) ($unlock['completed_expeditions_min'] ?? 0));

        $unlocked = $explorationLevel >= $requiredExploration && $completedExpeditions >= $requiredCompleted;

        $base = [
            'biome_code' => $biomeCode,
            'status' => $unlocked ? 'available' : 'locked',
            'unlocked' => $unlocked,
            'requirements' => [
                'exploration_level_min' => $requiredExploration,
                'completed_expeditions_min' => $requiredCompleted,
            ],
            'progress' => [
                'exploration_level' => $explorationLevel,
                'completed_expeditions' => $completedExpeditions,
            ],
            'unlock_source' => 'catalog',
        ];

        return $this->withEntry($playerId, $biomeCode, $base);
    }

    /** @return list<array<string, mixed>> */
    public function listBiomesForPlayer(int $playerId): array
    {
        $biomes = [];
        foreach ($this->catalog->listBiomes() as $biome) {
            $code = (string) ($biome['code'] ?? '');
            $progression = $this->statusForPlayer($playerId, $code);
            $biomes[] = array_merge($biome, [
                'status' => (string) ($progression['status'] ?? 'locked'),
                'unlocked' => (bool) ($progression['unlocked'] ?? false),
                'requirements' => $progression['requirements'] ?? [],
                'progress' => $progression['progress'] ?? [],
                'entry' => $progression['entry'] ?? null,
                'can_enter' => (bool) ($progression['can_enter'] ?? $progression['unlocked'] ?? false),
            ]);
        }

        return $biomes;
    }

    /** @param array<string, mixed> $base */
    private function withEntry(int $playerId, string $biomeCode, array $base): array
    {
        $entry = $this->entryRequirements->evaluate($playerId, $biomeCode);
        $base['entry'] = $entry;
        $base['can_enter'] = (bool) ($base['unlocked'] ?? false) && (bool) ($entry['allowed'] ?? true);

        return $base;
    }
    private function attributeLevel(int $playerId, string $attributeCode): int
    {
        foreach ($this->attributes->listForPlayer($playerId) as $attribute) {
            if (($attribute['code'] ?? null) === $attributeCode) {
                return max(1, (int) ($attribute['level'] ?? 1));
            }
        }

        return 1;
    }

    private function completedExpeditionCount(int $playerId): int
    {
        if (!$this->tableExists('expedition_instances')) {
            return 0;
        }

        $stmt = $this->pdo()->prepare("SELECT COUNT(*)
            FROM expedition_instances
            WHERE player_id = :player_id AND status = 'completed'");
        $stmt->execute(['player_id' => $playerId]);

        return (int) $stmt->fetchColumn();
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
