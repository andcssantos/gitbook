<?php

namespace App\Game\Campaign\Services;

use App\Game\Expeditions\Services\ExpeditionArenaCatalogService;
use App\Game\Expeditions\Services\ExpeditionRunModifiersService;
use App\Game\Inventory\Services\InventoryStateService;
use App\Game\Player\Services\PlayerAttributeService;
use App\Game\Player\Services\PlayerVitalsService;
use App\Support\DB;
use App\Support\PublicId;
use PDO;

class CampaignStageRunService
{
    public function __construct(
        private ?PDO $pdo = null,
        private ?CampaignWorldService $worlds = null,
        private ?CampaignProgressService $progress = null,
        private ?PlayerVitalsService $vitals = null,
        private ?ExpeditionArenaCatalogService $catalog = null,
        private ?ExpeditionRunModifiersService $runModifiers = null,
        private ?PlayerAttributeService $attributes = null,
        private ?InventoryStateService $inventory = null
    ) {
        $this->worlds ??= new CampaignWorldService($this->pdo);
        $this->progress ??= new CampaignProgressService($this->pdo);
        $this->vitals ??= new PlayerVitalsService($this->pdo);
        $this->catalog ??= new ExpeditionArenaCatalogService($this->pdo);
        $this->runModifiers ??= new ExpeditionRunModifiersService($this->pdo);
        $this->attributes ??= new PlayerAttributeService($this->pdo);
        $this->inventory ??= new InventoryStateService($this->pdo);
    }

