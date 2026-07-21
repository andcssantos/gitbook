<?php

namespace App\Game\Exploration\Services;

use App\Game\Exploration\ExplorationException;
use App\Game\Player\Services\PlayerVitalsService;
use App\Support\DB;
use App\Support\PublicId;
use PDO;

class InvestigableWorldService
{
    public function __construct(
        private ?PDO $pdo = null,
        private ?ExplorationRespawnService $respawn = null,
        private ?ExplorationExpeditionGateService $expeditionGate = null,
        private ?ExplorationBiomeCatalogService $catalog = null,
        private ?ExplorationPlayerPositionService $positions = null,
        private ?ExplorationDiscoveryService $discovery = null,
        private ?ExplorationBiomeProgressionService $progression = null,
        private ?ExplorationContainerRiskService $containerRisk = null,
        private ?ExplorationPlayerModifiersService $modifiers = null,
        private ?PlayerVitalsService $vitals = null
    ) {
        $this->respawn ??= new ExplorationRespawnService($this->pdo);
        $this->expeditionGate ??= new ExplorationExpeditionGateService($this->pdo);
        $this->catalog ??= new ExplorationBiomeCatalogService($this->pdo);
        $this->positions ??= new ExplorationPlayerPositionService($this->pdo);
        $this->discovery ??= new ExplorationDiscoveryService($this->catalog);
        $this->progression ??= new ExplorationBiomeProgressionService($this->pdo, $this->catalog);
        $this->containerRisk ??= new ExplorationContainerRiskService();
        $this->modifiers ??= new ExplorationPlayerModifiersService($this->pdo);
        $this->vitals ??= new PlayerVitalsService($this->pdo);
    }

    /** @return array<string, mixed> */
    public function listBiomeObjects(int $playerId, string $biomeCode): array
    {
        $biomeCode = $this->normalizeBiomeCode($biomeCode);
        if (!$this->progression->isAvailableForPlayer($playerId, $biomeCode)) {
            $status = $this->progression->statusForPlayer($playerId, $biomeCode);
            throw new ExplorationException('EXPLORATION_BIOME_LOCKED', 'This biome is not available yet.', 422, $status);
        }

        if (!$this->tableExists('investigable_definitions')) {
            return [
                'biome_code' => $biomeCode,
                'biome_name' => $this->biomeName($biomeCode),
                'objects' => [],
            ];
        }

        $definitions = $this->definitionsForBiome($biomeCode);
        if ($definitions === []) {
            throw new ExplorationException('EXPLORATION_BIOME_NOT_FOUND', 'Exploration biome was not found.', 404, [
                'biome_code' => $biomeCode,
            ]);
        }

        $this->ensureInstancesForPlayer($playerId, $biomeCode, $definitions);
        $this->respawn->refreshDueInstances($playerId, $biomeCode);

        $expeditionContext = $this->expeditionGate->expeditionContextForBiome($playerId, $biomeCode);
        $expeditionPublicId = $expeditionContext['active'] ? (string) ($expeditionContext['public_id'] ?? '') : null;
        $playerPosition = $this->positions->positionForBiome($playerId, $biomeCode, $expeditionPublicId ?: null);
        $playerModifiers = $this->modifiers->forPlayer($playerId, $biomeCode);
        $mapConfig = $this->catalog->mapConfig($biomeCode);
        $mapConfig['discovery_radius'] = round(
            (float) ($mapConfig['discovery_radius'] ?? 1.5) + (float) ($playerModifiers['discovery_radius_bonus'] ?? 0),
            2
        );

        $stmt = $this->pdo()->prepare('SELECT ii.*, id.code AS definition_code, id.name AS definition_name, id.kind, id.summary, id.icon_key, id.config_json, id.sort_order
            FROM investigable_instances ii
            INNER JOIN investigable_definitions id ON id.id = ii.definition_id
            WHERE ii.player_id = :player_id AND ii.biome_code = :biome_code AND id.is_active = 1
            ORDER BY id.sort_order ASC, ii.id ASC');
        $stmt->execute([
            'player_id' => $playerId,
            'biome_code' => $biomeCode,
        ]);

        $objects = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $objects[] = $this->mapInstanceSummary($row, $playerPosition, $biomeCode, $mapConfig, $playerModifiers);
        }

        $vitals = $this->vitals->snapshot($playerId);
        $energyCurrent = (float) ($vitals['energy']['current'] ?? 0);

        return [
            'biome_code' => $biomeCode,
            'biome_name' => $this->biomeName($biomeCode),
            'expedition' => $expeditionContext,
            'map' => $mapConfig,
            'position' => $playerPosition,
            'modifiers' => $playerModifiers,
            'vitals' => [
                'energy' => $vitals['energy'] ?? ['current' => 0, 'max' => 0],
                'hunger' => $vitals['hunger'] ?? ['current' => 0, 'max' => 0],
                'thirst' => $vitals['thirst'] ?? ['current' => 0, 'max' => 0],
                'is_resting' => (bool) ($vitals['is_resting'] ?? false),
                'can_start_expedition' => $energyCurrent >= PlayerVitalsService::MIN_ENERGY_TO_START
                    && !($vitals['is_resting'] ?? false),
                'min_energy_to_start' => PlayerVitalsService::MIN_ENERGY_TO_START,
            ],
            'objects' => $objects,
        ];
    }

