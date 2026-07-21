<?php

namespace App\Game\Expeditions\Services;

use App\Game\Exploration\Services\ExplorationBiomeProgressionService;
use App\Game\Exploration\Services\ExplorationDiscoveryService;
use App\Game\Exploration\Services\ExplorationExpeditionGateService;
use App\Game\Exploration\Services\ExplorationPlayerModifiersService;
use App\Game\Exploration\Services\ExplorationPlayerPositionService;
use App\Game\Inventory\Services\InventoryStateService;
use App\Game\Exploration\Services\InvestigableWorldService;
use App\Game\Player\Services\PlayerTemporaryBuffService;
use App\Game\Player\Services\PlayerVitalsService;
use App\Support\DB;
use PDO;

class ExpeditionArenaService
{
    public function __construct(
        private ?PDO $pdo = null,
        private ?ExpeditionStateService $state = null,
        private ?ExpeditionArenaCatalogService $catalog = null,
        private ?ExpeditionArenaSpawnService $spawn = null,
        private ?ExplorationExpeditionGateService $expeditionGate = null,
        private ?ExplorationPlayerPositionService $positions = null,
        private ?ExplorationDiscoveryService $discovery = null,
        private ?ExplorationPlayerModifiersService $modifiers = null,
        private ?InvestigableWorldService $world = null,
        private ?ExplorationBiomeProgressionService $progression = null,
        private ?ExpeditionArenaHazardService $hazards = null,
        private ?InventoryStateService $inventoryState = null,
        private ?PlayerTemporaryBuffService $temporaryBuffs = null,
        private ?PlayerVitalsService $playerVitals = null
    ) {
        $this->state ??= new ExpeditionStateService($this->pdo);
        $this->catalog ??= new ExpeditionArenaCatalogService($this->pdo);
        $this->spawn ??= new ExpeditionArenaSpawnService($this->pdo, $this->catalog);
        $this->expeditionGate ??= new ExplorationExpeditionGateService($this->pdo);
        $this->positions ??= new ExplorationPlayerPositionService($this->pdo);
        $this->discovery ??= new ExplorationDiscoveryService();
        $this->modifiers ??= new ExplorationPlayerModifiersService($this->pdo);
        $this->world ??= new InvestigableWorldService($this->pdo);
        $this->progression ??= new ExplorationBiomeProgressionService($this->pdo);
        $this->hazards ??= new ExpeditionArenaHazardService($this->pdo, $this->catalog, $this->modifiers);
        $this->inventoryState ??= new InventoryStateService($this->pdo);
        $this->temporaryBuffs ??= new PlayerTemporaryBuffService($this->pdo);
        $this->playerVitals ??= new PlayerVitalsService($this->pdo);
    }

