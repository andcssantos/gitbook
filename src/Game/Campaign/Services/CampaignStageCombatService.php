<?php

namespace App\Game\Campaign\Services;

use App\Game\Expeditions\Services\ExpeditionArenaCatalogService;
use App\Game\Market\Services\PlayerCurrencyService;
use App\Game\Player\Services\PlayerAttributeService;
use App\Game\Player\Services\PlayerVitalsService;
use App\Support\DB;
use App\Support\PublicId;
use PDO;

class CampaignStageCombatService
{
    private const WAVE_LIMIT_MS = 120000;
    private const BOSS_WAVE_LIMIT_MS = 150000;

    public function __construct(
        private ?PDO $pdo = null,
        private ?CampaignStageRunService $runs = null,
        private ?CampaignProgressService $progress = null,
        private ?PlayerVitalsService $vitals = null,
        private ?PlayerAttributeService $attributes = null,
        private ?PlayerCurrencyService $currencies = null,
        private ?ExpeditionArenaCatalogService $catalog = null,
        private ?CampaignPotionService $potions = null
    ) {
        $this->runs ??= new CampaignStageRunService($this->pdo);
        $this->progress ??= new CampaignProgressService($this->pdo);
        $this->vitals ??= new PlayerVitalsService($this->pdo);
        $this->attributes ??= new PlayerAttributeService($this->pdo);
        $this->currencies ??= new PlayerCurrencyService($this->pdo);
        $this->catalog ??= new ExpeditionArenaCatalogService($this->pdo);
        $this->potions ??= new CampaignPotionService($this->pdo);
    }

