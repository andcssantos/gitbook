<?php

namespace App\Game\Campaign\Services;

use App\Game\Expeditions\Services\ExpeditionArenaCatalogService;
use App\Game\Player\Services\PlayerVitalsService;
use App\Support\DB;
use PDO;

class CampaignWorldService
{
    public function __construct(
        private ?PDO $pdo = null,
        private ?ExpeditionArenaCatalogService $catalog = null,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function worldForPlayer(int $playerId, string $worldCode = 'mundo_1_bosque'): ?array
    {
        if (!$this->tableExists('campaign_worlds')) {
            return null;
        }

        $stmt = $this->pdo()->prepare('SELECT * FROM campaign_worlds WHERE code = :code AND is_active = 1 LIMIT 1');
        $stmt->execute(['code' => $worldCode]);
        $world = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($world)) {
            return null;
        }

        $nodesStmt = $this->pdo()->prepare('SELECT * FROM campaign_nodes WHERE world_id = :world_id AND is_active = 1 ORDER BY sort_order ASC, id ASC');
        $nodesStmt->execute(['world_id' => (int) $world['id']]);
        $rows = $nodesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $progressByNodeId = $this->progressByNodeId($playerId, array_map(static fn (array $row): int => (int) $row['id'], $rows));
        $clearedCodes = [];
        $flags = $this->playerFlags($playerId);

        foreach ($rows as $row) {
            $progress = $progressByNodeId[(int) $row['id']] ?? null;
            if (($progress['status'] ?? '') === 'cleared') {
                $clearedCodes[] = (string) $row['code'];
            }
            $nodeFlags = $this->parseJson($progress['flags_json'] ?? null);
            foreach ($nodeFlags as $flag => $value) {
                // Ignora blobs de discovery (arrays); so flags escalares (ex.: torn_map).
                if (!is_string($flag) || $flag === '' || is_array($value)) {
                    continue;
                }
                if ($value) {
                    $flags[$flag] = true;
                }
            }
        }

        $explorationLevel = $this->explorationLevel($playerId);
        $discovery = $this->mergedDiscovery($progressByNodeId);
        $playerStats = (new \App\Game\Campaign\Services\CampaignStageRunService($this->pdo()))->playerCombatStats($playerId);
        $vitalPenalties = (new \App\Game\Player\Services\PlayerVitalsService($this->pdo()))->campaignSoftPenalties($playerId);

        $nodes = [];
        foreach ($rows as $row) {
            $nodes[] = $this->mapNode(
                $row,
                $progressByNodeId[(int) $row['id']] ?? null,
                $clearedCodes,
                $flags,
                $explorationLevel,
                $discovery,
                $playerStats,
                $vitalPenalties
            );
        }

        return [
            'code' => (string) $world['code'],
            'name' => (string) $world['name'],
            'summary' => (string) ($world['summary'] ?? ''),
            'background_url' => (string) $world['background_url'],
            'path' => ['w1_s1', 'w1_s2', 'w1_village', 'w1_s3', 'w1_s4', 'w1_s5'],
            'nodes' => $nodes,
            'flags' => array_keys(array_filter($flags)),
            'player' => [
                'combat' => $playerStats,
                'vital_penalties' => $vitalPenalties,
                'album' => $this->buildArtifactAlbum($discovery),
            ],
        ];
    }

    /**
     * @param list<string> $clearedCodes
     * @param array<string, bool> $flags
     * @param array{monsters:array<string,bool>,items:array<string,bool>} $discovery
     * @param array<string, mixed> $playerStats
     * @param array<string, mixed> $vitalPenalties
     * @return array<string, mixed>
     */
    private function mapNode(
        array $row,
        ?array $progress,
        array $clearedCodes,
        array $flags,
        int $explorationLevel,
        array $discovery,
        array $playerStats,
        array $vitalPenalties
    ): array {
        $unlock = $this->parseJson($row['unlock_json'] ?? null);
        $config = $this->parseJson($row['config_json'] ?? null);
        $status = $this->resolveStatus($row, $progress, $unlock, $clearedCodes, $flags);

        return [
            'code' => (string) $row['code'],
            'type' => (string) $row['node_type'],
            'label' => (string) $row['label'],
            'map_x' => (float) $row['map_x'],
            'map_y' => (float) $row['map_y'],
            'pin_url' => (string) $row['pin_url'],
            'scene_url' => $row['scene_url'] !== null ? (string) $row['scene_url'] : null,
            'wave_count' => (int) ($row['wave_count'] ?? 0),
            'status' => $status,
            'locked' => in_array($status, ['locked', 'teaser'], true),
            'available' => $status === 'available' || $status === 'cleared',
            'unlock' => $unlock,
            'config' => $config,
            'progress' => [
                'status' => (string) ($progress['status'] ?? $status),
                'highest_wave' => (int) ($progress['highest_wave'] ?? 0),
                'clear_count' => (int) ($progress['clear_count'] ?? 0),
            ],
            'lobby' => $this->lobbyCopy(
                $row,
                $status,
                $config,
                $progress,
                $unlock,
                $clearedCodes,
                $flags,
                $explorationLevel,
                $discovery,
                $playerStats,
                $vitalPenalties
            ),
        ];
    }

