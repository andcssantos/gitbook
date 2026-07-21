<?php

namespace App\Game\Expeditions\Services;

use App\Game\Inventory\DTO\GrantItemRequest;
use App\Game\Inventory\Services\InventoryAutoPlacementService;
use App\Game\Inventory\Services\InventoryStateService;
use App\Game\Exploration\Services\ExplorationPlayerModifiersService;
use App\Game\Exploration\Services\ExplorationPlayerPositionService;
use App\Game\Market\Services\PlayerCurrencyService;
use App\Game\Missions\Services\MissionService;
use App\Game\Player\Services\PlayerAttributeService;
use App\Game\Player\Services\PlayerVitalsService;
use App\Game\Tools\Services\ToolDurabilityService;
use App\Support\DB;
use PDO;

class ExpeditionArenaCombatService
{
    public function __construct(
        private ?PDO $pdo = null,
        private ?ExpeditionArenaCatalogService $catalog = null,
        private ?ExpeditionArenaSpawnService $spawn = null,
        private ?InventoryAutoPlacementService $inventoryGrant = null,
        private ?InventoryStateService $inventoryState = null,
        private ?PlayerAttributeService $attributes = null,
        private ?ExplorationPlayerModifiersService $modifiers = null,
        private ?ToolDurabilityService $toolDurability = null,
        private ?PlayerCurrencyService $currencies = null,
        private ?ExplorationPlayerPositionService $positions = null,
        private ?ExpeditionRunModifiersService $runModifiers = null,
        private ?PlayerVitalsService $playerVitals = null
    ) {
        $this->catalog ??= new ExpeditionArenaCatalogService($this->pdo);
        $this->spawn ??= new ExpeditionArenaSpawnService($this->pdo);
        $this->inventoryGrant ??= new InventoryAutoPlacementService($this->pdo);
        $this->inventoryState ??= new InventoryStateService($this->pdo);
        $this->attributes ??= new PlayerAttributeService($this->pdo);
        $this->modifiers ??= new ExplorationPlayerModifiersService($this->pdo);
        $this->toolDurability ??= new ToolDurabilityService($this->pdo);
        $this->currencies ??= new PlayerCurrencyService($this->pdo);
        $this->positions ??= new ExplorationPlayerPositionService($this->pdo);
        $this->runModifiers ??= new ExpeditionRunModifiersService($this->pdo);
        $this->playerVitals ??= new PlayerVitalsService($this->pdo, $this->attributes);
    }