    /** @return array<string, mixed> */
    public function tick(int $playerId): array
    {
        $run = $this->runs->activeForPlayer($playerId);
        if ($run === null) {
            throw new \RuntimeException('Nenhuma fase ativa.');
        }

        $events = [];
        $rewards = ['gold' => 0, 'exploration_xp' => 0, 'wallet_balance' => null, 'items' => []];
        $combat = is_array($run['combat'] ?? null) ? $run['combat'] : [];
        $stagingLoot = array_values(array_filter((array) ($combat['staging_loot'] ?? []), 'is_array'));
        $totals = is_array($combat['totals'] ?? null) ? $combat['totals'] : ['gold' => 0, 'exploration_xp' => 0, 'kills' => 0];
        $nowMs = (int) round(microtime(true) * 1000);

        if (empty($combat['wave_started_at_ms'])) {
            $combat['wave_started_at_ms'] = $nowMs;
        }
        if (empty($combat['wave_limit_ms'])) {
            $combat['wave_limit_ms'] = $this->waveLimitMs((array) (($run['encounters'] ?? [])[0] ?? []));
        }

        $elapsed = max(0, $nowMs - (int) $combat['wave_started_at_ms']);
        $remainingMs = max(0, (int) $combat['wave_limit_ms'] - $elapsed);
        if ($remainingMs <= 0) {
            return $this->failWaveTimeout($playerId, $run, $events, $rewards, $combat);
        }

        try {
            $vitalPenalties = $this->vitals->campaignSoftPenalties($playerId);
            $energyCost = PlayerVitalsService::TICK_COMBAT_ENERGY_COST
                * $this->stageModifierMult($run, 'energy_cost_mult', 1.0)
                * (float) ($vitalPenalties['energy_cost_multiplier'] ?? 1.0);
            $this->vitals->spendEnergy($playerId, $energyCost, [], 'campaign_stage_tick');
        } catch (\Throwable) {
            $events[] = ['type' => 'energy_depleted', 'message' => 'Sem energia. Saia e descanse.', 'target' => 'player'];
            return $this->payload($run, $events, $rewards, false, false, false, $remainingMs);
        }

        try {
            $this->vitals->drainCampaignTick($playerId);
        } catch (\Throwable) {
            // vitais secundarios nao interrompem o tick
        }

        $encounters = array_values(array_filter((array) ($run['encounters'] ?? []), static fn ($e) => is_array($e) && (int) ($e['current_hp'] ?? 0) > 0));
        if ($encounters === []) {
            $node = $this->runs->nodeByCode((string) $run['node_code']);
            if ($node === null) {
                throw new \RuntimeException('Fase invalida.');
            }
            $encounters = $this->runs->spawnWave($node, (int) $run['current_wave']);
            $combat['wave_started_at_ms'] = $nowMs;
            $combat['wave_limit_ms'] = $this->waveLimitMs($encounters[0] ?? []);
            $remainingMs = (int) $combat['wave_limit_ms'];
            if (count($encounters) > 1) {
                $events[] = [
                    'type' => 'wave_spawn',
                    'message' => count($encounters) . ' inimigos entram juntos na onda',
                    'target' => 'monster',
                ];
            }
        }

        $playerStats = $this->runs->playerCombatStats($playerId);
        $vitalPenalties = $this->vitals->campaignSoftPenalties($playerId);
        $playerAttackMult = $this->stageModifierMult($run, 'player_attack_mult', 1.0)
            * (float) ($vitalPenalties['player_attack_mult'] ?? 1.0);
        $monsterAttackMult = $this->stageModifierMult($run, 'monster_attack_mult', 1.0);
        $playerCritBonus = $this->stageModifierSum($run, 'player_crit_bonus');
        $playerHp = (int) $run['current_hp'];
        $maxHp = (int) $run['max_hp'];
        $stageCleared = false;
        $defeated = false;
        $discoveredMonsters = [];
        $discoveredItems = [];

        // Jogador foca 1 monstro (aleatorio); as vezes espalha um segundo golpe menor.
        $focusIndex = array_rand($encounters);
        $focusIndexes = [$focusIndex];
        if (count($encounters) > 1 && (mt_rand() / mt_getrandmax()) < 0.22) {
            $others = array_values(array_filter(array_keys($encounters), static fn ($i) => $i !== $focusIndex));
            if ($others !== []) {
                $focusIndexes[] = $others[array_rand($others)];
            }
        }

        foreach ($focusIndexes as $hitOrder => $idx) {
            $target = $encounters[$idx];
            $targetName = (string) ($target['name'] ?? 'Inimigo');
            $targetId = (string) ($target['public_id'] ?? '');
            $hit = $this->rollDamage(
                (float) $playerStats['attack'] * $playerAttackMult * ($hitOrder === 0 ? 1.0 : 0.55),
                (float) ($target['defense'] ?? 0),
                (float) ($playerStats['crit_rate'] ?? 0.08) + $playerCritBonus,
                (float) ($playerStats['crit_damage_bonus'] ?? 0.1)
            );
            $encounters[$idx]['current_hp'] = max(0, (int) $target['current_hp'] - $hit['amount']);
            $events[] = [
                'type' => $hit['critical'] ? 'player_crit' : 'player_hit',
                'message' => $hit['critical']
                    ? "Critico em {$targetName}: {$hit['amount']}"
                    : "Voce atingiu {$targetName} ({$hit['amount']})",
                'damage' => $hit['amount'],
                'target' => 'monster',
                'encounter_public_id' => $targetId,
                'critical' => $hit['critical'],
            ];
        }

        // Resolve mortes apos os golpes do jogador.
        $survivors = [];
        foreach ($encounters as $monster) {
            if ((int) ($monster['current_hp'] ?? 0) > 0) {
                $survivors[] = $monster;
                continue;
            }

            $targetName = (string) ($monster['name'] ?? 'Inimigo');
            $discoveredMonsters[] = (string) ($monster['code'] ?? '');
            $killReward = $this->grantKillRewards($playerId, $monster);
            $rewards['gold'] += $killReward['gold'];
            $rewards['exploration_xp'] += $killReward['exploration_xp'];
            $rewards['wallet_balance'] = $killReward['wallet_balance'];
            $totals['gold'] = (int) ($totals['gold'] ?? 0) + $killReward['gold'];
            $totals['exploration_xp'] = (int) ($totals['exploration_xp'] ?? 0) + $killReward['exploration_xp'];
            $totals['kills'] = (int) ($totals['kills'] ?? 0) + 1;

            $events[] = [
                'type' => !empty($monster['is_boss']) ? 'boss_kill' : 'monster_kill',
                'message' => "{$targetName} derrotado",
                'target' => 'monster',
                'damage' => 0,
                'encounter_public_id' => (string) ($monster['public_id'] ?? ''),
            ];
            if ($killReward['gold'] > 0) {
                $events[] = ['type' => 'reward_gold', 'message' => "Ouro +{$killReward['gold']}G", 'damage' => $killReward['gold'], 'target' => 'player'];
            }
            if ($killReward['exploration_xp'] > 0) {
                $events[] = ['type' => 'reward_xp', 'message' => "Exploracao +{$killReward['exploration_xp']} XP", 'damage' => $killReward['exploration_xp'], 'target' => 'player'];
            }

            $nodeCfg = [];
            try {
                $nodeRow = $this->runs->nodeByCode((string) ($run['node_code'] ?? ''));
                if (is_string($nodeRow['config_json'] ?? null)) {
                    $nodeCfg = json_decode((string) $nodeRow['config_json'], true, 512, JSON_THROW_ON_ERROR) ?: [];
                }
            } catch (\Throwable) {
                $nodeCfg = [];
            }
            $drops = $this->rollItemDrops((string) ($monster['code'] ?? ''), !empty($monster['is_boss']), is_array($nodeCfg) ? $nodeCfg : []);
            foreach ($drops as $drop) {
                $stagingLoot[] = $drop;
                $rewards['items'][] = $drop;
                $discoveredItems[] = (string) ($drop['definition_code'] ?? '');
                $events[] = [
                    'type' => 'item_drop',
                    'message' => 'Drop: ' . ($drop['name'] ?? $drop['definition_code']) . ' x' . $drop['quantity'],
                    'item' => $drop,
                    'target' => 'player',
                ];
            }
        }
        $encounters = array_values($survivors);

        if ($encounters === []) {
            $waveCount = (int) $run['wave_count'];
            $currentWave = (int) $run['current_wave'];
            if ($currentWave >= $waveCount) {
                $stageCleared = true;
                $clearGold = 12 + ($waveCount * 2);
                $clearXp = 40 + ($waveCount * 8);
                try {
                    $rewards['wallet_balance'] = $this->currencies->credit($playerId, 'gold', $clearGold, 'campaign_stage_clear', 'campaign_node', (string) $run['node_code']);
                } catch (\Throwable) {
                }
                try {
                    $this->attributes->grantXp($playerId, 'exploration', $clearXp, 'campaign_stage_clear', (string) $run['node_code'], 'stage_clear');
                } catch (\Throwable) {
                }
                $rewards['gold'] += $clearGold;
                $rewards['exploration_xp'] += $clearXp;
                $totals['gold'] = (int) ($totals['gold'] ?? 0) + $clearGold;
                $totals['exploration_xp'] = (int) ($totals['exploration_xp'] ?? 0) + $clearXp;

                $startedMs = (float) ($combat['started_at_ms'] ?? (microtime(true) * 1000));
                $durationMs = max(0, (int) round((microtime(true) * 1000) - $startedMs));
                if ($durationMs <= 0 && !empty($run['started_at'])) {
                    $durationMs = max(0, (int) ((time() - strtotime((string) $run['started_at'])) * 1000));
                }

                $best = $this->progress->markClearedWithTime(
                    $playerId,
                    (int) $run['node_id'],
                    $waveCount,
                    $durationMs,
                    [
                        'kills' => (int) ($totals['kills'] ?? 0),
                        'gold' => (int) ($totals['gold'] ?? 0),
                        'exploration_xp' => (int) ($totals['exploration_xp'] ?? 0),
                    ]
                );
                foreach ($stagingLoot as $lootRow) {
                    if (is_array($lootRow)) {
                        $discoveredItems[] = (string) ($lootRow['definition_code'] ?? '');
                    }
                }
                $this->progress->discover($playerId, (int) $run['node_id'], $discoveredMonsters, $discoveredItems);

                $combatOut = [
                    'last_tick_at' => microtime(true),
                    'started_at_ms' => $combat['started_at_ms'] ?? null,
                    'staging_loot' => array_values($stagingLoot),
                    'totals' => $totals,
                    'duration_ms' => $durationMs,
                    'best_clear_ms' => $best['best_clear_ms'],
                    'is_best' => $best['is_best'],
                    'loot_committed' => false,
                ];

                $this->pdo()->prepare("UPDATE campaign_stage_runs SET
                    status = 'awaiting_loot',
                    current_hp = :hp,
                    encounters_json = '[]',
                    combat_json = :combat,
                    ended_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                    WHERE public_id = :public_id")
                    ->execute([
                        'hp' => $playerHp,
                        'combat' => json_encode($combatOut, JSON_THROW_ON_ERROR),
                        'public_id' => $run['public_id'],
                    ]);

                $events[] = ['type' => 'stage_cleared', 'message' => 'Fase concluida! Escolha o loot.', 'target' => 'player'];
                $events[] = ['type' => 'reward_gold', 'message' => '+' . $clearGold . 'G', 'damage' => $clearGold, 'target' => 'player'];
                $events[] = ['type' => 'reward_xp', 'message' => '+' . $clearXp . ' XP', 'damage' => $clearXp, 'target' => 'player'];

                $pending = $this->runs->pendingLootForPlayer($playerId);
                return $this->payload($pending ?? array_merge($run, [
                    'status' => 'awaiting_loot',
                    'encounters' => [],
                    'current_hp' => $playerHp,
                    'combat' => $combatOut,
                ]), $events, $rewards, true, false, false, 0);
            }

            $nextWave = $currentWave + 1;
            $node = $this->runs->nodeByCode((string) $run['node_code']);
            $encounters = $node ? $this->runs->spawnWave($node, $nextWave) : [];
            $run['current_wave'] = $nextWave;
            $combat['wave_started_at_ms'] = $nowMs;
            $combat['wave_limit_ms'] = $this->waveLimitMs($encounters[0] ?? []);
            $remainingMs = (int) $combat['wave_limit_ms'];
            $this->progress->updateHighestWave($playerId, (int) $run['node_id'], $nextWave);
            $alive = count($encounters);
            $events[] = [
                'type' => 'wave_advance',
                'message' => "Onda {$nextWave}/{$waveCount}" . ($alive > 1 ? " — {$alive} inimigos juntos" : ''),
                'target' => 'player',
            ];
        } else {
            // Todos os monstros vivos podem atacar no mesmo turno.
            foreach ($encounters as $monster) {
                // 70% de chance de atacar neste tick (sensacao de mesa: nem sempre todos batem).
                if ((mt_rand() / mt_getrandmax()) > 0.72 && count($encounters) > 1) {
                    continue;
                }
                $targetName = (string) ($monster['name'] ?? 'Inimigo');
                $monsterHit = $this->rollDamage(
                    (float) ($monster['attack'] ?? 10) * $monsterAttackMult,
                    0,
                    (float) ($monster['crit_rate'] ?? 0.06),
                    0.0
                );
                $reduced = max(1, (int) round($monsterHit['amount'] * (1 - (float) ($playerStats['damage_reduction'] ?? 0))));
                $playerHp = max(0, $playerHp - $reduced);
                $events[] = [
                    'type' => $monsterHit['critical'] ? 'monster_crit' : 'monster_hit',
                    'message' => $monsterHit['critical']
                        ? "{$targetName} critico: -{$reduced} HP"
                        : "{$targetName} atacou: -{$reduced} HP",
                    'damage' => $reduced,
                    'target' => 'player',
                    'encounter_public_id' => (string) ($monster['public_id'] ?? ''),
                    'critical' => $monsterHit['critical'],
                ];
                if ($playerHp <= 0) {
                    break;
                }
            }

            if ($playerHp > 0) {
                $auto = $this->potions->autoHealIfNeeded($playerId, $playerHp, $maxHp);
                $playerHp = (int) $auto['hp'];
                foreach ($auto['events'] as $ev) {
                    $events[] = $ev;
                }
            }

            if ($playerHp <= 0) {
                $defeated = true;
                $events[] = ['type' => 'player_defeat', 'message' => 'Derrotado — tente de novo', 'target' => 'player'];
                try {
                    $this->vitals->spendEnergy($playerId, 2.0, [], 'campaign_stage_defeat');
                } catch (\Throwable) {
                }
                $node = $this->runs->nodeByCode((string) $run['node_code']);
                $encounters = $node ? $this->runs->spawnWave($node, 1) : [];
                $playerHp = $maxHp;
                $run['current_wave'] = 1;
                $stagingLoot = [];
                $totals = ['gold' => (int) ($totals['gold'] ?? 0), 'exploration_xp' => (int) ($totals['exploration_xp'] ?? 0), 'kills' => 0];
                $combat['wave_started_at_ms'] = $nowMs;
                $combat['wave_limit_ms'] = $this->waveLimitMs($encounters[0] ?? []);
                $remainingMs = (int) $combat['wave_limit_ms'];
                $this->progress->touchPlayed($playerId, (int) $run['node_id'], 1);
            }
        }

        $combatOut = [
            'last_tick_at' => microtime(true),
            'started_at_ms' => $combat['started_at_ms'] ?? (microtime(true) * 1000),
            'wave_started_at_ms' => $combat['wave_started_at_ms'] ?? $nowMs,
            'wave_limit_ms' => $combat['wave_limit_ms'] ?? self::WAVE_LIMIT_MS,
            'staging_loot' => array_values($stagingLoot),
            'totals' => $totals,
        ];

        $this->progress->discover($playerId, (int) $run['node_id'], $discoveredMonsters, $discoveredItems);

        $this->pdo()->prepare('UPDATE campaign_stage_runs SET
            current_wave = :wave,
            current_hp = :hp,
            encounters_json = :encounters,
            combat_json = :combat,
            updated_at = CURRENT_TIMESTAMP
            WHERE public_id = :public_id AND player_id = :player_id
        ')->execute([
            'wave' => (int) $run['current_wave'],
            'hp' => $playerHp,
            'encounters' => json_encode(array_values($encounters), JSON_THROW_ON_ERROR),
            'combat' => json_encode($combatOut, JSON_THROW_ON_ERROR),
            'public_id' => $run['public_id'],
            'player_id' => $playerId,
        ]);

        $fresh = $this->runs->activeForPlayer($playerId) ?? array_merge($run, [
            'current_hp' => $playerHp,
            'encounters' => array_values($encounters),
            'combat' => $combatOut,
        ]);

        return $this->payload($fresh, $events, $rewards, $stageCleared, $defeated, false, $remainingMs);
    }