    /** @return array<string, mixed>|null */
    public function pendingLootForPlayer(int $playerId): ?array
    {
        if (!$this->tableExists('campaign_stage_runs')) {
            return null;
        }

        $stmt = $this->pdo()->prepare("SELECT r.*, n.code AS node_code, n.label AS node_label, n.scene_url, n.wave_count, n.config_json, n.node_type
            FROM campaign_stage_runs r
            INNER JOIN campaign_nodes n ON n.id = r.node_id
            WHERE r.player_id = :player_id AND r.status = 'awaiting_loot'
            ORDER BY r.id DESC
            LIMIT 1");
        $stmt->execute(['player_id' => $playerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->mapRun($row) : null;
    }

    /** @return array<string, mixed>|null */
    public function activeForPlayer(int $playerId): ?array
    {
        if (!$this->tableExists('campaign_stage_runs')) {
            return null;
        }

        $stmt = $this->pdo()->prepare("SELECT r.*, n.code AS node_code, n.label AS node_label, n.scene_url, n.wave_count, n.config_json, n.node_type
            FROM campaign_stage_runs r
            INNER JOIN campaign_nodes n ON n.id = r.node_id
            WHERE r.player_id = :player_id AND r.status = 'active'
            ORDER BY r.id DESC
            LIMIT 1");
        $stmt->execute(['player_id' => $playerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->mapRun($row) : null;
    }

    /** @return array<string, mixed> */
    public function start(int $playerId, string $nodeCode): array
    {
        $this->vitals->assertCanStartExpedition($playerId);

        $node = $this->nodeByCode($nodeCode);
        if ($node === null || (string) ($node['node_type'] ?? '') !== 'stage') {
            throw new \RuntimeException('Fase de campanha nao encontrada.');
        }

        $world = $this->worlds->worldForPlayer($playerId, $this->worldCodeForNode((int) $node['world_id']));
        $mapped = null;
        foreach (($world['nodes'] ?? []) as $entry) {
            if (($entry['code'] ?? '') === $nodeCode) {
                $mapped = $entry;
                break;
            }
        }
        if ($mapped === null || !empty($mapped['locked']) || !($mapped['available'] ?? false)) {
            throw new \RuntimeException('Esta fase ainda esta bloqueada.');
        }

        $existing = $this->activeForPlayer($playerId);
        if ($existing !== null) {
            $this->leave($playerId);
        }
        $pending = $this->pendingLootForPlayer($playerId);
        if ($pending !== null) {
            $this->leave($playerId);
        }

        $maxHp = $this->resolveMaxHp($playerId);
        $encounters = $this->spawnWave($node, 1);
        $publicId = PublicId::uuid();

        $this->pdo()->prepare('INSERT INTO campaign_stage_runs (
            public_id, player_id, node_id, status, current_wave, current_hp, max_hp, combat_json, encounters_json, started_at
        ) VALUES (
            :public_id, :player_id, :node_id, \'active\', 1, :current_hp, :max_hp, :combat_json, :encounters_json, CURRENT_TIMESTAMP
        )')->execute([
            'public_id' => $publicId,
            'player_id' => $playerId,
            'node_id' => (int) $node['id'],
            'current_hp' => $maxHp,
            'max_hp' => $maxHp,
            'combat_json' => json_encode([
                'player_cd' => 0,
                'enemy_cd' => 0.2,
                'last_tick_at' => microtime(true),
                'started_at_ms' => (int) round(microtime(true) * 1000),
                'wave_started_at_ms' => (int) round(microtime(true) * 1000),
                'wave_limit_ms' => !empty($encounters[0]['is_boss']) ? 150000 : 120000,
                'staging_loot' => [],
                'totals' => ['gold' => 0, 'exploration_xp' => 0, 'kills' => 0],
                'stage_modifiers' => $this->stageModifiersFromConfig($this->parseJson($node['config_json'] ?? null)),
            ], JSON_THROW_ON_ERROR),
            'encounters_json' => json_encode($encounters, JSON_THROW_ON_ERROR),
        ]);

        $this->progress->touchPlayed($playerId, (int) $node['id'], 1);
        $vitalPenalties = $this->vitals->campaignSoftPenalties($playerId);
        $this->vitals->spendEnergy(
            $playerId,
            PlayerVitalsService::MIN_ENERGY_TO_START,
            ['energy_cost_multiplier' => $vitalPenalties['energy_cost_multiplier']],
            'campaign_stage_start'
        );

        $active = $this->activeForPlayer($playerId);
        if ($active === null) {
            throw new \RuntimeException('Nao foi possivel iniciar a fase.');
        }

        return $active;
    }

    /** @return array<string, mixed> */
    public function leave(int $playerId): array
    {
        $active = $this->activeForPlayer($playerId);
        if ($active !== null) {
            $this->pdo()->prepare("UPDATE campaign_stage_runs SET status = 'abandoned', ended_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE public_id = :public_id AND player_id = :player_id")
                ->execute([
                    'public_id' => $active['public_id'],
                    'player_id' => $playerId,
                ]);

            $this->progress->updateHighestWave($playerId, (int) $active['node_id'], (int) $active['current_wave']);

            return ['left' => true, 'run' => null, 'node_code' => $active['node_code']];
        }

        $pending = $this->pendingLootForPlayer($playerId);
        if ($pending !== null) {
            $this->pdo()->prepare("UPDATE campaign_stage_runs SET status = 'cleared', ended_at = COALESCE(ended_at, CURRENT_TIMESTAMP), updated_at = CURRENT_TIMESTAMP WHERE public_id = :public_id AND player_id = :player_id")
                ->execute([
                    'public_id' => $pending['public_id'],
                    'player_id' => $playerId,
                ]);

            return ['left' => true, 'run' => null, 'node_code' => $pending['node_code'], 'loot_discarded' => true];
        }

        return ['left' => true, 'run' => null];
    }

    /** @param array<string, mixed> $node @return list<array<string, mixed>> */
    public function spawnWave(array $node, int $wave): array
    {
        $config = $this->parseJson($node['config_json'] ?? null);
        $pool = array_values(array_filter((array) ($config['monster_pool'] ?? ['bosque_treant']), 'is_string'));
        if ($pool === []) {
            $pool = ['bosque_treant'];
        }

        $bossWaves = array_map('intval', (array) ($config['boss_waves'] ?? [3, 6]));
        $isBossWave = in_array($wave, $bossWaves, true);
        $count = $this->waveMonsterCount($wave, $isBossWave, $config);

        $encounters = [];
        for ($i = 0; $i < $count; $i++) {
            $isBoss = $isBossWave && $i === 0;
            $code = $pool[($wave - 1 + $i) % count($pool)];
            $encounters[] = $this->buildEncounter($code, $wave, $isBoss);
        }

        return $encounters;
    }

    /** @param array<string, mixed> $config */
    private function waveMonsterCount(int $wave, bool $isBossWave, array $config): int
    {
        $forced = (int) ($config['monsters_per_wave'] ?? 0);
        if ($forced > 0) {
            return max(1, min(3, $forced));
        }

        if ($isBossWave) {
            return ($wave >= 6 && mt_rand(1, 100) <= 55) ? 2 : 1;
        }
        if ($wave >= 5 && mt_rand(1, 100) <= 55) {
            return mt_rand(1, 100) <= 25 ? 3 : 2;
        }
        if ($wave >= 3 && mt_rand(1, 100) <= 42) {
            return 2;
        }
        if ($wave >= 2 && mt_rand(1, 100) <= 28) {
            return 2;
        }

        return 1;
    }

    /** @return array<string, mixed> */
    private function buildEncounter(string $code, int $wave, bool $isBoss): array
    {
        $def = $this->catalog->monster($code) ?? [
            'code' => $code,
            'name' => $code,
            'sprite_key' => 'treant',
            'base_hp' => 120,
            'base_attack' => 12,
            'base_defense' => 6,
            'crit_rate' => 0.08,
            'reward_gold_min' => 3,
            'reward_gold_max' => 6,
            'reward_xp_min' => 10,
            'reward_xp_max' => 16,
        ];

        $scale = 1 + (($wave - 1) * 0.12);
        if ($isBoss) {
            $scale *= 1.65;
        }

        $maxHp = (int) max(40, round(((int) ($def['base_hp'] ?? 120)) * $scale));

        return [
            'public_id' => PublicId::uuid(),
            'code' => (string) ($def['code'] ?? $code),
            'name' => (string) ($def['name'] ?? $code) . ($isBoss ? ' (Chefe)' : ''),
            'sprite_key' => (string) ($def['sprite_key'] ?? 'treant'),
            'is_boss' => $isBoss,
            'current_hp' => $maxHp,
            'max_hp' => $maxHp,
            'attack' => (float) round(((float) ($def['base_attack'] ?? 12)) * $scale, 2),
            'defense' => (float) round(((float) ($def['base_defense'] ?? 6)) * $scale, 2),
            'crit_rate' => (float) ($def['crit_rate'] ?? 0.08),
            'reward_gold_min' => (int) ($def['reward_gold_min'] ?? 3),
            'reward_gold_max' => (int) ($def['reward_gold_max'] ?? 6),
            'reward_xp_min' => (int) ($def['reward_xp_min'] ?? 10),
            'reward_xp_max' => (int) ($def['reward_xp_max'] ?? 16),
            'art_url' => $this->monsterArt((string) ($def['sprite_key'] ?? 'treant')),
        ];
    }

    public function resolveMaxHp(int $playerId): int
    {
        $byCode = [];
        $equipmentLife = 0.0;
        try {
            $run = $this->runModifiers->forPlayer($playerId, 'bosque_inicial');
            $byCode = (array) ($run['stats_by_code'] ?? []);
            $snapshot = (array) ($run['combat_snapshot'] ?? []);
            $equipmentLife = max(0.0, (float) (($snapshot['player_power']['life'] ?? 0)));
        } catch (\Throwable) {
            $this->attributes->ensureDefaults($playerId);
            foreach ($this->attributes->listForPlayer($playerId) as $attribute) {
                $byCode[(string) ($attribute['code'] ?? '')] = (float) ($attribute['value'] ?? 0);
            }
            try {
                $snapshot = $this->inventory->combatSnapshotForPlayer($playerId);
                $equipmentLife = max(0.0, (float) (($snapshot['player_power']['life'] ?? 0)));
            } catch (\Throwable) {
                $equipmentLife = 0.0;
            }
        }

        $defense = max(1.0, (float) ($byCode['defense'] ?? 5));
        $vitality = max(0.0, (float) ($byCode['vitality'] ?? 0));
        $maxHealthBonus = max(0.0, (float) ($byCode['max_health'] ?? 0));
        $armor = max(0.0, (float) ($byCode['armor'] ?? 0));
        $level = max(1, (int) ($byCode['level'] ?? 1));

        return (int) max(50, round(
            100
            + ($level * 8)
            + ($defense * 2)
            + ($vitality * 3.5)
            + ($maxHealthBonus * 0.6)
            + ($armor * 0.35)
            + ($equipmentLife * 0.25)
        ));
    }

    /** @return array<string, mixed> */
    public function playerCombatStats(int $playerId): array
    {
        $byCode = [];
        $equipmentAttack = 0.0;
        try {
            $run = $this->runModifiers->forPlayer($playerId, 'bosque_inicial');
            $byCode = (array) ($run['stats_by_code'] ?? []);
            $snapshot = (array) ($run['combat_snapshot'] ?? []);
            $equipmentAttack = max(0.0, (float) (($snapshot['player_power']['attack'] ?? 0)));
        } catch (\Throwable) {
            $this->attributes->ensureDefaults($playerId);
            foreach ($this->attributes->listForPlayer($playerId) as $attribute) {
                $byCode[(string) ($attribute['code'] ?? '')] = (float) ($attribute['value'] ?? 0);
            }
        }

        $strength = max(1.0, (float) ($byCode['strength'] ?? 5));
        $agility = max(1.0, (float) ($byCode['agility'] ?? 5));
        $defenseAttr = max(1.0, (float) ($byCode['defense'] ?? 5));
        $attackPower = max(0.0, (float) ($byCode['attack_power'] ?? 0));
        $critChance = max(0.0, (float) ($byCode['critical_chance'] ?? 0));
        $attack = max(8.0, $strength + (($attackPower + $equipmentAttack) * 0.9));
        $defense = max(5.0, $defenseAttr + ($strength * 0.15));
        $power = (int) round(($attack * 2.2) + ($defense * 1.8) + ($agility * 0.5));

        return [
            'attack' => $attack,
            'defense' => $defense,
            'power' => $power,
            'crit_rate' => min(0.45, 0.05 + ($strength * 0.008) + ($agility * 0.004) + ($critChance * 0.01)),
            'crit_damage_bonus' => 0.1,
            'damage_reduction' => min(0.35, max(0.0, $defense * 0.01)),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public function mapRun(array $row): array
    {
        $encounters = $this->parseJson($row['encounters_json'] ?? null);
        $combat = $this->parseJson($row['combat_json'] ?? null);
        $config = $this->parseJson($row['config_json'] ?? null);
        $waveCount = max(1, (int) ($row['wave_count'] ?? 6));

        return [
            'public_id' => (string) $row['public_id'],
            'player_id' => (int) $row['player_id'],
            'node_id' => (int) $row['node_id'],
            'node_code' => (string) ($row['node_code'] ?? ''),
            'node_label' => (string) ($row['node_label'] ?? ''),
            'scene_url' => $row['scene_url'] !== null ? (string) $row['scene_url'] : null,
            'status' => (string) $row['status'],
            'current_wave' => (int) $row['current_wave'],
            'wave_count' => $waveCount,
            'current_hp' => (int) $row['current_hp'],
            'max_hp' => (int) $row['max_hp'],
            'encounters' => array_values($encounters),
            'combat' => $combat,
            'boss_waves' => array_values(array_map('intval', (array) ($config['boss_waves'] ?? [3, 6]))),
            'stage_code' => (string) ($config['stage_code'] ?? $row['node_label'] ?? ''),
            'stage_modifiers' => array_values((array) ($combat['stage_modifiers'] ?? $this->stageModifiersFromConfig($config))),
            'started_at' => $row['started_at'] ?? null,
            'ended_at' => $row['ended_at'] ?? null,
            'vitals' => $this->vitals->snapshot((int) $row['player_id']),
            'vital_penalties' => $this->vitals->campaignSoftPenalties((int) $row['player_id']),
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return list<array<string, mixed>>
     */
    private function stageModifiersFromConfig(array $config): array
    {
        $out = [];
        foreach ((array) ($config['modifiers'] ?? []) as $mod) {
            if (!is_array($mod)) {
                continue;
            }
            $label = trim((string) ($mod['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $kind = strtolower((string) ($mod['kind'] ?? 'buff'));
            $out[] = [
                'kind' => in_array($kind, ['buff', 'debuff'], true) ? $kind : 'buff',
                'label' => $label,
                'detail' => (string) ($mod['detail'] ?? ''),
                'effect' => is_array($mod['effect'] ?? null) ? $mod['effect'] : null,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function nodeByCode(string $code): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM campaign_nodes WHERE code = :code AND is_active = 1 LIMIT 1');
        $stmt->execute(['code' => $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function worldCodeForNode(int $worldId): string
    {
        $stmt = $this->pdo()->prepare('SELECT code FROM campaign_worlds WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $worldId]);
        $code = $stmt->fetchColumn();

        return is_string($code) && $code !== '' ? $code : 'mundo_1_bosque';
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
        ];

        return $map[$spriteKey] ?? $map['treant'];
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