    /**
     * @param array{mode?: string} $options mode=full|lite
     * @return array<string, mixed>
     */
    public function state(int $playerId, string $biomeCode, array $options = []): array
    {
        $lite = (($options['mode'] ?? 'full') === 'lite');
        $biomeCode = $this->catalog->normalizeBiomeCode($biomeCode);
        if (!$this->progression->isAvailableForPlayer($playerId, $biomeCode)) {
            throw new \RuntimeException('Biome is not available.');
        }

        $this->state->expireFinishedForPlayer($playerId);
        $expedition = $this->state->activeForPlayerInBiome($playerId, $biomeCode);
        $arenaBiome = $this->catalog->biome($biomeCode);
        $expeditionContext = $this->expeditionGate->expeditionContextForBiome($playerId, $biomeCode);

        if ($expedition !== null) {
            $this->spawn->ensureArenaReady($expedition, $playerId, $biomeCode);
        }

        $position = $this->positions->positionForBiome(
            $playerId,
            $biomeCode,
            $expedition !== null ? (string) ($expedition['public_id'] ?? '') : null
        );

        $discoveryRadius = (float) ($arenaBiome['discovery_radius'] ?? ($arenaBiome ? 1.6 : 1.5));
        $exploration = null;
        $points = null;
        $hiddenPoints = null;
        $playerModifiers = null;
        $playerHud = null;
        $wallets = null;
        $expeditionCarry = null;

        if (!$lite) {
            $playerModifiers = $this->modifiers->forPlayer($playerId, $biomeCode);
            try {
                $exploration = $this->world->listBiomeObjects($playerId, $biomeCode);
                $discoveryRadius = (float) (($exploration['map']['discovery_radius'] ?? null) ?? $discoveryRadius)
                    + (float) ($playerModifiers['discovery_radius_bonus'] ?? 0);
                $points = [];
                $hiddenPoints = [];
                foreach (($exploration['objects'] ?? []) as $object) {
                    if (!is_array($object)) {
                        continue;
                    }
                    $mapped = $this->mapPointOfInterest($object, $discoveryRadius);
                    if (($mapped['is_secret'] ?? false) === true) {
                        if (($mapped['discovered'] ?? false) === true) {
                            $points[] = $mapped;
                        } elseif (($mapped['in_range'] ?? false) === true) {
                            $hiddenPoints[] = $mapped;
                        }
                        continue;
                    }

                    $points[] = $mapped;
                }
            } catch (\Throwable) {
                $points = [];
                $hiddenPoints = [];
            }

            $inventory = $this->inventoryState->forPlayer($playerId);
            $expeditionCarry = $this->mapExpeditionCarry($inventory['containers'] ?? []);
            $playerHud = $inventory['player_hud'] ?? null;
            $wallets = $inventory['wallets'] ?? [];
        } else {
            try {
                $energy = $this->playerVitals->snapshot($playerId);
                $playerHud = [
                    'vitals' => [
                        'energy' => $energy['energy'] ?? null,
                        'hunger' => $energy['hunger'] ?? null,
                        'thirst' => $energy['thirst'] ?? null,
                        'is_resting' => (bool) ($energy['is_resting'] ?? false),
                    ],
                ];
            } catch (\Throwable) {
                $playerHud = null;
            }
            $expeditionCarry = $this->expeditionCarrySummaryLite($playerId);
        }

        $expeditionMetadata = is_array($expedition)
            ? $this->parseJson($expedition['metadata_json'] ?? null)
            : [];
        $temporary = $this->temporaryBuffs->activeForPlayer($playerId, $expeditionMetadata);

        $payload = [
            'biome_code' => $biomeCode,
            'biome_name' => (string) ($arenaBiome['name'] ?? $biomeCode),
            'mode' => $lite ? 'lite' : 'full',
            'arena' => [
                'background_url' => (string) ($arenaBiome['background_url'] ?? ''),
                'map_width' => (float) ($arenaBiome['map_width'] ?? 6),
                'map_height' => (float) ($arenaBiome['map_height'] ?? 4),
                'discovery_radius' => round($discoveryRadius, 2),
            ],
            'expedition' => $expeditionContext,
            'position' => $position,
            'vitals' => $this->mapVitals($expedition, $playerId),
            'encounters' => $this->listEncounters($expedition, $playerId),
            'ground_loot' => $this->listGroundLoot($expedition, $playerId),
            'combat_state' => $this->mapCombatState($expedition, $biomeCode, $lite),
            'potion_belt' => $temporary['potion_belt'] ?? [],
            'active_buffs' => $temporary['active_buffs'] ?? [],
        ];

        if (!$lite) {
            $payload['points_of_interest'] = $points ?? [];
            $payload['hidden_points'] = $hiddenPoints ?? [];
            $payload['modifiers'] = $playerModifiers;
            $payload['exploration'] = $exploration;
            $payload['player_hud'] = $playerHud;
            $payload['wallets'] = $wallets ?? [];
            $payload['expedition_carry'] = $expeditionCarry;
        } else {
            // Campos opcionais: cliente faz merge e preserva valores anteriores se null.
            $payload['player_hud'] = $playerHud;
            $payload['expedition_carry'] = $expeditionCarry;
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function move(int $playerId, string $biomeCode, float $mapX, float $mapY): array
    {
        $biomeCode = $this->catalog->normalizeBiomeCode($biomeCode);
        $expedition = $this->state->activeForPlayerInBiome($playerId, $biomeCode);
        if ($expedition === null) {
            throw new \RuntimeException('Active expedition required to move in the arena.');
        }

        $arenaBiome = $this->catalog->biome($biomeCode);
        $width = (float) ($arenaBiome['map_width'] ?? 6);
        $height = (float) ($arenaBiome['map_height'] ?? 4);
        $mapX = max(0.0, min($width, $mapX));
        $mapY = max(0.0, min($height, $mapY));

        $softPenalties = $this->softPenaltiesFromExpedition($expedition);
        $energySpend = $this->playerVitals->spendEnergy(
            $playerId,
            PlayerVitalsService::MOVE_ENERGY_COST,
            $softPenalties,
            'arena_move'
        );

        $position = $this->positions->moveTo(
            $playerId,
            $biomeCode,
            $mapX,
            $mapY,
            (string) ($expedition['public_id'] ?? '')
        );

        $hazard = $this->hazards->rollOnMove($expedition, $playerId, $biomeCode, $mapX, $mapY);

        return [
            'position' => $position,
            'hazard' => $hazard,
            'energy' => $energySpend,
        ];
    }

    /** @param array<string, mixed> $expedition */
    /** @return array<string, mixed> */
    private function softPenaltiesFromExpedition(array $expedition): array
    {
        $metadata = [];
        $raw = $expedition['metadata_json'] ?? null;
        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                $metadata = is_array($decoded) ? $decoded : [];
            } catch (\Throwable) {
                $metadata = [];
            }
        } elseif (is_array($raw)) {
            $metadata = $raw;
        }

        $entry = is_array($metadata['entry_requirements'] ?? null) ? $metadata['entry_requirements'] : [];
        $penalties = is_array($entry['soft_penalties'] ?? null) ? $entry['soft_penalties'] : [];

        return is_array($penalties) ? $penalties : [];
    }