    /**
     * @param array<string, mixed> $run
     * @param list<array<string, mixed>> $events
     * @param array<string, mixed> $rewards
     * @param array<string, mixed> $combat
     * @return array<string, mixed>
     */
    private function failWaveTimeout(int $playerId, array $run, array $events, array $rewards, array $combat): array
    {
        $events[] = [
            'type' => 'wave_timeout',
            'message' => 'Tempo da onda esgotado — voce perdeu.',
            'target' => 'player',
        ];

        $this->pdo()->prepare("UPDATE campaign_stage_runs SET
            status = 'failed',
            combat_json = :combat,
            ended_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
            WHERE public_id = :public_id AND player_id = :player_id
        ")->execute([
            'combat' => json_encode(array_merge($combat, [
                'last_tick_at' => microtime(true),
                'failed_reason' => 'wave_timeout',
            ]), JSON_THROW_ON_ERROR),
            'public_id' => $run['public_id'],
            'player_id' => $playerId,
        ]);

        $this->progress->touchPlayed($playerId, (int) $run['node_id'], (int) $run['current_wave']);

        return $this->payload(array_merge($run, ['status' => 'failed']), $events, $rewards, false, false, true, 0);
    }

    /** @param array<string, mixed> $encounter */
    private function waveLimitMs(array $encounter): int
    {
        return !empty($encounter['is_boss']) ? self::BOSS_WAVE_LIMIT_MS : self::WAVE_LIMIT_MS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rollItemDrops(string $monsterCode, bool $isBoss, array $stageConfig = []): array
    {
        $def = $this->catalog->monster($monsterCode);
        $table = is_array($def['loot'] ?? null) ? $def['loot'] : [];
        if ($table === []) {
            $table = [
                ['item_definition_code' => 'wood', 'quantity_min' => 1, 'quantity_max' => 2, 'weight' => 60],
                ['item_definition_code' => 'herb', 'quantity_min' => 1, 'quantity_max' => 1, 'weight' => 40],
            ];
        }

        $rolls = $isBoss ? 2 : 1;
        $out = [];
        for ($i = 0; $i < $rolls; $i++) {
            $pick = $this->weightedPick($table);
            if ($pick === null) {
                continue;
            }
            $meta = $this->itemMeta((string) $pick['item_definition_code']);
            if (!$meta['exists']) {
                continue;
            }
            $qty = random_int(
                max(1, (int) ($pick['quantity_min'] ?? 1)),
                max(1, (int) ($pick['quantity_max'] ?? 1))
            );
            $out[] = [
                'staging_id' => PublicId::uuid(),
                'definition_code' => (string) $pick['item_definition_code'],
                'name' => $meta['name'],
                'quantity' => $qty,
                'grid_w' => $meta['grid_w'],
                'grid_h' => $meta['grid_h'],
                'rarity' => $meta['rarity'],
                'icon' => $meta['icon'],
            ];
        }

        // Artefato especial: chance baixa em chefe.
        if ($isBoss) {
            foreach ((array) ($stageConfig['special_drops'] ?? []) as $code) {
                if (!is_string($code) || $code === '') {
                    continue;
                }
                if (mt_rand(1, 100) > 18) {
                    continue;
                }
                $meta = $this->itemMeta($code);
                if (!$meta['exists']) {
                    continue;
                }
                $out[] = [
                    'staging_id' => PublicId::uuid(),
                    'definition_code' => $code,
                    'name' => $meta['name'],
                    'quantity' => 1,
                    'grid_w' => $meta['grid_w'],
                    'grid_h' => $meta['grid_h'],
                    'rarity' => $meta['rarity'],
                    'icon' => $meta['icon'],
                    'special' => true,
                ];
            }
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $table */
    private function weightedPick(array $table): ?array
    {
        $total = 0;
        foreach ($table as $row) {
            $total += max(0, (int) ($row['weight'] ?? 0));
        }
        if ($total <= 0) {
            return $table[0] ?? null;
        }
        $roll = random_int(1, $total);
        $cursor = 0;
        foreach ($table as $row) {
            $cursor += max(0, (int) ($row['weight'] ?? 0));
            if ($roll <= $cursor) {
                return $row;
            }
        }

        return $table[array_key_last($table)] ?? null;
    }

    /** @return array{exists:bool,name:string,grid_w:int,grid_h:int,rarity:string,icon:string} */
    private function itemMeta(string $code): array
    {
        $stmt = $this->pdo()->prepare('SELECT name, grid_w, grid_h, base_config FROM item_definitions WHERE code = :code AND status = :status LIMIT 1');
        $stmt->execute(['code' => $code, 'status' => 'active']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return [
                'exists' => false,
                'name' => $code,
                'grid_w' => 1,
                'grid_h' => 1,
                'rarity' => 'common',
                'icon' => '',
            ];
        }
        $config = [];
        if (is_string($row['base_config'] ?? null) && $row['base_config'] !== '') {
            try {
                $decoded = json_decode((string) $row['base_config'], true, 512, JSON_THROW_ON_ERROR);
                $config = is_array($decoded) ? $decoded : [];
            } catch (\JsonException) {
                $config = [];
            }
        }

        return [
            'exists' => true,
            'name' => (string) ($row['name'] ?? $code),
            'grid_w' => max(1, (int) ($row['grid_w'] ?? 1)),
            'grid_h' => max(1, (int) ($row['grid_h'] ?? 1)),
            'rarity' => (string) ($config['rarity'] ?? 'common'),
            'icon' => (string) ($config['icon'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $target
     * @return array{gold:int,exploration_xp:int,wallet_balance:mixed}
     */
    private function grantKillRewards(int $playerId, array $target): array
    {
        $gold = random_int((int) ($target['reward_gold_min'] ?? 3), max((int) ($target['reward_gold_min'] ?? 3), (int) ($target['reward_gold_max'] ?? 6)));
        $xp = random_int((int) ($target['reward_xp_min'] ?? 10), max((int) ($target['reward_xp_min'] ?? 10), (int) ($target['reward_xp_max'] ?? 16)));
        if (!empty($target['is_boss'])) {
            $gold = (int) round($gold * 1.5);
            $xp = (int) round($xp * 1.5);
        }

        $wallet = null;
        try {
            $wallet = $this->currencies->credit($playerId, 'gold', $gold, 'campaign_stage_kill', 'campaign_encounter', (string) ($target['public_id'] ?? ''));
        } catch (\Throwable) {
        }
        try {
            $this->attributes->grantXp($playerId, 'exploration', $xp, 'campaign_stage_kill', (string) ($target['public_id'] ?? ''), 'monster_kill');
        } catch (\Throwable) {
        }

        return ['gold' => $gold, 'exploration_xp' => $xp, 'wallet_balance' => $wallet];
    }

    /** @return array{amount:int,critical:bool} */
    private function rollDamage(float $attack, float $defense, float $critRate, float $critBonus): array
    {
        $critical = (mt_rand() / mt_getrandmax()) < max(0.0, min(0.6, $critRate));
        $amount = max(1, (int) round($attack - ($defense * 0.35)));
        if ($critical) {
            $amount = (int) round($amount * (1.65 + $critBonus));
        }

        return ['amount' => $amount, 'critical' => $critical];
    }

    /** @param array<string, mixed> $run */
    private function stageModifierMult(array $run, string $key, float $default = 1.0): float
    {
        $mult = $default;
        foreach ($this->stageEffects($run) as $effect) {
            if ((string) ($effect['type'] ?? '') !== $key) {
                continue;
            }
            $mult *= max(0.25, (float) ($effect['value'] ?? 1.0));
        }

        return $mult;
    }

    /** @param array<string, mixed> $run */
    private function stageModifierSum(array $run, string $key): float
    {
        $sum = 0.0;
        foreach ($this->stageEffects($run) as $effect) {
            if ((string) ($effect['type'] ?? '') !== $key) {
                continue;
            }
            $sum += (float) ($effect['value'] ?? 0);
        }

        return $sum;
    }

    /**
     * @param array<string, mixed> $run
     * @return list<array<string, mixed>>
     */
    private function stageEffects(array $run): array
    {
        static $cache = [];
        $code = (string) ($run['node_code'] ?? '');
        if ($code === '') {
            return [];
        }
        if (isset($cache[$code])) {
            return $cache[$code];
        }

        $node = $this->runs->nodeByCode($code);
        $config = [];
        if (is_array($node['config'] ?? null)) {
            $config = $node['config'];
        } elseif (is_string($node['config_json'] ?? null)) {
            try {
                $decoded = json_decode((string) $node['config_json'], true, 512, JSON_THROW_ON_ERROR);
                $config = is_array($decoded) ? $decoded : [];
            } catch (\JsonException) {
                $config = [];
            }
        }

        $effects = [];
        foreach ((array) ($config['modifiers'] ?? []) as $mod) {
            if (!is_array($mod)) {
                continue;
            }
            $effect = $mod['effect'] ?? null;
            if (is_array($effect) && !empty($effect['type'])) {
                $effects[] = $effect;
            }
        }
        $cache[$code] = $effects;

        return $effects;
    }

    /**
     * @param array<string, mixed> $run
     * @param list<array<string, mixed>> $events
     * @param array<string, mixed> $rewards
     * @return array<string, mixed>
     */
    private function payload(array $run, array $events, array $rewards, bool $stageCleared, bool $defeated, bool $waveFailed = false, int $waveRemainingMs = 0): array
    {
        $playerId = (int) ($run['player_id'] ?? 0);
        $potions = [];
        try {
            if ($playerId > 0) {
                $potions = $this->potions->beltForPlayer($playerId);
            }
        } catch (\Throwable) {
            $potions = [];
        }

        $combat = is_array($run['combat'] ?? null) ? $run['combat'] : [];
        $limit = (int) ($combat['wave_limit_ms'] ?? self::WAVE_LIMIT_MS);

        return [
            'run' => $run,
            'events' => $events,
            'rewards' => $rewards,
            'stage_cleared' => $stageCleared,
            'player_defeated' => $defeated,
            'wave_failed' => $waveFailed,
            'awaiting_loot' => ($run['status'] ?? '') === 'awaiting_loot',
            'potions' => $potions,
            'wave' => [
                'current' => (int) ($run['current_wave'] ?? 1),
                'total' => (int) ($run['wave_count'] ?? 6),
                'remaining_ms' => max(0, $waveRemainingMs),
                'limit_ms' => $limit,
            ],
        ];
    }

    private function pdo(): PDO
    {
        return $this->pdo ??= DB::pdo();
    }
}