    /**
     * @param array<string, mixed> $unlock
     * @param list<string> $clearedCodes
     * @param array<string, bool> $flags
     */
    private function resolveStatus(array $row, ?array $progress, array $unlock, array $clearedCodes, array $flags): string
    {
        if (($unlock['teaser'] ?? false) === true) {
            return 'teaser';
        }

        if (($progress['status'] ?? '') === 'cleared') {
            return 'cleared';
        }

        if (($unlock['always'] ?? false) === true) {
            return 'available';
        }

        $requiresClear = array_values(array_filter((array) ($unlock['requires_clear'] ?? []), 'is_string'));
        foreach ($requiresClear as $code) {
            if (!in_array($code, $clearedCodes, true)) {
                return 'locked';
            }
        }

        $requiresFlag = (string) ($unlock['requires_flag'] ?? '');
        if ($requiresFlag !== '' && empty($flags[$requiresFlag])) {
            return 'locked';
        }

        return 'available';
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed>|null $progress
     * @param array<string, mixed> $unlock
     * @param list<string> $clearedCodes
     * @param array<string, bool> $flags
     * @return array<string, mixed>
     */
    private function lobbyCopy(
        array $row,
        string $status,
        array $config,
        ?array $progress,
        array $unlock,
        array $clearedCodes,
        array $flags,
        int $explorationLevel,
        array $discovery,
        array $playerStats = [],
        array $vitalPenalties = []
    ): array {
        $type = (string) $row['node_type'];
        if ($type === 'teaser') {
            return [
                'title' => (string) $row['label'],
                'body' => (string) ($config['teaser_message'] ?? 'Em breve.'),
                'cta' => 'Fechar',
                'cta_enabled' => false,
            ];
        }

        if ($type === 'village') {
            $hotspots = array_values(array_filter((array) ($config['hotspots'] ?? []), 'is_array'));
            return [
                'title' => (string) $row['label'],
                'body' => $status === 'locked'
                    ? 'Conclua a fase 1-2 para acessar o vilarejo.'
                    : 'Explore a cena. Use a lupa no Bau escondido para achar o Mapa Rasgado.',
                'cta' => $status === 'locked' ? 'Bloqueado' : 'Explorar vilarejo',
                'cta_enabled' => $status !== 'locked',
                'hotspots' => $hotspots,
                'is_village' => true,
            ];
        }

        $waves = (int) ($row['wave_count'] ?? 6);
        $stage = (string) ($config['stage_code'] ?? $row['label']);
        $bossWaves = array_values(array_map('intval', (array) ($config['boss_waves'] ?? [3, 6])));
        $mapLevel = max(1, (int) ($config['map_level'] ?? 1));
        $power = max(1, (int) ($config['recommended_power'] ?? ($mapLevel * 40)));
        $energyStart = (float) PlayerVitalsService::MIN_ENERGY_TO_START;
        $energyTick = (float) PlayerVitalsService::TICK_COMBAT_ENERGY_COST;
        $story = (string) ($config['lore'] ?? "Fase {$stage} do Bosque Inicial.");
        $dossier = $this->buildStageDossier(
            $config,
            $progress,
            $unlock,
            $clearedCodes,
            $flags,
            $explorationLevel,
            $waves,
            $bossWaves,
            $mapLevel,
            $power,
            $energyStart,
            $energyTick,
            $story,
            $discovery,
            $playerStats,
            $vitalPenalties
        );

        if ($status === 'locked') {
            $why = 'Bloqueado.';
            if (!empty($unlock['requires_flag'])) {
                $why = 'Encontre o Mapa Rasgado no vilarejo.';
            } elseif (!empty($unlock['requires_clear'])) {
                $why = 'Conclua a fase anterior.';
            }

            return array_merge([
                'title' => (string) $row['label'],
                'body' => $why,
                'cta' => 'Bloqueado',
                'cta_enabled' => false,
                'preview' => [
                    'waves' => $waves,
                    'boss_waves' => $bossWaves,
                    'scene_url' => $row['scene_url'] !== null ? (string) $row['scene_url'] : null,
                ],
            ], $dossier);
        }

        return array_merge([
            'title' => (string) $row['label'],
            'body' => "Fase {$stage} · {$waves} ondas idle · chefes nas ondas marcadas. Combate automatico.",
            'cta' => $status === 'cleared' ? 'Repetir fase' : 'Entrar na fase',
            'cta_enabled' => true,
            'preview' => [
                'waves' => $waves,
                'boss_waves' => $bossWaves,
                'scene_url' => $row['scene_url'] !== null ? (string) $row['scene_url'] : null,
            ],
        ], $dossier);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed>|null $progress
     * @param array<string, mixed> $unlock
     * @param list<string> $clearedCodes
     * @param array<string, bool> $flags
     * @param list<int> $bossWaves
     * @return array<string, mixed>
     */
    private function buildStageDossier(
        array $config,
        ?array $progress,
        array $unlock,
        array $clearedCodes,
        array $flags,
        int $explorationLevel,
        int $waves,
        array $bossWaves,
        int $mapLevel,
        int $power,
        float $energyStart,
        float $energyTick,
        string $story,
        array $discovery,
        array $playerStats = [],
        array $vitalPenalties = []
    ): array {
        $threats = $this->buildThreats($config, $discovery['monsters'] ?? []);
        $lootPreview = $this->buildLootPreview($threats, $config, $discovery['items'] ?? []);
        $requirements = $this->buildRequirements($unlock, $config, $clearedCodes, $flags, $explorationLevel, $playerStats);
        $modifiers = [];
        foreach ((array) ($config['modifiers'] ?? []) as $mod) {
            if (!is_array($mod)) {
                continue;
            }
            $label = trim((string) ($mod['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $kind = strtolower((string) ($mod['kind'] ?? 'buff'));
            $effect = is_array($mod['effect'] ?? null) ? $mod['effect'] : null;
            $modifiers[] = [
                'kind' => in_array($kind, ['buff', 'debuff'], true) ? $kind : 'buff',
                'label' => $label,
                'detail' => (string) ($mod['detail'] ?? ''),
                'effect' => $effect,
            ];
        }

        $progressFlags = $this->parseJson($progress['flags_json'] ?? null);
        $bestMs = isset($progressFlags['best_clear_ms']) ? (int) $progressFlags['best_clear_ms'] : null;
        if ($bestMs !== null && $bestMs <= 0) {
            $bestMs = null;
        }
        $history = [];
        foreach ((array) ($progressFlags['clear_history'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $history[] = [
                'duration_ms' => (int) ($row['duration_ms'] ?? 0),
                'duration_label' => (string) ($row['duration_label'] ?? $this->formatDuration((int) ($row['duration_ms'] ?? 0))),
                'at' => (string) ($row['at'] ?? ''),
                'kills' => (int) ($row['kills'] ?? 0),
                'gold' => (int) ($row['gold'] ?? 0),
                'exploration_xp' => (int) ($row['exploration_xp'] ?? 0),
                'is_best' => (bool) ($row['is_best'] ?? false),
            ];
        }

        $hasSpecial = false;
        foreach ($lootPreview as $item) {
            if (!empty($item['special'])) {
                $hasSpecial = true;
                break;
            }
        }

        $playerPower = (int) ($playerStats['power'] ?? 0);
        $softReady = true;
        foreach ($requirements as $req) {
            if (($req['met'] ?? null) === false && !empty($req['soft'])) {
                $softReady = false;
                break;
            }
        }

        return [
            'summary_chips' => [
                'map_level' => $mapLevel,
                'power' => $power,
                'player_power' => $playerPower,
                'energy_start' => $energyStart,
                'energy_per_tick' => $energyTick,
                'waves' => $waves,
                'boss_waves' => $bossWaves,
            ],
            'story' => $story,
            'requirements' => $requirements,
            'threats' => $threats,
            'loot_preview' => $lootPreview,
            'modifiers' => $modifiers,
            'score' => [
                'best_clear_ms' => $bestMs,
                'best_clear_label' => $bestMs !== null ? $this->formatDuration($bestMs) : null,
                'clear_count' => (int) ($progress['clear_count'] ?? 0),
                'highest_wave' => (int) ($progress['highest_wave'] ?? 0),
                'history' => $history,
            ],
            'has_special_drops' => $hasSpecial,
            'soft_ready' => $softReady,
            'vital_notes' => array_values((array) ($vitalPenalties['notes'] ?? [])),
            'carry_locked_cols' => (int) ($vitalPenalties['carry_locked_cols'] ?? 0),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $progressByNodeId
     * @return array{monsters:array<string,bool>,items:array<string,bool>}
     */
    private function mergedDiscovery(array $progressByNodeId): array
    {
        $monsters = [];
        $items = [];
        foreach ($progressByNodeId as $row) {
            $flags = $this->parseJson($row['flags_json'] ?? null);
            foreach ((array) ($flags['discovered_monsters'] ?? []) as $code) {
                if (is_string($code) && $code !== '') {
                    $monsters[$code] = true;
                }
            }
            foreach ((array) ($flags['discovered_items'] ?? []) as $code) {
                if (is_string($code) && $code !== '') {
                    $items[$code] = true;
                }
            }
        }

        return ['monsters' => $monsters, 'items' => $items];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, bool> $discoveredMonsters
     * @return list<array<string, mixed>>
     */
    private function buildThreats(array $config, array $discoveredMonsters = []): array
    {
        $pool = array_values(array_filter((array) ($config['monster_pool'] ?? []), 'is_string'));
        $threats = [];
        foreach ($pool as $code) {
            $def = $this->catalog()->monster($code);
            $sprite = (string) ($def['sprite_key'] ?? 'treant');
            $known = !empty($discoveredMonsters[$code]);
            $threats[] = [
                'code' => $code,
                'name' => (string) ($def['name'] ?? $code),
                'art_url' => $this->monsterArt($sprite),
                'is_boss_candidate' => true,
                'element' => (string) ($def['element'] ?? ''),
                'resistance' => (string) ($def['resistance'] ?? ''),
                'loot' => array_values((array) ($def['loot'] ?? [])),
                'discovered' => $known,
            ];
        }

        return $threats;
    }

    /**
     * @param list<array<string, mixed>> $threats
     * @param array<string, mixed> $config
     * @param array<string, bool> $discoveredItems
     * @return list<array<string, mixed>>
     */
    private function buildLootPreview(array $threats, array $config, array $discoveredItems = []): array
    {
        $specialCodes = [];
        foreach ((array) ($config['special_drops'] ?? []) as $code) {
            if (is_string($code) && $code !== '') {
                $specialCodes[$code] = true;
            }
        }

        $byCode = [];
        foreach ($threats as $threat) {
            foreach ((array) ($threat['loot'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $code = (string) ($row['item_definition_code'] ?? '');
                if ($code === '') {
                    continue;
                }
                $byCode[$code] = [
                    'code' => $code,
                    'weight' => max((int) ($byCode[$code]['weight'] ?? 0), (int) ($row['weight'] ?? 0)),
                ];
            }
        }

        foreach (array_keys($specialCodes) as $code) {
            if (!isset($byCode[$code])) {
                $byCode[$code] = ['code' => $code, 'weight' => 0];
            }
        }

        $meta = $this->itemMeta(array_keys($byCode));
        $out = [];
        foreach ($byCode as $code => $row) {
            $info = $meta[$code] ?? ['name' => $code, 'rarity' => 'common', 'icon' => ''];
            $out[] = [
                'code' => $code,
                'name' => (string) $info['name'],
                'rarity' => (string) $info['rarity'],
                'icon' => (string) $info['icon'],
                'special' => isset($specialCodes[$code]),
                'discovered' => !empty($discoveredItems[$code]),
            ];
        }

        usort($out, static function (array $a, array $b): int {
            if ($a['special'] !== $b['special']) {
                return $a['special'] ? -1 : 1;
            }

            return strcmp((string) $a['name'], (string) $b['name']);
        });

        return $out;
    }

    /**
     * @param array<string, mixed> $unlock
     * @param array<string, mixed> $config
     * @param list<string> $clearedCodes
     * @param array<string, bool> $flags
     * @param array<string, mixed> $playerStats
     * @return list<array{label:string,met:?bool,soft?:bool}>
     */
    private function buildRequirements(
        array $unlock,
        array $config,
        array $clearedCodes,
        array $flags,
        int $explorationLevel,
        array $playerStats = []
    ): array {
        $reqs = [];

        foreach (array_values(array_filter((array) ($unlock['requires_clear'] ?? []), 'is_string')) as $code) {
            $reqs[] = [
                'label' => 'Concluir ' . $code,
                'met' => in_array($code, $clearedCodes, true),
                'soft' => false,
            ];
        }

        $requiresFlag = (string) ($unlock['requires_flag'] ?? '');
        if ($requiresFlag !== '') {
            $reqs[] = [
                'label' => $requiresFlag === 'torn_map' ? 'Possuir Mapa Rasgado' : ('Flag: ' . $requiresFlag),
                'met' => !empty($flags[$requiresFlag]),
                'soft' => false,
            ];
        }

        $entry = is_array($config['entry'] ?? null) ? $config['entry'] : [];
        $minLevel = (int) ($entry['min_exploration_level'] ?? 0);
        if ($minLevel > 0) {
            $reqs[] = [
                'label' => 'Exploracao Nv.' . $minLevel,
                'met' => $explorationLevel >= $minLevel,
                'soft' => true,
            ];
        }

        $recommended = max(0, (int) ($config['recommended_power'] ?? $entry['min_power'] ?? 0));
        if ($recommended > 0) {
            $playerPower = (int) ($playerStats['power'] ?? 0);
            $reqs[] = [
                'label' => "Poder {$playerPower}/{$recommended}",
                'met' => $playerPower >= $recommended,
                'soft' => true,
            ];
        }

        $minAttack = (int) ($entry['min_attack'] ?? 0);
        if ($minAttack > 0) {
            $atk = (float) ($playerStats['attack'] ?? 0);
            $reqs[] = [
                'label' => 'Ataque ' . (int) round($atk) . '/' . $minAttack,
                'met' => $atk >= $minAttack,
                'soft' => true,
            ];
        }

        $minDefense = (int) ($entry['min_defense'] ?? 0);
        if ($minDefense > 0) {
            $def = (float) ($playerStats['defense'] ?? 0);
            $reqs[] = [
                'label' => 'Defesa ' . (int) round($def) . '/' . $minDefense,
                'met' => $def >= $minDefense,
                'soft' => true,
            ];
        }

        foreach ((array) ($entry['requires_items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = (string) ($item['label'] ?? $item['code'] ?? '');
            if ($label === '') {
                continue;
            }
            $reqs[] = [
                'label' => $label,
                'met' => null,
                'soft' => true,
            ];
        }

        if ($reqs === [] && !empty($unlock['always'])) {
            $reqs[] = ['label' => 'Sem requisitos especiais', 'met' => true, 'soft' => false];
        }

        return $reqs;
    }

    /**
     * @param array{monsters:array<string,bool>,items:array<string,bool>} $discovery
     * @return array{code:string,name:string,found:int,total:int,entries:list<array<string,mixed>>}
     */
    private function buildArtifactAlbum(array $discovery): array
    {
        $entries = [];
        $found = 0;
        $discoveredItems = (array) ($discovery['items'] ?? []);

        if ($this->tableExists('item_definitions')) {
            $stmt = $this->pdo()->query("SELECT code, name, base_config FROM item_definitions WHERE status = 'active'");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $config = $this->parseJson($row['base_config'] ?? null);
                if (empty($config['campaign_artifact'])) {
                    continue;
                }
                $code = (string) ($row['code'] ?? '');
                if ($code === '') {
                    continue;
                }
                $known = !empty($discoveredItems[$code]);
                if ($known) {
                    $found++;
                }
                $entries[] = [
                    'code' => $code,
                    'name' => (string) ($row['name'] ?? $code),
                    'rarity' => (string) ($config['rarity'] ?? 'rare'),
                    'album' => (string) ($config['album'] ?? 'bosque_inicial'),
                    'discovered' => $known,
                    'special' => true,
                ];
            }
        }

        usort($entries, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return [
            'code' => 'bosque_inicial',
            'name' => 'Artefatos do Bosque',
            'found' => $found,
            'total' => count($entries),
            'entries' => $entries,
        ];
    }

    /**
     * @param list<string> $codes
     * @return array<string, array{name:string,rarity:string,icon:string}>
     */
    private function itemMeta(array $codes): array
    {
        $codes = array_values(array_unique(array_filter($codes, static fn ($c) => is_string($c) && $c !== '')));
        if ($codes === [] || !$this->tableExists('item_definitions')) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $stmt = $this->pdo()->prepare("SELECT code, name, base_config FROM item_definitions WHERE code IN ({$placeholders})");
        $stmt->execute($codes);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $code = (string) ($row['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $config = $this->parseJson($row['base_config'] ?? null);
            $out[$code] = [
                'name' => (string) ($row['name'] ?? $code),
                'rarity' => (string) ($config['rarity'] ?? 'common'),
                'icon' => (string) ($config['icon'] ?? ''),
            ];
        }

        return $out;
    }

    private function explorationLevel(int $playerId): int
    {
        try {
            if (!$this->tableExists('player_attributes')) {
                return 1;
            }
            $stmt = $this->pdo()->prepare("SELECT level FROM player_attributes WHERE player_id = :player_id AND attribute_code = 'exploration' LIMIT 1");
            $stmt->execute(['player_id' => $playerId]);
            $level = $stmt->fetchColumn();

            return max(1, (int) ($level ?: 1));
        } catch (\Throwable) {
            return 1;
        }
    }

    private function monsterArt(string $spriteKey): string
    {
        $map = [
            'treant' => '/assets/game/avatar_monsters/Characters/Square_CharacterIcon__81_.PNG',
            'brute' => '/assets/game/avatar_monsters/Characters/Square_CharacterIcon__68_.PNG',
            'crab' => '/assets/game/avatar_monsters/Characters/Square_CharacterIcon__77_.PNG',
            'lurker' => '/assets/game/avatar_monsters/Characters/Square_CharacterIcon__83_.PNG',
            'bat' => '/assets/game/avatar_monsters/Characters/Square_CharacterIcon__72_.PNG',
            'golem' => '/assets/game/avatar_monsters/Characters/Square_CharacterIcon__75_.PNG',
            'specter' => '/assets/game/avatar_monsters/Characters/Square_CharacterIcon__75_.PNG',
            'toad' => '/assets/game/avatar_monsters/Characters/Square_CharacterIcon__68_.PNG',
            'wisp' => '/assets/game/avatar_monsters/Characters/Square_CharacterIcon__72_.PNG',
        ];

        return $map[$spriteKey] ?? $map['treant'];
    }

    private function formatDuration(int $ms): string
    {
        $totalSec = max(0, (int) floor($ms / 1000));
        $min = (int) floor($totalSec / 60);
        $sec = $totalSec % 60;

        return sprintf('%d:%02d', $min, $sec);
    }

    /** @param list<int> $nodeIds @return array<int, array<string, mixed>> */
    private function progressByNodeId(int $playerId, array $nodeIds): array
    {
        if ($nodeIds === [] || !$this->tableExists('campaign_node_progress')) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($nodeIds), '?'));
        $sql = "SELECT * FROM campaign_node_progress WHERE player_id = ? AND node_id IN ({$placeholders})";
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([$playerId, ...$nodeIds]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int) $row['node_id']] = $row;
        }

        return $out;
    }

    /** @return array<string, bool> */
    private function playerFlags(int $playerId): array
    {
        if (!$this->tableExists('campaign_node_progress')) {
            return [];
        }

        $stmt = $this->pdo()->prepare('SELECT flags_json FROM campaign_node_progress WHERE player_id = :player_id');
        $stmt->execute(['player_id' => $playerId]);
        $flags = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            foreach ($this->parseJson($row['flags_json'] ?? null) as $flag => $value) {
                if ($value) {
                    $flags[(string) $flag] = true;
                }
            }
        }

        return $flags;
    }

    /** @return array<string, mixed> */
    private function parseJson(mixed $value): array
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

    private function tableExists(string $table): bool
    {
        try {
            $driver = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $this->pdo()->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name");
                $stmt->execute(['name' => $table]);
                return (bool) $stmt->fetchColumn();
            }
            $stmt = $this->pdo()->query("SHOW TABLES LIKE " . $this->pdo()->quote($table));
            return (bool) ($stmt && $stmt->fetchColumn());
        } catch (\Throwable) {
            return false;
        }
    }

    private function catalog(): ExpeditionArenaCatalogService
    {
        return $this->catalog ??= new ExpeditionArenaCatalogService($this->pdo());
    }

    private function pdo(): PDO
    {
        return $this->pdo ??= DB::pdo();
    }
}