    /** @return array<string, mixed> */
    public function attack(int $playerId, string $encounterPublicId): array
    {
        $expedition = $this->activeExpedition($playerId);
        if ($expedition === null) {
            throw new \RuntimeException('No active expedition found.');
        }

        $encounter = $this->findEncounter($playerId, (int) $expedition['id'], $encounterPublicId);
        if ($encounter === null) {
            throw new \RuntimeException('Encounter not found.');
        }

        $config = $this->parseJson($encounter['config_json'] ?? null);
        $metadata = $this->parseJson($expedition['metadata_json'] ?? null);
        $biomeCode = (string) ($metadata['biome_code'] ?? 'bosque_inicial');
        $playerCombat = $this->playerCombatStats($playerId, $biomeCode, $metadata);
        $this->assertWithinEngageRange(
            $playerId,
            $expedition,
            $biomeCode,
            (float) $encounter['map_x'],
            (float) $encounter['map_y'],
            (float) ($playerCombat['engage_radius'] ?? 2.0),
            'Alvo fora do alcance. Clique no chao para se aproximar e tente de novo.'
        );
        $vitals = $this->vitals((int) $expedition['id'], $playerId);
        $turn = (int) ($encounter['combat_turn'] ?? 0) + 1;
        $rng = new ExpeditionArenaRng((string) $expedition['expedition_seed'] . ':combat:' . $encounterPublicId . ':' . $turn);

        $events = [];
        $killed = false;
        $playerDefeated = false;
        $reposition = null;
        $groundLoot = null;
        $respawned = 0;
        $currentHp = (int) $encounter['current_hp'];
        $rewards = [
            'gold' => 0,
            'exploration_xp' => 0,
            'player_experience' => 0,
            'wallet_balance' => null,
            'attribute_progress' => null,
            'player_progress' => null,
            'player_level_up' => false,
        ];

        if ($rng->rollChance((float) ($config['dodge_rate'] ?? 0.1))) {
            $reposition = $this->randomPosition($expedition, $rng);
            $events[] = ['type' => 'monster_dodge', 'message' => 'O monstro disparou em fuga para outra posicao!'];
        } elseif ($this->playerWinsInitiative($playerCombat, $config, $rng)) {
            $damage = $this->playerDamage($playerCombat, $config, $rng);
            $isCrit = $damage['critical'];
            $currentHp = max(0, $currentHp - (int) $damage['amount']);
            $events[] = [
                'type' => $isCrit ? 'player_crit' : 'player_hit',
                'message' => $isCrit ? 'Golpe critico!' : 'Voce atingiu o monstro.',
                'damage' => (int) $damage['amount'],
                'target' => 'monster',
            ];
        } else {
            if ($rng->rollChance($playerCombat['dodge_rate'])) {
                $events[] = ['type' => 'player_dodge', 'message' => 'Voce esquivou do contra-ataque!', 'damage' => 0, 'target' => 'player'];
            } elseif ($rng->rollChance($playerCombat['reflect_rate'])) {
                $damage = $this->monsterDamage($config, $rng, $playerCombat);
                $currentHp = max(0, $currentHp - (int) round($damage['amount'] * 0.75));
                $events[] = [
                    'type' => 'player_reflect',
                    'message' => 'Voce refletiu parte do ataque.',
                    'damage' => (int) round($damage['amount'] * 0.75),
                    'target' => 'monster',
                ];
            } else {
                $damage = $this->monsterDamage($config, $rng, $playerCombat);
                $vitals['current_hp'] = max(0, (int) $vitals['current_hp'] - (int) $damage['amount']);
                $events[] = [
                    'type' => $damage['critical'] ? 'monster_crit' : 'monster_hit',
                    'message' => 'O monstro te atingiu.',
                    'damage' => (int) $damage['amount'],
                    'target' => 'player',
                ];
            }
        }

        if ($currentHp <= 0) {
            $killed = true;
            $events[] = ['type' => 'monster_kill', 'message' => 'Monstro derrotado!', 'damage' => 0, 'target' => 'monster'];
            $rewards = $this->grantKillRewards($playerId, $encounter, $config, $expedition, $rng);
            if ((int) ($rewards['gold'] ?? 0) > 0) {
                $events[] = [
                    'type' => 'reward_gold',
                    'message' => '+' . (int) $rewards['gold'] . ' ouro',
                    'damage' => (int) $rewards['gold'],
                    'target' => 'reward',
                ];
            }
            if ((int) ($rewards['exploration_xp'] ?? 0) > 0) {
                $events[] = [
                    'type' => 'reward_xp',
                    'message' => '+' . (int) $rewards['exploration_xp'] . ' XP exploracao',
                    'damage' => (int) $rewards['exploration_xp'],
                    'target' => 'reward',
                ];
            }
            $groundLoot = $this->spawn->spawnGroundLoot(
                (int) $expedition['id'],
                $playerId,
                (float) $encounter['map_x'],
                (float) $encounter['map_y'],
                array_values((array) ($config['loot'] ?? [])),
                $rng,
                (float) ($playerCombat['item_rarity_bonus'] ?? 0) * 0.4,
                (float) ($playerCombat['item_rarity_bonus'] ?? 0),
                (float) ($playerCombat['chest_find_chance'] ?? 0)
            );
            $this->pdo()->prepare("UPDATE expedition_encounters SET status = 'dead', current_hp = 0, combat_turn = :turn, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
                ->execute(['id' => (int) $encounter['id'], 'turn' => $turn]);
            $respawned = $this->spawn->replenishEncounters($expedition, $playerId, $biomeCode);
            if ($respawned > 0) {
                $events[] = [
                    'type' => 'monster_respawn',
                    'message' => $respawned === 1 ? 'Um novo monstro surgiu na arena.' : "{$respawned} novos monstros surgiram na arena.",
                    'damage' => 0,
                    'target' => 'arena',
                    'count' => $respawned,
                ];
            }
        } else {
            if ($reposition !== null) {
                $update = $this->pdo()->prepare('UPDATE expedition_encounters SET current_hp = :current_hp, combat_turn = :turn, map_x = :map_x, map_y = :map_y, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                $params = [
                    'current_hp' => $currentHp,
                    'turn' => $turn,
                    'map_x' => $reposition['x'],
                    'map_y' => $reposition['y'],
                    'id' => (int) $encounter['id'],
                ];
            } else {
                $update = $this->pdo()->prepare('UPDATE expedition_encounters SET current_hp = :current_hp, combat_turn = :turn, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                $params = [
                    'current_hp' => $currentHp,
                    'turn' => $turn,
                    'id' => (int) $encounter['id'],
                ];
            }
            $update->execute($params);
        }

        $this->pdo()->prepare('UPDATE expedition_arena_vitals SET current_hp = :current_hp, updated_at = CURRENT_TIMESTAMP WHERE expedition_instance_id = :expedition_id')
            ->execute([
                'current_hp' => (int) $vitals['current_hp'],
                'expedition_id' => (int) $expedition['id'],
            ]);

        $failure = null;
        if ((int) $vitals['current_hp'] <= 0) {
            $playerDefeated = true;
            $events[] = [
                'type' => 'player_defeat',
                'message' => 'Voce foi derrotado e a expedicao foi encerrada.',
                'damage' => 0,
                'target' => 'player',
            ];
            $failedRow = (new ExpeditionStateService($this->pdo))->failActiveForPlayer($playerId, 'arena_defeat');
            if ($failedRow !== null) {
                $failureMetadata = $this->parseJson($failedRow['metadata_json'] ?? null);
                $failure = is_array($failureMetadata['failure'] ?? null) ? $failureMetadata['failure'] : null;
            }
        }

        $weaponWear = $this->wearEquippedWeapon($playerId);

        return [
            'events' => $events,
            'killed' => $killed,
            'player_defeated' => $playerDefeated,
            'expedition_failed' => $failure,
            'monsters_respawned' => $respawned,
            'ground_loot' => $groundLoot,
            'rewards' => $rewards,
            'weapon_wear' => $weaponWear,
            'encounter' => $killed ? null : $this->mapEncounter($this->findEncounterById((int) $encounter['id']) ?? $encounter),
            'vitals' => [
                'current_hp' => (int) $vitals['current_hp'],
                'max_hp' => (int) $vitals['max_hp'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function focus(int $playerId, ?string $encounterPublicId): array
    {
        $expedition = $this->activeExpedition($playerId);
        if ($expedition === null) {
            throw new \RuntimeException('No active expedition found.');
        }

        $metadata = $this->parseJson($expedition['metadata_json'] ?? null);
        $biomeCode = (string) ($metadata['biome_code'] ?? 'bosque_inicial');
        $combat = $this->combatStateFromMetadata($metadata, $biomeCode);

        if ($encounterPublicId === null || trim($encounterPublicId) === '') {
            $combat['focus_encounter_public_id'] = null;
            $combat['enemy_cd'] = 0.0;
        } else {
            $encounter = $this->findEncounter($playerId, (int) $expedition['id'], $encounterPublicId);
            if ($encounter === null) {
                throw new \RuntimeException('Encounter not found.');
            }
            $combat['focus_encounter_public_id'] = (string) $encounter['public_id'];
            $combat['enemy_cd'] = 0.0;
            $combat['player_cd'] = $this->playerAttackInterval($this->playerCombatStats($playerId, $biomeCode, $metadata)) * 0.98;
            $combat['last_tick_at'] = $this->timestampSecondsAgo(1.15);
        }

        if (!isset($combat['last_tick_at']) || $combat['last_tick_at'] === '') {
            $combat['last_tick_at'] = $this->nowWithMicros();
        }
        $this->persistCombatState((int) $expedition['id'], $metadata, $combat);

        return [
            'combat_state' => $this->publicCombatState($combat, $biomeCode),
            'focused' => $combat['focus_encounter_public_id'],
        ];
    }

    /** @return array<string, mixed> */
    public function tick(int $playerId): array
    {
        $expedition = $this->activeExpedition($playerId);
        if ($expedition === null) {
            throw new \RuntimeException('No active expedition found.');
        }

        $metadata = $this->parseJson($expedition['metadata_json'] ?? null);
        $biomeCode = (string) ($metadata['biome_code'] ?? 'bosque_inicial');
        $biome = $this->catalog->biome($biomeCode) ?? [];
        $combat = $this->combatStateFromMetadata($metadata, $biomeCode);
        $playerCombat = $this->playerCombatStats($playerId, $biomeCode, $metadata);
        $vitals = $this->vitals((int) $expedition['id'], $playerId);
        $engageRadius = (float) ($biome['engage_radius'] ?? ($playerCombat['engage_radius'] ?? 2.0));
        $healPct = max(0.0, min(0.12, (float) ($biome['heal_on_kill_pct'] ?? 0.03)));
        $combat['engage_radius'] = $engageRadius;
        $combat['loot_pickup_radius'] = (float) ($playerCombat['loot_pickup_radius'] ?? 0);

        $softPenalties = is_array($metadata['entry_requirements']['soft_penalties'] ?? null)
            ? $metadata['entry_requirements']['soft_penalties']
            : [];

        $now = microtime(true);
        $lastTick = $this->parseTickTimestamp((string) ($combat['last_tick_at'] ?? ''));
        $elapsed = $lastTick > 0 ? max(0.0, min(2.8, $now - $lastTick)) : 0.9;
        $combat['last_tick_at'] = $this->nowWithMicros();

        $position = $this->positions->positionForBiome(
            $playerId,
            $biomeCode,
            (string) ($expedition['public_id'] ?? '')
        );
        $playerX = (float) ($position['map_x'] ?? 0);
        $playerY = (float) ($position['map_y'] ?? 0);

        $target = $this->resolveIdleTarget(
            $playerId,
            (int) $expedition['id'],
            $combat['focus_encounter_public_id'] ?? null,
            $playerX,
            $playerY,
            $engageRadius
        );

        // Sem alvo: nao gasta energia (evita spam ao coletar loot / andar).
        $energySpend = [
            'spent' => 0.0,
            'reason' => 'arena_tick',
            'energy' => $this->playerVitals->snapshot($playerId)['energy'] ?? ['current' => 0, 'max' => 0],
            'multiplier' => 1.0,
        ];
        if ($target !== null) {
            try {
                $energySpend = $this->playerVitals->spendEnergy(
                    $playerId,
                    PlayerVitalsService::TICK_COMBAT_ENERGY_COST,
                    $softPenalties,
                    'arena_tick'
                );
            } catch (\RuntimeException $e) {
                $this->persistCombatState((int) $expedition['id'], $metadata, $combat);

                return [
                    'events' => [[
                        'type' => 'energy_depleted',
                        'message' => 'Sem energia para combater. Pare, colete loot proximo ou descanse depois da expedicao.',
                        'damage' => 0,
                        'target' => 'player',
                    ]],
                    'killed' => false,
                    'player_defeated' => false,
                    'expedition_failed' => null,
                    'monsters_respawned' => 0,
                    'ground_loot' => null,
                    'auto_pickups' => [],
                    'rewards' => [
                        'gold' => 0,
                        'exploration_xp' => 0,
                        'player_experience' => 0,
                        'wallet_balance' => null,
                        'attribute_progress' => null,
                        'player_progress' => null,
                        'player_level_up' => false,
                        'heal' => 0,
                    ],
                    'weapon_wear' => null,
                    'encounter' => null,
                    'vitals' => [
                        'current_hp' => (int) $vitals['current_hp'],
                        'max_hp' => (int) $vitals['max_hp'],
                    ],
                    'energy' => [
                        'spent' => 0.0,
                        'reason' => 'arena_tick',
                        'energy' => $this->playerVitals->snapshot($playerId)['energy'] ?? ['current' => 0, 'max' => 0],
                        'multiplier' => 1.0,
                    ],
                    'combat_state' => $this->publicCombatState($combat, $biomeCode),
                    'in_range' => false,
                    'swings' => 0,
                ];
            }
        }

        $events = [];
        $killed = false;
        $playerDefeated = false;
        $groundLoot = null;
        $respawned = 0;
        $bossSpawned = null;
        $rewards = [
            'gold' => 0,
            'exploration_xp' => 0,
            'player_experience' => 0,
            'wallet_balance' => null,
            'attribute_progress' => null,
            'player_progress' => null,
            'player_level_up' => false,
            'heal' => 0,
        ];
        $inRange = false;
        $mappedEncounter = null;
        $weaponWear = null;

        if ($target === null) {
            $combat['focus_encounter_public_id'] = null;
            $this->persistCombatState((int) $expedition['id'], $metadata, $combat);

            return [
                'events' => [['type' => 'idle_waiting', 'message' => 'Sem alvo no alcance. Aproxime-se de um monstro ou selecione um alvo.', 'damage' => 0, 'target' => 'arena']],
                'killed' => false,
                'player_defeated' => false,
                'expedition_failed' => null,
                'monsters_respawned' => 0,
                'ground_loot' => null,
                'rewards' => $rewards,
                'weapon_wear' => null,
                'encounter' => null,
                'vitals' => [
                    'current_hp' => (int) $vitals['current_hp'],
                    'max_hp' => (int) $vitals['max_hp'],
                ],
                'energy' => $energySpend,
                'combat_state' => $this->publicCombatState($combat, $biomeCode),
                'in_range' => false,
                'swings' => 0,
            ];
        }

        $config = $this->parseJson($target['config_json'] ?? null);
        $distance = $this->distance($playerX, $playerY, (float) $target['map_x'], (float) $target['map_y']);
        $inRange = $distance <= $engageRadius;
        $combat['focus_encounter_public_id'] = (string) $target['public_id'];

        if (!$inRange) {
            $this->persistCombatState((int) $expedition['id'], $metadata, $combat);

            return [
                'events' => [['type' => 'out_of_range', 'message' => 'Alvo focado fora do alcance. Aproxime-se para auto-atacar.', 'damage' => 0, 'target' => 'arena']],
                'killed' => false,
                'player_defeated' => false,
                'expedition_failed' => null,
                'monsters_respawned' => 0,
                'ground_loot' => null,
                'rewards' => $rewards,
                'weapon_wear' => null,
                'encounter' => $this->mapEncounter($target),
                'vitals' => [
                    'current_hp' => (int) $vitals['current_hp'],
                    'max_hp' => (int) $vitals['max_hp'],
                ],
                'energy' => $energySpend,
                'combat_state' => $this->publicCombatState($combat, $biomeCode),
                'in_range' => false,
                'swings' => 0,
            ];
        }

        $playerInterval = $this->playerAttackInterval($playerCombat);
        $enemyInterval = $this->monsterAttackInterval($config);
        $playerCd = (float) ($combat['player_cd'] ?? 0);
        $enemyCd = (float) ($combat['enemy_cd'] ?? 0);
        $currentHp = (int) $target['current_hp'];
        $turn = (int) ($target['combat_turn'] ?? 0);
        $swings = 0;
        $maxSwings = 5;
        $remaining = $elapsed;
        $reposition = null;

        while ($remaining > 0.0001 && $swings < $maxSwings && $currentHp > 0 && (int) $vitals['current_hp'] > 0) {
            $step = min(0.12, $remaining);
            $remaining -= $step;
            $playerCd += $step;
            $enemyCd += $step;

            if ($playerCd >= $playerInterval) {
                $playerCd -= $playerInterval;
                $swings++;
                $turn++;
                $rng = new ExpeditionArenaRng((string) $expedition['expedition_seed'] . ':idle:' . $target['public_id'] . ':' . $turn);

                if ($rng->rollChance((float) ($config['dodge_rate'] ?? 0.1))) {
                    $reposition = $this->randomPosition($expedition, $rng);
                    $events[] = ['type' => 'monster_dodge', 'message' => 'O monstro disparou em fuga para outra posicao!', 'damage' => 0, 'target' => 'monster'];
                    $enemyCd = 0.0;
                    break;
                }

                $damage = $this->playerDamage($playerCombat, $config, $rng);
                $currentHp = max(0, $currentHp - (int) $damage['amount']);
                $events[] = [
                    'type' => $damage['critical'] ? 'player_crit' : 'player_hit',
                    'message' => $damage['critical'] ? 'Golpe critico!' : 'Voce atingiu o monstro.',
                    'damage' => (int) $damage['amount'],
                    'target' => 'monster',
                ];
                $weaponWear = $this->wearEquippedWeapon($playerId) ?? $weaponWear;

                if ($currentHp <= 0) {
                    break;
                }
            }

            if ($enemyCd >= $enemyInterval && $currentHp > 0) {
                $enemyCd -= $enemyInterval;
                $swings++;
                $turn++;
                $rng = new ExpeditionArenaRng((string) $expedition['expedition_seed'] . ':idle-enemy:' . $target['public_id'] . ':' . $turn);

                if ($rng->rollChance($playerCombat['dodge_rate'])) {
                    $events[] = ['type' => 'player_dodge', 'message' => 'Voce esquivou do contra-ataque!', 'damage' => 0, 'target' => 'player'];
                } elseif ($rng->rollChance($playerCombat['reflect_rate'])) {
                    $damage = $this->monsterDamage($config, $rng, $playerCombat);
                    $reflected = (int) round($damage['amount'] * 0.75);
                    $currentHp = max(0, $currentHp - $reflected);
                    $events[] = [
                        'type' => 'player_reflect',
                        'message' => 'Voce refletiu parte do ataque.',
                        'damage' => $reflected,
                        'target' => 'monster',
                    ];
                } else {
                    $damage = $this->monsterDamage($config, $rng, $playerCombat);
                    $vitals['current_hp'] = max(0, (int) $vitals['current_hp'] - (int) $damage['amount']);
                    $events[] = [
                        'type' => $damage['critical'] ? 'monster_crit' : 'monster_hit',
                        'message' => 'O monstro te atingiu.',
                        'damage' => (int) $damage['amount'],
                        'target' => 'player',
                    ];
                }
            }
        }

        $combat['player_cd'] = round(max(0.0, $playerCd), 4);
        $combat['enemy_cd'] = round(max(0.0, $enemyCd), 4);

        if ($currentHp <= 0) {
            $killed = true;
            $isBoss = (bool) ($config['is_boss'] ?? false);
            $events[] = [
                'type' => $isBoss ? 'boss_kill' : 'monster_kill',
                'message' => $isBoss ? 'Chefe derrotado!' : 'Monstro derrotado!',
                'damage' => 0,
                'target' => 'monster',
            ];

            $killRng = new ExpeditionArenaRng((string) $expedition['expedition_seed'] . ':idle-kill:' . $target['public_id'] . ':' . $turn);
            $rewards = $this->grantKillRewards($playerId, $target, $config, $expedition, $killRng);
            $healAmount = (int) floor((int) $vitals['max_hp'] * $healPct);
            if ($healAmount > 0 && (int) $vitals['current_hp'] > 0) {
                $before = (int) $vitals['current_hp'];
                $vitals['current_hp'] = min((int) $vitals['max_hp'], $before + $healAmount);
                $actualHeal = (int) $vitals['current_hp'] - $before;
                $rewards['heal'] = $actualHeal;
                if ($actualHeal > 0) {
                    $events[] = [
                        'type' => 'heal_on_kill',
                        'message' => '+' . $actualHeal . ' HP',
                        'damage' => $actualHeal,
                        'target' => 'player',
                    ];
                }
            }

            if ((int) ($rewards['gold'] ?? 0) > 0) {
                $events[] = [
                    'type' => 'reward_gold',
                    'message' => '+' . (int) $rewards['gold'] . ' ouro',
                    'damage' => (int) $rewards['gold'],
                    'target' => 'reward',
                ];
            }
            if ((int) ($rewards['exploration_xp'] ?? 0) > 0) {
                $events[] = [
                    'type' => 'reward_xp',
                    'message' => '+' . (int) $rewards['exploration_xp'] . ' XP exploracao',
                    'damage' => (int) $rewards['exploration_xp'],
                    'target' => 'reward',
                ];
            }

            $groundLoot = $this->spawn->spawnGroundLoot(
                (int) $expedition['id'],
                $playerId,
                (float) $target['map_x'],
                (float) $target['map_y'],
                array_values((array) ($config['loot'] ?? [])),
                $killRng,
                (float) ($playerCombat['item_rarity_bonus'] ?? 0) * 0.4,
                (float) ($playerCombat['item_rarity_bonus'] ?? 0),
                (float) ($playerCombat['chest_find_chance'] ?? 0)
            );

            $this->pdo()->prepare("UPDATE expedition_encounters SET status = 'dead', current_hp = 0, combat_turn = :turn, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
                ->execute(['id' => (int) $target['id'], 'turn' => $turn]);

            $combat['focus_encounter_public_id'] = null;
            $combat['enemy_cd'] = 0.0;
            $combat['player_cd'] = min($playerInterval * 0.25, (float) $combat['player_cd']);

            if ($isBoss) {
                $combat['boss_active'] = false;
                $combat['boss_defeated'] = (int) ($combat['boss_defeated'] ?? 0) + 1;
                $combat['kills_toward_boss'] = 0;
                $events[] = [
                    'type' => 'boss_wave_cleared',
                    'message' => 'A onda do chefe foi limpa. Novos monstros retornam a arena.',
                    'damage' => 0,
                    'target' => 'arena',
                ];
            } else {
                $combat['kills_toward_boss'] = (int) ($combat['kills_toward_boss'] ?? 0) + 1;
                $killsToBoss = max(1, (int) ($combat['kills_to_boss'] ?? ($biome['kills_to_boss'] ?? 10)));
                if (($combat['boss_active'] ?? false) !== true && (int) $combat['kills_toward_boss'] >= $killsToBoss) {
                    $combat['boss_active'] = true;
                    $combat['kills_toward_boss'] = $killsToBoss;
                    $bossSpawned = $this->spawn->spawnBossEncounter($expedition, $playerId, $biomeCode);
                    if ($bossSpawned !== null) {
                        $combat['focus_encounter_public_id'] = (string) $bossSpawned['public_id'];
                        $events[] = [
                            'type' => 'boss_spawn',
                            'message' => (string) ($bossSpawned['name'] ?? 'Um chefe') . ' surgiu na arena!',
                            'damage' => 0,
                            'target' => 'arena',
                        ];
                    } else {
                        $combat['boss_active'] = false;
                    }
                }
            }

            if (($combat['boss_active'] ?? false) !== true) {
                $respawned = $this->spawn->replenishEncounters($expedition, $playerId, $biomeCode);
                if ($respawned > 0) {
                    $events[] = [
                        'type' => 'monster_respawn',
                        'message' => $respawned === 1 ? 'Um novo monstro surgiu na arena.' : "{$respawned} novos monstros surgiram na arena.",
                        'damage' => 0,
                        'target' => 'arena',
                        'count' => $respawned,
                    ];
                }
            }
        } else {
            if ($reposition !== null) {
                $this->pdo()->prepare('UPDATE expedition_encounters SET current_hp = :current_hp, combat_turn = :turn, map_x = :map_x, map_y = :map_y, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
                    ->execute([
                        'current_hp' => $currentHp,
                        'turn' => $turn,
                        'map_x' => $reposition['x'],
                        'map_y' => $reposition['y'],
                        'id' => (int) $target['id'],
                    ]);
                $combat['focus_encounter_public_id'] = null;
                $combat['enemy_cd'] = 0.0;
            } else {
                $this->pdo()->prepare('UPDATE expedition_encounters SET current_hp = :current_hp, combat_turn = :turn, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
                    ->execute([
                        'current_hp' => $currentHp,
                        'turn' => $turn,
                        'id' => (int) $target['id'],
                    ]);
            }
            $mappedEncounter = $this->mapEncounter($this->findEncounterById((int) $target['id']) ?? $target);
        }

        $this->pdo()->prepare('UPDATE expedition_arena_vitals SET current_hp = :current_hp, updated_at = CURRENT_TIMESTAMP WHERE expedition_instance_id = :expedition_id')
            ->execute([
                'current_hp' => (int) $vitals['current_hp'],
                'expedition_id' => (int) $expedition['id'],
            ]);

        $failure = null;
        if ((int) $vitals['current_hp'] <= 0) {
            $playerDefeated = true;
            $events[] = [
                'type' => 'player_defeat',
                'message' => 'Voce foi derrotado e a expedicao foi encerrada.',
                'damage' => 0,
                'target' => 'player',
            ];
            $failedRow = (new ExpeditionStateService($this->pdo))->failActiveForPlayer($playerId, 'arena_defeat');
            if ($failedRow !== null) {
                $failureMetadata = $this->parseJson($failedRow['metadata_json'] ?? null);
                $failure = is_array($failureMetadata['failure'] ?? null) ? $failureMetadata['failure'] : null;
            }
        }

        $this->persistCombatState((int) $expedition['id'], $metadata, $combat);

        $autoPickups = [];
        if (!$playerDefeated) {
            $autoPickups = $this->autoPickupInRadius(
                $playerId,
                $expedition,
                $biomeCode,
                $playerX,
                $playerY,
                max(1.0, (float) ($playerCombat['loot_pickup_radius'] ?? 1.0))
            );
            foreach ($autoPickups as $picked) {
                $events[] = [
                    'type' => 'auto_loot',
                    'message' => 'Loot capturado automaticamente (' . (string) ($picked['item_definition_code'] ?? 'item') . ').',
                    'damage' => (int) ($picked['quantity'] ?? 1),
                    'target' => 'reward',
                ];
            }
        }

        return [
            'events' => $events,
            'killed' => $killed,
            'player_defeated' => $playerDefeated,
            'expedition_failed' => $failure,
            'monsters_respawned' => $respawned,
            'ground_loot' => $groundLoot,
            'auto_pickups' => $autoPickups,
            'rewards' => $rewards,
            'weapon_wear' => $weaponWear,
            'encounter' => $killed ? ($bossSpawned ?? null) : $mappedEncounter,
            'boss_spawned' => $bossSpawned,
            'vitals' => [
                'current_hp' => (int) $vitals['current_hp'],
                'max_hp' => (int) $vitals['max_hp'],
            ],
            'energy' => $energySpend,
            'combat_state' => $this->publicCombatState($combat, $biomeCode),
            'in_range' => true,
            'swings' => $swings,
        ];
    }

    /** @return array<string, mixed> */
    public function pickup(int $playerId, string $lootPublicId): array
    {
        $expedition = $this->activeExpedition($playerId);
        if ($expedition === null) {
            throw new \RuntimeException('No active expedition found.');
        }

        $stmt = $this->pdo()->prepare("SELECT * FROM expedition_ground_loot
            WHERE public_id = :public_id AND player_id = :player_id AND expedition_instance_id = :expedition_id AND status = 'ground'
            LIMIT 1");
        $stmt->execute([
            'public_id' => $lootPublicId,
            'player_id' => $playerId,
            'expedition_id' => (int) $expedition['id'],
        ]);
        $loot = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($loot)) {
            throw new \RuntimeException('Esse loot ja foi coletado ou sumiu.');
        }

        $metadata = $this->parseJson($expedition['metadata_json'] ?? null);
        $biomeCode = (string) ($metadata['biome_code'] ?? 'bosque_inicial');
        $playerCombat = $this->playerCombatStats($playerId, $biomeCode, $metadata);
        $manualPickupRadius = max(
            1.35,
            (float) ($playerCombat['loot_pickup_radius'] ?? 0) + 0.35,
            min(1.8, (float) ($playerCombat['engage_radius'] ?? 2.0) * 0.7)
        );
        $this->assertWithinEngageRange(
            $playerId,
            $expedition,
            $biomeCode,
            (float) $loot['map_x'],
            (float) $loot['map_y'],
            $manualPickupRadius,
            'Loot fora do alcance. Aproxime-se do item (anel amarelo) e clique de novo.'
        );

        return $this->claimGroundLootRow($playerId, $loot, false);
    }

    /** @return array<string, mixed>|null */
    private function wearEquippedWeapon(int $playerId): ?array
    {
        $weapon = $this->toolDurability->equippedWeaponForPlayer($playerId);
        if ($weapon === null) {
            return null;
        }

        $wear = $this->toolDurability->wear($playerId, (int) $weapon['id'], 1);
        if (($wear['worn'] ?? false) !== true) {
            return null;
        }

        return [
            'item_public_id' => (string) ($weapon['public_id'] ?? ''),
            'current' => $wear['current'],
            'max' => $wear['max'],
            'broken' => $wear['broken'],
        ];
    }

    /** @param array<string, mixed> $playerCombat */
    /** @param array<string, mixed> $monster */
    private function playerWinsInitiative(array $playerCombat, array $monster, ExpeditionArenaRng $rng): bool
    {
        $playerRate = max(0.1, min(0.9, (float) ($playerCombat['attack_rate'] ?? 0.55)));
        $monsterRate = max(0.1, min(0.9, (float) ($monster['attack_rate'] ?? 0.45)));

        return $rng->nextFloat() <= ($playerRate / ($playerRate + $monsterRate));
    }

    /** @param array<string, mixed> $playerCombat */
    /** @param array<string, mixed> $monster */
    /** @return array{amount: int, critical: bool} */
    private function playerDamage(array $playerCombat, array $monster, ExpeditionArenaRng $rng): array
    {
        $critical = $rng->rollChance((float) ($playerCombat['crit_rate'] ?? 0.08));
        $attack = (float) ($playerCombat['attack'] ?? 10);
        $defense = (float) ($monster['defense'] ?? 5);
        $amount = max(1, (int) round($attack - ($defense * 0.35)));
        $elementBonus = $this->resolveElementalBonus(
            (string) ($playerCombat['element'] ?? 'neutral'),
            (string) ($monster['element'] ?? 'neutral'),
            (string) ($monster['resistance'] ?? 'neutral')
        );
        if ($elementBonus !== 0.0) {
            $amount = max(1, (int) round($amount * (1 + $elementBonus)));
        }
        if ($critical) {
            $amount = (int) round($amount * (1.65 + (float) ($playerCombat['crit_damage_bonus'] ?? 0)));
        }

        return ['amount' => $amount, 'critical' => $critical];
    }

    /** @param array<string, mixed> $monster */
    /** @param array<string, mixed> $playerCombat */
    /** @return array{amount: int, critical: bool} */
    private function monsterDamage(array $monster, ExpeditionArenaRng $rng, array $playerCombat = []): array
    {
        $critical = $rng->rollChance((float) ($monster['crit_rate'] ?? 0.06));
        $amount = max(1, (int) round((float) ($monster['attack'] ?? 10)));
        $elementBonus = $this->resolveElementalBonus(
            (string) ($monster['element'] ?? 'neutral'),
            (string) ($playerCombat['element'] ?? 'neutral'),
            (string) ($playerCombat['resistance'] ?? 'neutral')
        );
        if ($elementBonus !== 0.0) {
            $amount = max(1, (int) round($amount * (1 + $elementBonus)));
        }
        $reduction = (float) ($playerCombat['damage_reduction'] ?? 0);
        if ($reduction > 0) {
            $amount = max(1, (int) round($amount * (1 - $reduction)));
        }
        if ($critical) {
            $amount = (int) round($amount * 1.5);
        }

        return ['amount' => $amount, 'critical' => $critical];
    }

    /** @return array<string, mixed> */
    private function playerCombatStats(int $playerId, string $biomeCode, ?array $expeditionMetadata = null): array
    {
        $this->attributes->ensureDefaults($playerId);
        $run = $this->runModifiers->forPlayer($playerId, $biomeCode, $expeditionMetadata);
        $byCode = (array) ($run['stats_by_code'] ?? []);
        $combatSnapshot = (array) ($run['combat_snapshot'] ?? []);

        $strength = max(1.0, (float) ($byCode['strength'] ?? 5));
        $defense = max(1.0, (float) ($byCode['defense'] ?? 5));
        $agility = max(1.0, (float) ($byCode['agility'] ?? 5));
        $vitality = max(0.0, (float) ($byCode['vitality'] ?? 0));
        $attackPower = max(0.0, (float) ($byCode['attack_power'] ?? 0));
        $armorPower = max(0.0, (float) ($byCode['armor'] ?? 0));
        $critChance = max(0.0, (float) ($byCode['critical_chance'] ?? 0));
        $critDamage = max(0.0, (float) ($byCode['critical_damage'] ?? 0));
        $fireDamage = max(0.0, (float) ($byCode['fire_damage'] ?? 0));
        $coldResistance = max(0.0, (float) ($byCode['cold_resistance'] ?? 0));
        $goldFind = max(0.0, (float) ($byCode['gold_find'] ?? 0));
        $experienceGain = max(0.0, (float) ($byCode['experience_gain'] ?? ($byCode['experience_bonus'] ?? 0)));
        $attackSpeed = max(0.0, (float) ($byCode['attack_speed'] ?? ($run['attack_speed'] ?? 0)));
        $dodgeChance = max(0.0, (float) ($byCode['dodge_chance'] ?? ($run['dodge_chance'] ?? 0)));
        $lootPickupRadius = max(0.0, (float) ($run['loot_pickup_radius'] ?? ($byCode['loot_pickup_radius'] ?? 0)));
        $maxHealthBonus = max(0.0, (float) ($byCode['max_health'] ?? 0));
        $equipmentPower = is_array($combatSnapshot['player_power'] ?? null) ? $combatSnapshot['player_power'] : [];
        $equipmentAttack = max(0.0, (float) ($equipmentPower['attack'] ?? 0));
        $equipmentArmor = max(0.0, (float) ($equipmentPower['armor'] ?? 0));
        $equipmentLife = max(0.0, (float) ($equipmentPower['life'] ?? 0));
        $equipmentTotal = max(0.0, (float) ($equipmentPower['total'] ?? 0));

        $combatBonuses = (array) ($run['combat_bonuses'] ?? []);
        $biome = $this->catalog->biome($biomeCode) ?? [];
        $gearAttackContribution = $attackPower + max(0.0, $equipmentAttack - $strength);
        $gearArmorContribution = $armorPower + max(0.0, $equipmentArmor - $defense);
        $gearLifeContribution = max(0.0, $equipmentLife - (100 + ($defense * 2)));
        $attackBase = $strength + ($gearAttackContribution * 0.9) + ($equipmentTotal * 0.015);
        $defenseBase = $defense + ($gearArmorContribution * 0.7) + ($equipmentTotal * 0.01);
        $critRateBase = 0.05 + ($strength * 0.008) + ($agility * 0.004) + ($critChance * 0.01) + min(0.06, $gearAttackContribution * 0.0015);
        $damageReductionBase = min(0.35, ($gearArmorContribution * 0.004) + ($gearLifeContribution * 0.0008) + ($vitality * 0.0025));
        $attackRate = min(0.95, 0.45 + ($agility * 0.02) + ($attackSpeed / 100 * 0.55) + (float) ($combatBonuses['attack_rate_bonus'] ?? 0));
        $dodgeRate = min(0.5, 0.05 + ($agility * 0.015) + ($dodgeChance / 100) + (float) ($combatBonuses['dodge_bonus'] ?? 0));
        $engageRadius = (float) ($biome['engage_radius'] ?? 2.0);
        // Raio base generoso: loot no chao nao exige affix de ima para funcionar.
        $lootRadius = min(2.5, max(1.0, $lootPickupRadius > 0 ? $lootPickupRadius : 1.0));

        return [
            'attack' => $attackBase * (1 + (float) ($combatBonuses['damage_bonus'] ?? 0)),
            'defense' => $defenseBase,
            'attack_rate' => $attackRate,
            'dodge_rate' => $dodgeRate,
            'reflect_rate' => min(0.2, 0.02 + ($defenseBase * 0.005) + (float) ($combatBonuses['reflect_bonus'] ?? 0)),
            'crit_rate' => min(0.3, $critRateBase + (float) ($combatBonuses['crit_bonus'] ?? 0)),
            'crit_damage_bonus' => max(0.0, min(2.5, $critDamage / 100)),
            'damage_reduction' => min(0.45, $damageReductionBase + (float) ($combatBonuses['damage_reduction'] ?? 0)),
            'damage_bonus' => (float) ($combatBonuses['damage_bonus'] ?? 0),
            'element' => $fireDamage > 0 ? 'fire' : 'neutral',
            'resistance' => $coldResistance > 0 ? 'cold' : 'neutral',
            'gold_find' => max(0.0, $goldFind / 100),
            'experience_gain' => max(0.0, $experienceGain / 100),
            'loot_pickup_radius' => $lootRadius,
            'engage_radius' => $engageRadius,
            'max_health_bonus' => $maxHealthBonus,
            'attack_speed' => $attackSpeed,
            'dodge_chance' => $dodgeChance,
            'item_rarity_bonus' => (float) ($run['item_rarity_bonus'] ?? 0),
            'chest_find_chance' => (float) ($run['chest_find_chance'] ?? 0),
            'map_duration_bonus' => (float) ($run['map_duration_bonus'] ?? 0),
            'monster_spawn_bonus' => (float) ($run['monster_spawn_bonus'] ?? 0),
            'monster_rare_chance_bonus' => (float) ($run['monster_rare_chance_bonus'] ?? 0),
            'monster_elite_chance_bonus' => (float) ($run['monster_elite_chance_bonus'] ?? 0),
        ];
    }

    /** @return array{x: float, y: float} */
    private function randomPosition(array $expedition, ExpeditionArenaRng $rng): array
    {
        $metadata = $this->parseJson($expedition['metadata_json'] ?? null);
        $biomeCode = (string) ($metadata['biome_code'] ?? 'bosque_inicial');
        $biome = $this->catalog->biome($biomeCode) ?? ['map_width' => 6, 'map_height' => 4];
        $width = (float) ($biome['map_width'] ?? 6);
        $height = (float) ($biome['map_height'] ?? 4);

        return [
            'x' => round(0.8 + ($rng->nextFloat() * max(0.5, $width - 1.6)), 2),
            'y' => round(0.8 + ($rng->nextFloat() * max(0.5, $height - 1.6)), 2),
        ];
    }

    /** @param array<string,mixed> $encounter */
    /** @param array<string,mixed> $config */
    /** @param array<string,mixed> $expedition */
    /** @return array<string,mixed> */
    private function grantKillRewards(int $playerId, array $encounter, array $config, array $expedition, ExpeditionArenaRng $rng): array
    {
        try {
            (new MissionService($this->pdo))->recordKill($playerId, 1);
        } catch (\Throwable) {
            // missao nao bloqueia combate
        }

        $metadata = $this->parseJson($expedition['metadata_json'] ?? null);
        $biomeCode = (string) ($metadata['biome_code'] ?? 'bosque_inicial');
        $playerCombat = $this->playerCombatStats($playerId, $biomeCode, $metadata);

        $goldMin = max(0, (int) ($config['reward_gold_min'] ?? 3));
        $goldMax = max($goldMin, (int) ($config['reward_gold_max'] ?? $goldMin));
        $xpMin = max(0, (int) ($config['reward_xp_min'] ?? 10));
        $xpMax = max($xpMin, (int) ($config['reward_xp_max'] ?? $xpMin));

        $gold = $rng->rangeInt($goldMin, $goldMax);
        $explorationXp = $rng->rangeInt($xpMin, $xpMax);
        $playerXp = max(1, (int) round($explorationXp * 0.6));

        if (($playerCombat['gold_find'] ?? 0) > 0) {
            $gold = (int) max(1, round($gold * (1 + (float) $playerCombat['gold_find'])));
        }
        if (($playerCombat['experience_gain'] ?? 0) > 0) {
            $explorationXp = (int) max(1, round($explorationXp * (1 + (float) $playerCombat['experience_gain'])));
            $playerXp = (int) max(1, round($playerXp * (1 + (float) $playerCombat['experience_gain'])));
        }

        $walletBalance = null;
        if ($gold > 0) {
            try {
                $walletBalance = $this->currencies->credit($playerId, 'gold', $gold, 'expedition_arena_kill', 'encounter', (string) ($encounter['public_id'] ?? ''), [
                    'definition_code' => (string) ($encounter['definition_code'] ?? ''),
                ]);
            } catch (\Throwable) {
                $walletBalance = null;
            }
        }

        $attributeProgress = $explorationXp > 0
            ? $this->attributes->grantXp($playerId, 'exploration', $explorationXp, 'arena_kill', (string) ($encounter['public_id'] ?? ''), 'monster_kill')
            : ['updated' => false];

        $playerProgress = $this->grantPlayerExperience($playerId, $playerXp);

        return [
            'gold' => $gold,
            'exploration_xp' => $explorationXp,
            'player_experience' => $playerXp,
            'wallet_balance' => $walletBalance,
            'attribute_progress' => $attributeProgress,
            'player_progress' => $playerProgress,
            'player_level_up' => (bool) ($playerProgress['leveled_up'] ?? false),
        ];
    }

    /** @return array<string,mixed> */
    private function grantPlayerExperience(int $playerId, int $xpDelta): array
    {
        if ($xpDelta <= 0) {
            return ['updated' => false, 'leveled_up' => false];
        }

        $stmt = $this->pdo()->prepare('SELECT level, experience FROM players WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $playerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['updated' => false, 'leveled_up' => false];
        }

        $levelBefore = max(1, (int) ($row['level'] ?? 1));
        $level = $levelBefore;
        $experience = max(0, (int) ($row['experience'] ?? 0)) + $xpDelta;
        while ($experience >= $this->playerXpForNextLevel($level)) {
            $experience -= $this->playerXpForNextLevel($level);
            $level++;
        }

        $this->pdo()->prepare('UPDATE players SET level = :level, experience = :experience, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute([
                'id' => $playerId,
                'level' => $level,
                'experience' => $experience,
            ]);

        $levelsGained = max(0, $level - $levelBefore);
        if ($levelsGained > 0) {
            $this->attributes->grantUnspentPoints(
                $playerId,
                $levelsGained * \App\Game\Player\Services\PlayerAttributeService::POINTS_PER_PLAYER_LEVEL,
                'player_level_up'
            );
        }

        return [
            'updated' => true,
            'level_before' => $levelBefore,
            'level_after' => $level,
            'experience' => $experience,
            'leveled_up' => $level > $levelBefore,
            'attribute_points_granted' => $levelsGained * \App\Game\Player\Services\PlayerAttributeService::POINTS_PER_PLAYER_LEVEL,
        ];
    }

    private function playerXpForNextLevel(int $level): int
    {
        return 500 + max(0, ($level - 1) * 180);
    }

    private function resolveElementalBonus(string $attackerElement, string $targetElement, string $targetResistance): float
    {
        $attacker = strtolower(trim($attackerElement));
        $target = strtolower(trim($targetElement));
        $resist = strtolower(trim($targetResistance));

        $bonus = match ($attacker) {
            'fire' => $target === 'nature' ? 0.35 : ($target === 'water' ? -0.18 : 0.0),
            'water' => $target === 'fire' ? 0.35 : ($target === 'earth' ? 0.12 : 0.0),
            'nature' => $target === 'water' ? 0.18 : 0.0,
            'earth' => $target === 'fire' ? 0.14 : 0.0,
            default => 0.0,
        };

        if ($attacker !== 'neutral' && $attacker === $resist) {
            $bonus -= 0.22;
        }

        return max(-0.3, min(0.45, $bonus));
    }

    /** @return array<string, mixed>|null */
    private function activeExpedition(int $playerId): ?array
    {
        $stmt = $this->pdo()->prepare("SELECT * FROM expedition_instances WHERE player_id = :player_id AND status = 'active' AND ends_at >= :now ORDER BY id DESC LIMIT 1");
        $stmt->execute(['player_id' => $playerId, 'now' => date('Y-m-d H:i:s')]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function findEncounter(int $playerId, int $expeditionId, string $publicId): ?array
    {
        $stmt = $this->pdo()->prepare("SELECT * FROM expedition_encounters WHERE public_id = :public_id AND player_id = :player_id AND expedition_instance_id = :expedition_id AND status = 'active' LIMIT 1");
        $stmt->execute([
            'public_id' => $publicId,
            'player_id' => $playerId,
            'expedition_id' => $expeditionId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function findEncounterById(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM expedition_encounters WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array{current_hp: int, max_hp: int} */
    private function vitals(int $expeditionId, int $playerId): array
    {
        $stmt = $this->pdo()->prepare('SELECT current_hp, max_hp FROM expedition_arena_vitals WHERE expedition_instance_id = :expedition_id AND player_id = :player_id LIMIT 1');
        $stmt->execute(['expedition_id' => $expeditionId, 'player_id' => $playerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['current_hp' => 100, 'max_hp' => 100];
        }

        return [
            'current_hp' => (int) $row['current_hp'],
            'max_hp' => (int) $row['max_hp'],
        ];
    }

    /** @param array<string, mixed> $row */
    /** @return array<string, mixed> */
    private function mapEncounter(array $row): array
    {
        $config = $this->parseJson($row['config_json'] ?? null);

        return [
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
            'status' => (string) $row['status'],
            'is_boss' => (bool) ($config['is_boss'] ?? false),
        ];
    }

    /** @param array<string, mixed> $metadata */
    /** @return array<string, mixed> */
    private function combatStateFromMetadata(array $metadata, string $biomeCode): array
    {
        $biome = $this->catalog->biome($biomeCode) ?? [];
        $combat = is_array($metadata['combat'] ?? null) ? $metadata['combat'] : [];

        return [
            'focus_encounter_public_id' => isset($combat['focus_encounter_public_id']) && is_string($combat['focus_encounter_public_id'])
                ? $combat['focus_encounter_public_id']
                : null,
            'player_cd' => max(0.0, (float) ($combat['player_cd'] ?? 0)),
            'enemy_cd' => max(0.0, (float) ($combat['enemy_cd'] ?? 0)),
            'last_tick_at' => (string) ($combat['last_tick_at'] ?? ''),
            'kills_toward_boss' => max(0, (int) ($combat['kills_toward_boss'] ?? 0)),
            'kills_to_boss' => max(1, (int) ($combat['kills_to_boss'] ?? ($biome['kills_to_boss'] ?? 10))),
            'boss_active' => (bool) ($combat['boss_active'] ?? false),
            'boss_defeated' => max(0, (int) ($combat['boss_defeated'] ?? 0)),
            'engage_radius' => (float) ($biome['engage_radius'] ?? 2.0),
            'loot_pickup_radius' => 0.0,
            'mode' => 'idle',
        ];
    }

    /** @param array<string, mixed> $combat */
    /** @return array<string, mixed> */
    private function publicCombatState(array $combat, string $biomeCode): array
    {
        $killsToBoss = max(1, (int) ($combat['kills_to_boss'] ?? 10));
        $killsToward = max(0, (int) ($combat['kills_toward_boss'] ?? 0));

        return [
            'mode' => 'idle',
            'focus_encounter_public_id' => $combat['focus_encounter_public_id'] ?? null,
            'kills_toward_boss' => $killsToward,
            'kills_to_boss' => $killsToBoss,
            'boss_active' => (bool) ($combat['boss_active'] ?? false),
            'boss_defeated' => max(0, (int) ($combat['boss_defeated'] ?? 0)),
            'engage_radius' => (float) ($combat['engage_radius'] ?? 2.0),
            'loot_pickup_radius' => (float) ($combat['loot_pickup_radius'] ?? 0.0),
            'wave_progress' => min(1, $killsToward / $killsToBoss),
            'biome_code' => $biomeCode,
        ];
    }

    /** @param array<string, mixed> $metadata */
    /** @param array<string, mixed> $combat */
    private function persistCombatState(int $expeditionId, array $metadata, array $combat): void
    {
        $metadata['combat'] = [
            'focus_encounter_public_id' => $combat['focus_encounter_public_id'] ?? null,
            'player_cd' => round((float) ($combat['player_cd'] ?? 0), 4),
            'enemy_cd' => round((float) ($combat['enemy_cd'] ?? 0), 4),
            'last_tick_at' => (string) ($combat['last_tick_at'] ?? $this->nowWithMicros()),
            'kills_toward_boss' => max(0, (int) ($combat['kills_toward_boss'] ?? 0)),
            'kills_to_boss' => max(1, (int) ($combat['kills_to_boss'] ?? 10)),
            'boss_active' => (bool) ($combat['boss_active'] ?? false),
            'boss_defeated' => max(0, (int) ($combat['boss_defeated'] ?? 0)),
            'mode' => 'idle',
        ];

        $this->pdo()->prepare('UPDATE expedition_instances SET metadata_json = :metadata_json, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute([
                'id' => $expeditionId,
                'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
    }

    /** @return array<string, mixed>|null */
    private function resolveIdleTarget(
        int $playerId,
        int $expeditionId,
        ?string $focusPublicId,
        float $playerX,
        float $playerY,
        float $engageRadius
    ): ?array {
        if (is_string($focusPublicId) && trim($focusPublicId) !== '') {
            $focused = $this->findEncounter($playerId, $expeditionId, $focusPublicId);
            if ($focused !== null) {
                return $focused;
            }
        }

        $stmt = $this->pdo()->prepare("SELECT * FROM expedition_encounters
            WHERE expedition_instance_id = :expedition_id AND player_id = :player_id AND status = 'active'
            ORDER BY id ASC");
        $stmt->execute([
            'expedition_id' => $expeditionId,
            'player_id' => $playerId,
        ]);

        $nearest = null;
        $nearestDistance = PHP_FLOAT_MAX;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $distance = $this->distance($playerX, $playerY, (float) $row['map_x'], (float) $row['map_y']);
            if ($distance > $engageRadius) {
                continue;
            }
            if ($distance < $nearestDistance) {
                $nearestDistance = $distance;
                $nearest = $row;
            }
        }

        return $nearest;
    }

    /** @param array<string, mixed> $playerCombat */
    private function playerAttackInterval(array $playerCombat): float
    {
        $rate = max(0.3, min(0.95, (float) ($playerCombat['attack_rate'] ?? 0.55)));

        return max(0.75, min(2.1, 1.15 / $rate));
    }

    /** @param array<string, mixed> $monster */
    private function monsterAttackInterval(array $monster): float
    {
        $rate = max(0.25, min(0.95, (float) ($monster['attack_rate'] ?? 0.45)));

        return max(0.95, min(2.6, 1.45 / $rate));
    }

    private function distance(float $x1, float $y1, float $x2, float $y2): float
    {
        return sqrt((($x1 - $x2) ** 2) + (($y1 - $y2) ** 2));
    }

    /** @param array<string, mixed> $expedition */
    private function assertWithinEngageRange(
        int $playerId,
        array $expedition,
        string $biomeCode,
        float $targetX,
        float $targetY,
        float $radius,
        string $message
    ): void {
        $position = $this->positions->positionForBiome(
            $playerId,
            $biomeCode,
            (string) ($expedition['public_id'] ?? '')
        );
        $distance = $this->distance(
            (float) ($position['map_x'] ?? 0),
            (float) ($position['map_y'] ?? 0),
            $targetX,
            $targetY
        );
        if ($distance > max(0.1, $radius)) {
            throw new \RuntimeException($message);
        }
    }

    /**
     * @param array<string, mixed> $expedition
     * @return list<array<string, mixed>>
     */
    private function autoPickupInRadius(
        int $playerId,
        array $expedition,
        string $biomeCode,
        float $playerX,
        float $playerY,
        float $radius
    ): array {
        if ($radius <= 0 || !$this->tableExists('expedition_ground_loot')) {
            return [];
        }

        $stmt = $this->pdo()->prepare("SELECT * FROM expedition_ground_loot
            WHERE expedition_instance_id = :expedition_id AND player_id = :player_id AND status = 'ground'
            ORDER BY id ASC
            LIMIT 12");
        $stmt->execute([
            'expedition_id' => (int) $expedition['id'],
            'player_id' => $playerId,
        ]);

        $picked = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $loot) {
            if (!is_array($loot)) {
                continue;
            }
            $distance = $this->distance($playerX, $playerY, (float) $loot['map_x'], (float) $loot['map_y']);
            if ($distance > $radius) {
                continue;
            }

            try {
                $result = $this->claimGroundLootRow($playerId, $loot, true);
                $picked[] = $result;
            } catch (\Throwable) {
                // Carry cheio ou falha pontual: tenta o proximo loot.
                continue;
            }

            if (count($picked) >= 4) {
                break;
            }
        }

        return $picked;
    }

    /** @param array<string, mixed> $loot */
    /** @return array<string, mixed> */
    private function claimGroundLootRow(int $playerId, array $loot, bool $fromMagnet = false): array
    {
        $itemCode = strtolower(trim((string) $loot['item_definition_code']));
        $quantity = max(1, (int) $loot['quantity']);
        $lootPublicId = (string) $loot['public_id'];
        $grant = null;
        $walletBalance = null;

        if (strtolower($itemCode) === 'gold_coin') {
            try {
                $walletBalance = $this->currencies->credit(
                    $playerId,
                    'gold',
                    $quantity,
                    $fromMagnet ? 'expedition_arena_magnet' : 'expedition_arena_pickup',
                    'ground_loot',
                    $lootPublicId
                );
            } catch (\Throwable) {
                $walletBalance = null;
            }
        } else {
            $grant = $this->inventoryGrant->grantAndPlace(new GrantItemRequest(
                $playerId,
                $itemCode,
                $quantity,
                null,
                null,
                null,
                true
            ));
        }

        $this->pdo()->prepare("UPDATE expedition_ground_loot SET status = 'claimed', picked_at = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute(['id' => (int) $loot['id']]);

        return [
            'claimed' => true,
            'loot_public_id' => $lootPublicId,
            'item_definition_code' => $itemCode,
            'quantity' => $quantity,
            'placement' => $grant,
            'wallet_balance' => $walletBalance,
            'placed_in_expedition_carry' => strtolower((string) ($grant['container_definition_code'] ?? '')) === 'expedition_carry',
            'from_magnet' => $fromMagnet,
        ];
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

    private function nowWithMicros(): string
    {
        $micro = sprintf('%06d', (int) ((microtime(true) - floor(microtime(true))) * 1000000));

        return date('Y-m-d H:i:s') . '.' . $micro;
    }

    private function timestampSecondsAgo(float $seconds): string
    {
        $target = microtime(true) - max(0.0, $seconds);
        $micro = sprintf('%06d', (int) (($target - floor($target)) * 1000000));

        return date('Y-m-d H:i:s', (int) floor($target)) . '.' . $micro;
    }

    private function parseTickTimestamp(string $value): float
    {
        $value = trim($value);
        if ($value === '') {
            return 0.0;
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.(\d+))?$/', $value, $matches) === 1) {
            $base = strtotime($matches[1]);
            if ($base === false) {
                return 0.0;
            }
            $fraction = isset($matches[2]) ? ((float) ('0.' . $matches[2])) : 0.0;

            return (float) $base + $fraction;
        }

        $parsed = strtotime($value);

        return $parsed === false ? 0.0 : (float) $parsed;
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

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