    /** @return array<string, mixed> */
    public function listBiomes(int $playerId): array
    {
        return [
            'biomes' => $this->progression->listBiomesForPlayer($playerId),
            'world_map' => [
                'width' => 6,
                'height' => 4,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function updatePlayerPosition(int $playerId, string $biomeCode, float $mapX, float $mapY): array
    {
        $biomeCode = $this->normalizeBiomeCode($biomeCode);
        $this->expeditionGate->assertCanExplore($playerId, $biomeCode);
        $expeditionContext = $this->expeditionGate->expeditionContextForBiome($playerId, $biomeCode);
        $expeditionPublicId = (string) ($expeditionContext['public_id'] ?? '');

        $position = $this->positions->moveTo(
            $playerId,
            $biomeCode,
            $mapX,
            $mapY,
            $expeditionPublicId !== '' ? $expeditionPublicId : null
        );

        return [
            'position' => $position,
            'map' => $this->catalog->mapConfig($biomeCode),
        ];
    }

    public function findOwnedInstance(int $playerId, string $instancePublicId): ?array
    {
        if (!$this->tableExists('investigable_instances')) {
            return null;
        }

        $stmt = $this->pdo()->prepare('SELECT ii.*, id.code AS definition_code, id.name AS definition_name, id.kind, id.summary, id.icon_key, id.config_json
            FROM investigable_instances ii
            INNER JOIN investigable_definitions id ON id.id = ii.definition_id
            WHERE ii.public_id = :public_id AND ii.player_id = :player_id
            LIMIT 1');
        $stmt->execute([
            'public_id' => $instancePublicId,
            'player_id' => $playerId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function assertObjectDiscovered(int $playerId, array $instance): void
    {
        $biomeCode = (string) ($instance['biome_code'] ?? '');
        $revealTier = max(0, (int) ($instance['reveal_tier'] ?? 0));
        $config = $this->parseJson($instance['config_json'] ?? null);
        $expeditionContext = $this->expeditionGate->expeditionContextForBiome($playerId, $biomeCode);
        $expeditionPublicId = $expeditionContext['active'] ? (string) ($expeditionContext['public_id'] ?? '') : null;
        $playerPosition = $this->positions->positionForBiome($playerId, $biomeCode, $expeditionPublicId ?: null);

        $playerModifiers = $this->modifiers->forPlayer($playerId, $biomeCode);
        $mapConfig = $this->catalog->mapConfig($biomeCode);
        $effectiveRadius = (float) ($mapConfig['discovery_radius'] ?? 1.5) + (float) ($playerModifiers['discovery_radius_bonus'] ?? 0);

        $discovered = $this->discovery->isDiscovered(
            $biomeCode,
            (float) ($playerPosition['map_x'] ?? 0),
            (float) ($playerPosition['map_y'] ?? 0),
            (float) ($config['map_x'] ?? 0),
            (float) ($config['map_y'] ?? 0),
            $revealTier,
            $effectiveRadius
        );

        if (!$discovered) {
            throw new ExplorationException('EXPLORATION_OBJECT_UNDISCOVERED', 'Move closer on the biome map to discover this object.', 422, [
                'object_public_id' => (string) ($instance['public_id'] ?? ''),
            ]);
        }
    }

    /** @param array<int, array<string, mixed>> $definitions */
    private function ensureInstancesForPlayer(int $playerId, string $biomeCode, array $definitions): void
    {
        foreach ($definitions as $definition) {
            $definitionId = (int) $definition['id'];
            $stmt = $this->pdo()->prepare('SELECT id FROM investigable_instances WHERE player_id = :player_id AND definition_id = :definition_id LIMIT 1');
            $stmt->execute([
                'player_id' => $playerId,
                'definition_id' => $definitionId,
            ]);

            if ($stmt->fetchColumn() !== false) {
                continue;
            }

            $driver = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'mysql') {
                $sql = 'INSERT IGNORE INTO investigable_instances (
                    public_id, player_id, definition_id, biome_code, reveal_tier, state
                ) VALUES (
                    :public_id, :player_id, :definition_id, :biome_code, 0, :state
                )';
            } else {
                $sql = 'INSERT INTO investigable_instances (
                    public_id, player_id, definition_id, biome_code, reveal_tier, state
                ) VALUES (
                    :public_id, :player_id, :definition_id, :biome_code, 0, :state
                ) ON CONFLICT(player_id, definition_id) DO NOTHING';
            }

            $this->pdo()->prepare($sql)->execute([
                'public_id' => PublicId::uuid(),
                'player_id' => $playerId,
                'definition_id' => $definitionId,
                'biome_code' => $biomeCode,
                'state' => 'active',
            ]);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function definitionsForBiome(string $biomeCode): array
    {
        $stmt = $this->pdo()->prepare('SELECT *
            FROM investigable_definitions
            WHERE biome_code = :biome_code AND is_active = 1
            ORDER BY sort_order ASC, id ASC');
        $stmt->execute(['biome_code' => $biomeCode]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string, mixed> $playerPosition @param array<string, mixed> $mapConfig @param array<string, mixed> $playerModifiers */
    private function mapInstanceSummary(array $row, array $playerPosition, string $biomeCode, array $mapConfig, array $playerModifiers): array
    {
        $config = $this->parseJson($row['config_json'] ?? null);
        $revealTier = max(0, (int) $row['reveal_tier']);
        $state = (string) ($row['state'] ?? 'active');
        $maxTier = count($config['analyze_tiers'] ?? []);
        $tiers = $config['analyze_tiers'] ?? [];
        $latestTier = $revealTier > 0 ? ($tiers[$revealTier - 1] ?? null) : null;
        $respawnSeconds = $state === 'depleted'
            ? $this->respawn->respawnSecondsRemaining(isset($row['respawn_at']) ? (string) $row['respawn_at'] : null)
            : null;
        $mapX = (float) ($config['map_x'] ?? 0);
        $mapY = (float) ($config['map_y'] ?? 0);
        $discovered = $this->discovery->isDiscovered(
            $biomeCode,
            (float) ($playerPosition['map_x'] ?? 0),
            (float) ($playerPosition['map_y'] ?? 0),
            $mapX,
            $mapY,
            $revealTier,
            (float) ($mapConfig['discovery_radius'] ?? $this->catalog->discoveryRadius($biomeCode))
        );
        $distance = $this->discovery->distance(
            (float) ($playerPosition['map_x'] ?? 0),
            (float) ($playerPosition['map_y'] ?? 0),
            $mapX,
            $mapY
        );

        $summary = [
            'public_id' => (string) $row['public_id'],
            'definition_code' => (string) $row['definition_code'],
            'name' => $discovered && $revealTier > 0 ? (string) $row['definition_name'] : '???',
            'kind' => $discovered ? (string) $row['kind'] : 'unknown',
            'summary' => $discovered && $revealTier > 0 ? (string) ($row['summary'] ?? '') : null,
            'icon_key' => (string) ($row['icon_key'] ?? ''),
            'visual_type' => (string) ($config['visual_type'] ?? $this->resolveVisualType(
                (string) ($row['kind'] ?? ''),
                (string) ($row['definition_code'] ?? ''),
                (string) ($row['icon_key'] ?? '')
            )),
            'is_secret' => (bool) ($config['is_secret'] ?? false),
            'is_structure' => (bool) ($config['is_structure'] ?? false),
            'position_label' => (string) ($config['position_label'] ?? ''),
            'map_x' => $mapX,
            'map_y' => $mapY,
            'distance' => round($distance, 2),
            'discovered' => $discovered,
            'flavor' => $discovered
                ? ($revealTier > 0
                    ? (string) ($latestTier['description'] ?? $config['flavor_unknown'] ?? '')
                    : (string) ($config['flavor_unknown'] ?? 'Algo estranho chama atencao aqui.'))
                : 'Fora do raio de descoberta. Aproxime-se no mapa.',
            'state' => $state,
            'reveal_tier' => $revealTier,
            'max_tier' => $maxTier,
            'fully_analyzed' => $maxTier > 0 && $revealTier >= $maxTier,
            'respawn_at' => $row['respawn_at'] ?? null,
            'respawn_in_seconds' => $respawnSeconds,
            'available_actions' => $discovered
                ? $this->availableActionsForInstance(
                    $revealTier,
                    $state,
                    (int) $row['definition_id'],
                    $maxTier,
                    (float) ($playerModifiers['trap_chance_reduction'] ?? 0),
                    array_values((array) ($playerModifiers['trap_mitigation_sources'] ?? []))
                )
                : [],
            'recommended_tool' => $discovered && is_array($latestTier['recommended_tool'] ?? null)
                ? $latestTier['recommended_tool']
                : null,
        ];

        return $summary;
    }

    private function resolveVisualType(string $kind, string $definitionCode, string $iconKey): string
    {
        $blob = strtolower(trim($kind . ' ' . $definitionCode . ' ' . $iconKey));
        if (str_contains($blob, 'chest') || str_contains($blob, 'crate') || str_contains($blob, 'cache') || str_contains($blob, 'container')) {
            return 'chest';
        }
        if (str_contains($blob, 'cave') || str_contains($blob, 'pit') || str_contains($blob, 'mine')) {
            return 'cave';
        }
        if (str_contains($blob, 'cabin') || str_contains($blob, 'hut') || str_contains($blob, 'camp') || str_contains($blob, 'house')) {
            return 'cabin';
        }
        if (str_contains($blob, 'altar') || str_contains($blob, 'shrine') || str_contains($blob, 'temple') || str_contains($blob, 'prayer')) {
            return 'shrine';
        }
        if (str_contains($blob, 'ruin') || str_contains($blob, 'tower') || str_contains($blob, 'portal') || str_contains($blob, 'wonder')) {
            return 'ruin';
        }
        if (str_contains($blob, 'wood') || str_contains($blob, 'stump') || str_contains($blob, 'flora') || str_contains($blob, 'fern') || str_contains($blob, 'briar')) {
            return 'resource';
        }
        if (str_contains($blob, 'stone') || str_contains($blob, 'rock') || str_contains($blob, 'ore')) {
            return 'resource';
        }

        return 'resource';
    }

    /** @return array<int, array<string, mixed>> */
    private function availableActionsForInstance(
        int $revealTier,
        string $state,
        int $definitionId,
        int $maxTier,
        float $trapChanceReduction = 0.0,
        array $mitigationSources = []
    ): array {
        if (!$this->tableExists('investigable_actions')) {
            return [];
        }

        $stmt = $this->pdo()->prepare('SELECT action_code, required_tool_type, min_reveal_tier, max_reveal_tier, xp_tool, xp_attribute, attribute_code, config_json
            FROM investigable_actions
            WHERE definition_id = :definition_id AND is_active = 1
            ORDER BY sort_order ASC, id ASC');
        $stmt->execute(['definition_id' => $definitionId]);

        $actions = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $actionCode = (string) $row['action_code'];
            $config = $this->parseJson($row['config_json'] ?? null);
            $minRevealTier = max(0, (int) $row['min_reveal_tier']);
            $actionMaxTier = $row['max_reveal_tier'] !== null ? (int) $row['max_reveal_tier'] : $maxTier;

            if ($actionCode === 'analyze_magnifier') {
                $available = $state === 'active' && $revealTier < $actionMaxTier;
            } else {
                $available = $state === 'active' && $revealTier >= $minRevealTier;
            }

            $actions[] = [
                'action_code' => $actionCode,
                'required_tool_type' => $row['required_tool_type'] !== null ? (string) $row['required_tool_type'] : null,
                'available' => $available,
                'xp_tool' => (int) $row['xp_tool'],
                'xp_attribute' => (int) $row['xp_attribute'],
                'attribute_code' => $row['attribute_code'] !== null ? (string) $row['attribute_code'] : null,
                'action_label' => isset($config['action_label']) && is_string($config['action_label']) ? $config['action_label'] : null,
                'detail_text' => isset($config['detail_text']) && is_string($config['detail_text']) ? $config['detail_text'] : null,
                'risk' => $this->containerRisk->summarizeActionRisk(
                    $actionCode,
                    $config,
                    $trapChanceReduction,
                    $mitigationSources
                ),
            ];
        }

        return $actions;
    }

    private function normalizeBiomeCode(string $biomeCode): string
    {
        $normalized = strtolower(trim($biomeCode));
        $normalized = preg_replace('/[^a-z0-9_]/', '', $normalized) ?: '';

        return $normalized;
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
