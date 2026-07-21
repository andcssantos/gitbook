<?php

namespace App\Game\Expeditions\Services;

use App\Game\Exploration\Services\ExplorationBiomeCatalogService;
use App\Game\Exploration\Services\ExplorationBiomeProgressionService;
use App\Game\Exploration\Services\ExplorationExpeditionGateService;
use App\Game\Exploration\Services\ExplorationPlayerPositionService;
use App\Game\Player\Services\PlayerVitalsService;
use App\Support\DB;
use App\Support\PublicId;
use PDO;

class ExpeditionLifecycleService
{
    public function __construct(
        private ?PDO $pdo = null,
        private ?ExpeditionStateService $state = null,
        private ?ExplorationExpeditionGateService $biomeRules = null,
        private ?ExplorationPlayerPositionService $positions = null,
        private ?ExplorationBiomeCatalogService $catalog = null,
        private ?ExplorationBiomeProgressionService $progression = null,
        private ?ExpeditionArenaSpawnService $arenaSpawn = null,
        private ?ExpeditionRunModifiersService $runModifiers = null,
        private ?ExpeditionEntryRequirementService $entryRequirements = null,
        private ?PlayerVitalsService $vitals = null
    ) {
        $this->state ??= new ExpeditionStateService($this->pdo);
        $this->biomeRules ??= new ExplorationExpeditionGateService();
        $this->positions ??= new ExplorationPlayerPositionService($this->pdo);
        $this->catalog ??= new ExplorationBiomeCatalogService($this->pdo);
        $this->progression ??= new ExplorationBiomeProgressionService($this->pdo, $this->catalog);
        $this->arenaSpawn ??= new ExpeditionArenaSpawnService($this->pdo);
        $this->runModifiers ??= new ExpeditionRunModifiersService($this->pdo);
        $this->entryRequirements ??= new ExpeditionEntryRequirementService($this->pdo, $this->catalog, $this->runModifiers);
        $this->vitals ??= new PlayerVitalsService($this->pdo);
    }

    /** @return array<string, mixed> */
    public function activeForPlayer(int $playerId): array
    {
        $active = $this->state->activeForPlayer($playerId);

        return [
            'active' => $active !== null,
            'expedition' => $active !== null ? $this->mapExpedition($active) : null,
        ];
    }

    /** @return array<string, mixed> */
    public function start(int $playerId, string $biomeCode, ?int $durationMinutes = null): array
    {
        if (!$this->tableExists('expedition_instances')) {
            return [
                'started' => false,
                'reason' => 'expedition_table_missing',
            ];
        }

        $biomeCode = strtolower(trim(preg_replace('/[^a-z0-9_]/', '', $biomeCode) ?: ''));
        if ($biomeCode === '') {
            throw new \InvalidArgumentException('Biome code is required.');
        }

        if (!$this->progression->isAvailableForPlayer($playerId, $biomeCode)) {
            throw new \RuntimeException('This biome is not available yet.');
        }

        $this->vitals->assertCanStartExpedition($playerId);
        $entry = $this->entryRequirements->assertCanEnter($playerId, $biomeCode);

        $this->expireFinishedExpeditions($playerId);

        if ($this->state->hasActiveForPlayer($playerId)) {
            throw new \RuntimeException('Player already has an active expedition.');
        }

        $rules = $this->biomeRules->biomeRules($biomeCode);
        $duration = max(2, $durationMinutes ?? (int) ($rules['default_duration_minutes'] ?? 2));
        $runMods = $this->runModifiers->forPlayer($playerId, $biomeCode);
        $mapDurationBonus = (float) ($runMods['map_duration_bonus'] ?? 0);
        if ($mapDurationBonus > 0) {
            $duration = max(2, (int) round($duration * (1 + $mapDurationBonus)));
        }
        $publicId = PublicId::uuid();
        $seed = bin2hex(random_bytes(8));
        $metadata = json_encode([
            'biome_code' => $biomeCode,
            'biome_name' => $this->biomeName($biomeCode),
            'duration_minutes' => $duration,
            'map_duration_bonus' => $mapDurationBonus,
            'run_modifiers' => $this->runModifiers->summaryForMetadata($playerId, $biomeCode),
            'entry_requirements' => [
                'met' => (bool) ($entry['met'] ?? true),
                'mode' => (string) ($entry['mode'] ?? 'none'),
                'soft_penalties' => (array) ($entry['soft_penalties'] ?? []),
                'missing' => (array) ($entry['missing'] ?? []),
            ],
            'auto_carry_loot' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $endsAt = date('Y-m-d H:i:s', time() + ($duration * 60));

        $this->pdo()->prepare('INSERT INTO expedition_instances (
            public_id, player_id, status, expedition_seed, ends_at, metadata_json
        ) VALUES (
            :public_id, :player_id, :status, :expedition_seed, :ends_at, :metadata_json
        )')->execute([
            'public_id' => $publicId,
            'player_id' => $playerId,
            'status' => 'active',
            'expedition_seed' => $seed,
            'ends_at' => $endsAt,
            'metadata_json' => $metadata,
        ]);

        $this->positions->resetToSpawn($playerId, $biomeCode, $publicId);

        $active = $this->state->activeForPlayer($playerId);
        if ($active !== null) {
            $this->arenaSpawn->ensureArenaReady($active, $playerId, $biomeCode);
        }

        return [
            'started' => true,
            'expedition' => $active !== null ? $this->mapExpedition($active) : null,
        ];
    }

    private function expireFinishedExpeditions(int $playerId): void
    {
        $this->state->expireFinishedForPlayer($playerId);
    }

    /** @return array<string, mixed> */
    private function mapExpedition(array $row): array
    {
        $metadata = $this->parseJson($row['metadata_json'] ?? null);

        return [
            'public_id' => (string) ($row['public_id'] ?? ''),
            'status' => (string) ($row['status'] ?? 'active'),
            'biome_code' => (string) ($metadata['biome_code'] ?? ''),
            'biome_name' => (string) ($metadata['biome_name'] ?? ''),
            'started_at' => $row['started_at'] ?? null,
            'ends_at' => $row['ends_at'] ?? null,
            'expedition_seed' => (string) ($row['expedition_seed'] ?? ''),
            'auto_carry_loot' => ($metadata['auto_carry_loot'] ?? true) !== false,
        ];
    }

    private function biomeName(string $biomeCode): string
    {
        return match ($biomeCode) {
            'bosque_inicial' => 'Bosque Inicial',
            'costa_salobra' => 'Costa Salobra',
            'gruta_ecoante' => 'Gruta Ecoante',
            'ruinas_afundadas' => 'Ruinas Afundadas',
            'pantano_venenoso' => 'Pantano Venenoso',
            'vale_dos_reis' => 'Vale dos Reis',
            default => ucwords(str_replace('_', ' ', $biomeCode)),
        };
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