    /** @param array<string, mixed> $object */
    /** @return array<string, mixed> */
    private function mapPointOfInterest(array $object, float $discoveryRadius): array
    {
        $distance = (float) ($object['distance'] ?? 0);
        $discovered = (bool) ($object['discovered'] ?? false);
        $actions = array_values((array) ($object['available_actions'] ?? []));

        $availableActions = [];
        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            $availableActions[] = [
                'action_code' => (string) ($action['action_code'] ?? ''),
                'required_tool_type' => $action['required_tool_type'] ?? null,
                'available' => (bool) ($action['available'] ?? false),
                'risk' => $action['risk'] ?? null,
            ];
        }

        $canInteract = false;
        foreach ($availableActions as $action) {
            if (($action['available'] ?? false) === true) {
                $canInteract = true;
                break;
            }
        }

        return [
            'public_id' => (string) ($object['public_id'] ?? ''),
            'definition_code' => (string) ($object['definition_code'] ?? ''),
            'name' => (string) ($object['name'] ?? '???'),
            'kind' => (string) ($object['kind'] ?? 'object'),
            'icon_key' => (string) ($object['icon_key'] ?? ''),
            'visual_type' => (string) ($object['visual_type'] ?? ''),
            'is_secret' => (bool) ($object['is_secret'] ?? false),
            'is_structure' => (bool) ($object['is_structure'] ?? false),
            'map_x' => (float) ($object['map_x'] ?? 0),
            'map_y' => (float) ($object['map_y'] ?? 0),
            'distance' => round($distance, 2),
            'discovery_radius' => round($discoveryRadius, 2),
            'in_range' => $discovered || $distance <= $discoveryRadius,
            'discovered' => $discovered,
            'reveal_tier' => (int) ($object['reveal_tier'] ?? 0),
            'max_tier' => (int) ($object['max_tier'] ?? 0),
            'state' => (string) ($object['state'] ?? 'active'),
            'flavor' => (string) ($object['flavor'] ?? ''),
            'can_interact' => $canInteract,
            'available_actions' => $availableActions,
        ];
    }

    /** @return array{current_hp: int, max_hp: int}|null */
    private function mapVitals(?array $expedition, int $playerId): ?array
    {
        if ($expedition === null || !$this->tableExists('expedition_arena_vitals')) {
            return null;
        }

        $stmt = $this->pdo()->prepare('SELECT current_hp, max_hp FROM expedition_arena_vitals WHERE expedition_instance_id = :expedition_id AND player_id = :player_id LIMIT 1');
        $stmt->execute([
            'expedition_id' => (int) $expedition['id'],
            'player_id' => $playerId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'current_hp' => (int) $row['current_hp'],
            'max_hp' => (int) $row['max_hp'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function listEncounters(?array $expedition, int $playerId): array
    {
        if ($expedition === null || !$this->tableExists('expedition_encounters')) {
            return [];
        }

        $stmt = $this->pdo()->prepare("SELECT * FROM expedition_encounters WHERE expedition_instance_id = :expedition_id AND player_id = :player_id AND status = 'active' ORDER BY id ASC");
        $stmt->execute([
            'expedition_id' => (int) $expedition['id'],
            'player_id' => $playerId,
        ]);

        $encounters = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $config = $this->parseJson($row['config_json'] ?? null);
            $encounters[] = [
                'public_id' => (string) $row['public_id'],
                'definition_code' => (string) $row['definition_code'],
                'name' => (string) ($config['name'] ?? $row['definition_code']),
                'sprite_key' => (string) ($config['sprite_key'] ?? 'mob'),
                'tier' => (int) $row['tier'],
                'tier_label' => (string) ($config['tier_label'] ?? 'Comum'),
                'map_x' => (float) $row['map_x'],
                'map_y' => (float) $row['map_y'],
                'current_hp' => (int) $row['current_hp'],
                'max_hp' => (int) $row['max_hp'],
                'is_boss' => (bool) ($config['is_boss'] ?? false),
            ];
        }

        return $encounters;
    }

    /** @return array<string, mixed>|null */
    private function mapCombatState(?array $expedition, string $biomeCode, bool $lite = false): ?array
    {
        if ($expedition === null) {
            return null;
        }

        $metadata = $this->parseJson($expedition['metadata_json'] ?? null);
        $biome = $this->catalog->biome($biomeCode) ?? [];
        $combat = is_array($metadata['combat'] ?? null) ? $metadata['combat'] : [];
        $killsToBoss = max(1, (int) ($combat['kills_to_boss'] ?? ($biome['kills_to_boss'] ?? 10)));
        $killsToward = max(0, (int) ($combat['kills_toward_boss'] ?? 0));

        $lootRadius = (float) ($combat['loot_pickup_radius'] ?? 0);
        if (!$lite) {
            try {
                $playerId = (int) ($expedition['player_id'] ?? 0);
                if ($playerId > 0) {
                    $snapshot = $this->inventoryState->combatSnapshotForPlayer($playerId);
                    foreach ($snapshot['character_stats'] ?? [] as $stat) {
                        if ((string) ($stat['code'] ?? '') === 'loot_pickup_radius') {
                            $lootRadius = min(2.5, max(0.0, (float) ($stat['value'] ?? 0)));
                            break;
                        }
                    }
                }
            } catch (\Throwable) {
                // Mantem valor do metadata.
            }
        }

        return [
            'mode' => 'idle',
            'focus_encounter_public_id' => isset($combat['focus_encounter_public_id']) && is_string($combat['focus_encounter_public_id'])
                ? $combat['focus_encounter_public_id']
                : null,
            'kills_toward_boss' => $killsToward,
            'kills_to_boss' => $killsToBoss,
            'boss_active' => (bool) ($combat['boss_active'] ?? false),
            'boss_defeated' => max(0, (int) ($combat['boss_defeated'] ?? 0)),
            'engage_radius' => (float) ($biome['engage_radius'] ?? 2.0),
            'loot_pickup_radius' => $lootRadius,
            'wave_progress' => min(1, $killsToward / $killsToBoss),
        ];
    }

    /** @return array<string, mixed>|null */
    private function expeditionCarrySummaryLite(int $playerId): ?array
    {
        if (!$this->tableExists('container_instances') || !$this->tableExists('container_definitions')) {
            return null;
        }

        $stmt = $this->pdo()->prepare("SELECT cinst.id, cinst.public_id, cinst.name, cinst.grid_columns, cinst.grid_rows
            FROM container_instances cinst
            INNER JOIN container_definitions cdef ON cdef.id = cinst.container_definition_id
            WHERE cinst.owner_player_id = :player_id
              AND cinst.status = 'active'
              AND cdef.code = 'expedition_carry'
            LIMIT 1");
        try {
            $stmt->execute(['player_id' => $playerId]);
        } catch (\Throwable) {
            return null;
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $columns = max(1, (int) ($row['grid_columns'] ?? 4));
        $rows = max(1, (int) ($row['grid_rows'] ?? 4));
        $capacity = $columns * $rows;
        $occupied = 0;
        $itemCount = 0;

        if ($this->tableExists('container_items')) {
            try {
                $occ = $this->pdo()->prepare('SELECT COUNT(*) AS item_count, COALESCE(SUM(grid_w * grid_h), 0) AS occupied
                    FROM container_items WHERE container_instance_id = :id');
                $occ->execute(['id' => (int) $row['id']]);
                $stats = $occ->fetch(PDO::FETCH_ASSOC);
                if (is_array($stats)) {
                    $itemCount = (int) ($stats['item_count'] ?? 0);
                    $occupied = (int) ($stats['occupied'] ?? 0);
                }
            } catch (\Throwable) {
                $occupied = 0;
            }
        }

        return [
            'public_id' => (string) $row['public_id'],
            'name' => (string) ($row['name'] ?? 'Expedition Carry'),
            'columns' => $columns,
            'rows' => $rows,
            'capacity_cells' => $capacity,
            'occupied_cells' => $occupied,
            'item_count' => $itemCount,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function listGroundLoot(?array $expedition, int $playerId): array
    {
        if ($expedition === null || !$this->tableExists('expedition_ground_loot')) {
            return [];
        }

        $stmt = $this->pdo()->prepare("SELECT public_id, item_definition_code, quantity, map_x, map_y FROM expedition_ground_loot WHERE expedition_instance_id = :expedition_id AND player_id = :player_id AND status = 'ground' ORDER BY id ASC");
        $stmt->execute([
            'expedition_id' => (int) $expedition['id'],
            'player_id' => $playerId,
        ]);

        $loot = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $loot[] = [
                'public_id' => (string) $row['public_id'],
                'item_definition_code' => (string) $row['item_definition_code'],
                'quantity' => (int) $row['quantity'],
                'map_x' => (float) $row['map_x'],
                'map_y' => (float) $row['map_y'],
            ];
        }

        return $loot;
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

    /** @param list<array<string,mixed>> $containers */
    private function mapExpeditionCarry(array $containers): ?array
    {
        foreach ($containers as $container) {
            if (!is_array($container) || (string) ($container['definition_code'] ?? '') !== 'expedition_carry') {
                continue;
            }

            $columns = (int) ($container['grid']['columns'] ?? 0);
            $rows = (int) ($container['grid']['rows'] ?? 0);
            $items = array_values((array) ($container['items'] ?? []));
            $occupiedCells = 0;
            foreach ($items as $item) {
                $placement = (array) ($item['placement'] ?? []);
                $occupiedCells += max(1, (int) ($placement['grid_w'] ?? 1)) * max(1, (int) ($placement['grid_h'] ?? 1));
            }

            return [
                'public_id' => (string) ($container['public_id'] ?? ''),
                'name' => (string) ($container['name'] ?? 'Expedition Carry'),
                'columns' => $columns,
                'rows' => $rows,
                'capacity_cells' => max(1, $columns * $rows),
                'occupied_cells' => $occupiedCells,
                'item_count' => count($items),
                'items' => array_slice(array_map(static function (array $item): array {
                    return [
                        'public_id' => (string) ($item['public_id'] ?? ''),
                        'name' => (string) ($item['item_name'] ?? $item['definition']['name'] ?? 'Item'),
                        'definition_code' => (string) ($item['definition']['code'] ?? ''),
                        'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                    ];
                }, $items), 0, 6),
            ];
        }

        return null;
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
