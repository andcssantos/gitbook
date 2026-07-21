<?php

namespace App\Game\Expeditions\Services;

use App\Game\Biomes\Repositories\BiomeCatalogRepository;
use PDO;

class ExpeditionArenaCatalogService
{
    private BiomeCatalogRepository $repository;
    private bool $preferDatabase;

    public function __construct(?PDO $pdo = null, ?BiomeCatalogRepository $repository = null)
    {
        $this->repository = $repository ?? new BiomeCatalogRepository($pdo);
        $this->preferDatabase = $this->repository->hasTables() && $this->repository->countBiomes() > 0;
    }

    /** @var array<string, array<string, mixed>> */
    private const BIOMES = [
        'bosque_inicial' => [
            'code' => 'bosque_inicial',
            'name' => 'Bosque Inicial',
            'map_width' => 6.0,
            'map_height' => 4.0,
            'spawn' => ['x' => 1.0, 'y' => 2.0],
            'background_url' => '/assets/expedition/bosque/background.svg',
            'monster_spawn_count' => 3,
            'monster_elite_chance' => 0.12,
            'monster_rare_chance' => 0.03,
            'move_trap_chance' => 0.05,
            'move_trap_damage_min' => 6,
            'move_trap_damage_max' => 12,
            'engage_radius' => 2.0,
            'kills_to_boss' => 8,
            'heal_on_kill_pct' => 0.03,
            'monster_pool' => ['bosque_treant', 'bosque_brute'],
        ],
        'costa_salobra' => [
            'code' => 'costa_salobra',
            'name' => 'Costa Salobra',
            'map_width' => 6.0,
            'map_height' => 4.0,
            'spawn' => ['x' => 1.0, 'y' => 1.0],
            'background_url' => '/assets/expedition/costa/background.svg',
            'monster_spawn_count' => 3,
            'monster_elite_chance' => 0.16,
            'monster_rare_chance' => 0.05,
            'move_trap_chance' => 0.09,
            'move_trap_damage_min' => 8,
            'move_trap_damage_max' => 16,
            'engage_radius' => 2.1,
            'kills_to_boss' => 12,
            'heal_on_kill_pct' => 0.03,
            'monster_pool' => ['costa_crab', 'costa_shorelurker', 'bosque_brute'],
        ],
        'gruta_ecoante' => [
            'code' => 'gruta_ecoante',
            'name' => 'Gruta Ecoante',
            'map_width' => 7.0,
            'map_height' => 4.0,
            'spawn' => ['x' => 1.0, 'y' => 2.0],
            'background_url' => '/assets/expedition/bosque/background.svg',
            'monster_spawn_count' => 6,
            'monster_elite_chance' => 0.22,
            'monster_rare_chance' => 0.09,
            'move_trap_chance' => 0.08,
            'move_trap_damage_min' => 7,
            'move_trap_damage_max' => 15,
            'engage_radius' => 2.0,
            'kills_to_boss' => 11,
            'heal_on_kill_pct' => 0.035,
            'monster_pool' => ['gruta_echo_bat', 'gruta_stonewarden', 'bosque_treant'],
            'combat_mode' => 'waves',
            'wave_size' => 3,
            'wave_pause_kills' => 3,
        ],
        'ruinas_afundadas' => [
            'code' => 'ruinas_afundadas',
            'name' => 'Ruinas Afundadas',
            'map_width' => 7.0,
            'map_height' => 5.0,
            'spawn' => ['x' => 1.2, 'y' => 2.5],
            'background_url' => '/assets/expedition/costa/background.svg',
            'monster_spawn_count' => 7,
            'monster_elite_chance' => 0.26,
            'monster_rare_chance' => 0.1,
            'move_trap_chance' => 0.11,
            'move_trap_damage_min' => 9,
            'move_trap_damage_max' => 18,
            'engage_radius' => 2.15,
            'kills_to_boss' => 14,
            'heal_on_kill_pct' => 0.03,
            'monster_pool' => ['ruina_tide_specter', 'costa_shorelurker', 'costa_crab'],
        ],
        'pantano_venenoso' => [
            'code' => 'pantano_venenoso',
            'name' => 'Pantano Venenoso',
            'map_width' => 7.0,
            'map_height' => 5.0,
            'spawn' => ['x' => 1.0, 'y' => 2.0],
            'background_url' => '/assets/expedition/bosque/background.svg',
            'monster_spawn_count' => 6,
            'monster_elite_chance' => 0.28,
            'monster_rare_chance' => 0.1,
            'move_trap_chance' => 0.16,
            'move_trap_damage_min' => 11,
            'move_trap_damage_max' => 22,
            'engage_radius' => 2.0,
            'kills_to_boss' => 13,
            'heal_on_kill_pct' => 0.025,
            'monster_pool' => ['pantano_mire_toad', 'pantano_venom_wisp', 'pantano_bog_brute'],
        ],
        'vale_dos_reis' => [
            'code' => 'vale_dos_reis',
            'name' => 'Vale dos Reis',
            'map_width' => 8.0,
            'map_height' => 5.0,
            'spawn' => ['x' => 1.5, 'y' => 2.5],
            'background_url' => '/assets/expedition/costa/background.svg',
            'monster_spawn_count' => 7,
            'monster_elite_chance' => 0.3,
            'monster_rare_chance' => 0.12,
            'move_trap_chance' => 0.06,
            'move_trap_damage_min' => 8,
            'move_trap_damage_max' => 15,
            'engage_radius' => 2.2,
            'kills_to_boss' => 15,
            'heal_on_kill_pct' => 0.03,
            'monster_pool' => ['vale_royal_sentinel', 'vale_crown_jackal', 'vale_sand_wraith'],
        ],
    ];

    /** @var array<string, array<string, mixed>> */
    private const MONSTERS = [
        'bosque_treant' => [
            'code' => 'bosque_treant',
            'name' => 'Ent do Bosque',
            'sprite_key' => 'treant',
            'element' => 'nature',
            'resistance' => 'fire',
            'base_hp' => 160,
            'base_attack' => 14,
            'base_defense' => 10,
            'dodge_rate' => 0.1,
            'attack_rate' => 0.48,
            'crit_rate' => 0.08,
            'reward_gold_min' => 4,
            'reward_gold_max' => 8,
            'reward_xp_min' => 14,
            'reward_xp_max' => 22,
            'loot' => [
                ['item_definition_code' => 'wood', 'quantity_min' => 1, 'quantity_max' => 3, 'weight' => 70],
                ['item_definition_code' => 'herb', 'quantity_min' => 1, 'quantity_max' => 2, 'weight' => 30],
            ],
        ],
        'bosque_brute' => [
            'code' => 'bosque_brute',
            'name' => 'Bruto da Mata',
            'sprite_key' => 'brute',
            'element' => 'earth',
            'resistance' => 'cold',
            'base_hp' => 120,
            'base_attack' => 18,
            'base_defense' => 6,
            'dodge_rate' => 0.06,
            'attack_rate' => 0.55,
            'crit_rate' => 0.1,
            'reward_gold_min' => 5,
            'reward_gold_max' => 10,
            'reward_xp_min' => 16,
            'reward_xp_max' => 26,
            'loot' => [
                ['item_definition_code' => 'gold_coin', 'quantity_min' => 1, 'quantity_max' => 4, 'weight' => 60],
                ['item_definition_code' => 'stone', 'quantity_min' => 1, 'quantity_max' => 2, 'weight' => 40],
            ],
        ],
        'costa_crab' => [
            'code' => 'costa_crab',
            'name' => 'Caranguejo Salgado',
            'sprite_key' => 'crab',
            'element' => 'water',
            'resistance' => 'fire',
            'base_hp' => 100,
            'base_attack' => 12,
            'base_defense' => 14,
            'dodge_rate' => 0.12,
            'attack_rate' => 0.42,
            'crit_rate' => 0.05,
            'reward_gold_min' => 4,
            'reward_gold_max' => 7,
            'reward_xp_min' => 13,
            'reward_xp_max' => 20,
            'loot' => [
                ['item_definition_code' => 'herb', 'quantity_min' => 1, 'quantity_max' => 2, 'weight' => 50],
                ['item_definition_code' => 'gold_coin', 'quantity_min' => 1, 'quantity_max' => 3, 'weight' => 50],
            ],
        ],
        'costa_shorelurker' => [
            'code' => 'costa_shorelurker',
            'name' => 'Espreitador da Costa',
            'sprite_key' => 'lurker',
            'element' => 'water',
            'resistance' => 'nature',
            'base_hp' => 130,
            'base_attack' => 16,
            'base_defense' => 8,
            'dodge_rate' => 0.14,
            'attack_rate' => 0.5,
            'crit_rate' => 0.09,
            'reward_gold_min' => 6,
            'reward_gold_max' => 11,
            'reward_xp_min' => 17,
            'reward_xp_max' => 28,
            'loot' => [
                ['item_definition_code' => 'herb', 'quantity_min' => 1, 'quantity_max' => 3, 'weight' => 40],
                ['item_definition_code' => 'stone', 'quantity_min' => 1, 'quantity_max' => 2, 'weight' => 35],
                ['item_definition_code' => 'gold_coin', 'quantity_min' => 2, 'quantity_max' => 5, 'weight' => 25],
            ],
        ],
        'gruta_echo_bat' => [
            'code' => 'gruta_echo_bat',
            'name' => 'Morcego Ecoante',
            'sprite_key' => 'bat',
            'element' => 'air',
            'resistance' => 'earth',
            'base_hp' => 95,
            'base_attack' => 15,
            'base_defense' => 5,
            'dodge_rate' => 0.18,
            'attack_rate' => 0.58,
            'crit_rate' => 0.12,
            'reward_gold_min' => 5,
            'reward_gold_max' => 9,
            'reward_xp_min' => 15,
            'reward_xp_max' => 24,
            'loot' => [
                ['item_definition_code' => 'herb', 'quantity_min' => 1, 'quantity_max' => 2, 'weight' => 45],
                ['item_definition_code' => 'gold_coin', 'quantity_min' => 1, 'quantity_max' => 3, 'weight' => 40],
                ['item_definition_code' => 'season_echo_fragment', 'quantity_min' => 1, 'quantity_max' => 1, 'weight' => 8],
            ],
        ],
        'gruta_stonewarden' => [
            'code' => 'gruta_stonewarden',
            'name' => 'Guardiao de Pedra',
            'sprite_key' => 'golem',
            'element' => 'earth',
            'resistance' => 'lightning',
            'base_hp' => 170,
            'base_attack' => 17,
            'base_defense' => 16,
            'dodge_rate' => 0.05,
            'attack_rate' => 0.4,
            'crit_rate' => 0.06,
            'reward_gold_min' => 6,
            'reward_gold_max' => 12,
            'reward_xp_min' => 18,
            'reward_xp_max' => 30,
            'loot' => [
                ['item_definition_code' => 'stone', 'quantity_min' => 2, 'quantity_max' => 4, 'weight' => 55],
                ['item_definition_code' => 'gold_coin', 'quantity_min' => 2, 'quantity_max' => 5, 'weight' => 35],
                ['item_definition_code' => 'season_echo_fragment', 'quantity_min' => 1, 'quantity_max' => 1, 'weight' => 10],
            ],
        ],
        'ruina_tide_specter' => [
            'code' => 'ruina_tide_specter',
            'name' => 'Espectro da Mare',
            'sprite_key' => 'specter',
            'element' => 'water',
            'resistance' => 'cold',
            'base_hp' => 140,
            'base_attack' => 19,
            'base_defense' => 9,
            'dodge_rate' => 0.16,
            'attack_rate' => 0.52,
            'crit_rate' => 0.11,
            'reward_gold_min' => 7,
            'reward_gold_max' => 13,
            'reward_xp_min' => 20,
            'reward_xp_max' => 32,
            'loot' => [
                ['item_definition_code' => 'herb', 'quantity_min' => 1, 'quantity_max' => 3, 'weight' => 35],
                ['item_definition_code' => 'gold_coin', 'quantity_min' => 2, 'quantity_max' => 6, 'weight' => 45],
                ['item_definition_code' => 'season_echo_fragment', 'quantity_min' => 1, 'quantity_max' => 1, 'weight' => 12],
            ],
        ],
        'pantano_mire_toad' => [
            'code' => 'pantano_mire_toad',
            'name' => 'Sapo do Lamaçal',
            'sprite_key' => 'toad',
            'element' => 'poison',
            'resistance' => 'nature',
            'base_hp' => 125,
            'base_attack' => 17,
            'base_defense' => 8,
            'dodge_rate' => 0.11,
            'attack_rate' => 0.5,
            'crit_rate' => 0.09,
            'reward_gold_min' => 6,
            'reward_gold_max' => 11,
            'reward_xp_min' => 17,
            'reward_xp_max' => 27,
            'loot' => [
                ['item_definition_code' => 'herb', 'quantity_min' => 1, 'quantity_max' => 3, 'weight' => 55],
                ['item_definition_code' => 'gold_coin', 'quantity_min' => 1, 'quantity_max' => 4, 'weight' => 45],
            ],
        ],
        'pantano_venom_wisp' => [
            'code' => 'pantano_venom_wisp',
            'name' => 'Fumaca Venenosa',
            'sprite_key' => 'wisp',
            'element' => 'poison',
            'resistance' => 'fire',
            'base_hp' => 95,
            'base_attack' => 20,
            'base_defense' => 5,
            'dodge_rate' => 0.2,
            'attack_rate' => 0.58,
            'crit_rate' => 0.13,
            'reward_gold_min' => 5,
            'reward_gold_max' => 10,
            'reward_xp_min' => 16,
            'reward_xp_max' => 26,
            'loot' => [
                ['item_definition_code' => 'herb', 'quantity_min' => 1, 'quantity_max' => 2, 'weight' => 60],
                ['item_definition_code' => 'gold_coin', 'quantity_min' => 2, 'quantity_max' => 5, 'weight' => 40],
            ],
        ],
        'pantano_bog_brute' => [
            'code' => 'pantano_bog_brute',
            'name' => 'Bruto do Brejo',
            'sprite_key' => 'brute',
            'element' => 'earth',
            'resistance' => 'poison',
            'base_hp' => 155,
            'base_attack' => 18,
            'base_defense' => 12,
            'dodge_rate' => 0.05,
            'attack_rate' => 0.44,
            'crit_rate' => 0.07,
            'reward_gold_min' => 7,
            'reward_gold_max' => 13,
            'reward_xp_min' => 19,
            'reward_xp_max' => 30,
            'loot' => [
                ['item_definition_code' => 'wood', 'quantity_min' => 1, 'quantity_max' => 3, 'weight' => 40],
                ['item_definition_code' => 'stone', 'quantity_min' => 1, 'quantity_max' => 2, 'weight' => 35],
                ['item_definition_code' => 'gold_coin', 'quantity_min' => 2, 'quantity_max' => 5, 'weight' => 25],
            ],
        ],
        'vale_royal_sentinel' => [
            'code' => 'vale_royal_sentinel',
            'name' => 'Sentinela Real',
            'sprite_key' => 'golem',
            'element' => 'earth',
            'resistance' => 'lightning',
            'base_hp' => 165,
            'base_attack' => 18,
            'base_defense' => 15,
            'dodge_rate' => 0.06,
            'attack_rate' => 0.42,
            'crit_rate' => 0.07,
            'reward_gold_min' => 7,
            'reward_gold_max' => 13,
            'reward_xp_min' => 20,
            'reward_xp_max' => 32,
            'loot' => [
                ['item_definition_code' => 'stone', 'quantity_min' => 2, 'quantity_max' => 4, 'weight' => 50],
                ['item_definition_code' => 'gold_coin', 'quantity_min' => 2, 'quantity_max' => 6, 'weight' => 50],
            ],
        ],
        'vale_crown_jackal' => [
            'code' => 'vale_crown_jackal',
            'name' => 'Chacal da Coroa',
            'sprite_key' => 'lurker',
            'element' => 'earth',
            'resistance' => 'cold',
            'base_hp' => 115,
            'base_attack' => 19,
            'base_defense' => 7,
            'dodge_rate' => 0.17,
            'attack_rate' => 0.56,
            'crit_rate' => 0.12,
            'reward_gold_min' => 6,
            'reward_gold_max' => 11,
            'reward_xp_min' => 18,
            'reward_xp_max' => 28,
            'loot' => [
                ['item_definition_code' => 'herb', 'quantity_min' => 1, 'quantity_max' => 2, 'weight' => 35],
                ['item_definition_code' => 'gold_coin', 'quantity_min' => 2, 'quantity_max' => 5, 'weight' => 65],
            ],
        ],
        'vale_sand_wraith' => [
            'code' => 'vale_sand_wraith',
            'name' => 'Espectro da Areia',
            'sprite_key' => 'specter',
            'element' => 'air',
            'resistance' => 'earth',
            'base_hp' => 135,
            'base_attack' => 20,
            'base_defense' => 9,
            'dodge_rate' => 0.15,
            'attack_rate' => 0.53,
            'crit_rate' => 0.11,
            'reward_gold_min' => 7,
            'reward_gold_max' => 14,
            'reward_xp_min' => 21,
            'reward_xp_max' => 33,
            'loot' => [
                ['item_definition_code' => 'stone', 'quantity_min' => 1, 'quantity_max' => 3, 'weight' => 40],
                ['item_definition_code' => 'gold_coin', 'quantity_min' => 3, 'quantity_max' => 7, 'weight' => 60],
            ],
        ],
    ];

    /** @var array<int, array<string, float|int|string>> */
    private const TIERS = [
        1 => ['code' => 'common', 'label' => 'Comum', 'hp_mult' => 1.0, 'atk_mult' => 1.0, 'def_mult' => 1.0],
        2 => ['code' => 'elite', 'label' => 'Elite', 'hp_mult' => 1.45, 'atk_mult' => 1.2, 'def_mult' => 1.15],
        3 => ['code' => 'rare', 'label' => 'Raro', 'hp_mult' => 1.9, 'atk_mult' => 1.35, 'def_mult' => 1.25],
    ];

    /** @return array<string, mixed>|null */
    public function biome(string $biomeCode): ?array
    {
        $normalized = $this->normalizeBiomeCode($biomeCode);
        if ($this->preferDatabase) {
            $fromDb = $this->repository->getArenaBiome($normalized);
            if ($fromDb !== null) {
                return $fromDb;
            }
        }

        return self::BIOMES[$normalized] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function monster(string $definitionCode): ?array
    {
        if ($this->preferDatabase) {
            $fromDb = $this->repository->getMonster($definitionCode);
            if ($fromDb !== null) {
                return $fromDb;
            }
        }

        return self::MONSTERS[$definitionCode] ?? null;
    }

    /** @return array<string, mixed> */
    public function scaledMonster(string $definitionCode, int $tier = 1): array
    {
        $definition = $this->monster($definitionCode);
        if ($definition === null) {
            throw new \InvalidArgumentException('Unknown monster definition: ' . $definitionCode);
        }

        $tierConfig = self::TIERS[max(1, min(3, $tier))] ?? self::TIERS[1];
        $hpMult = (float) $tierConfig['hp_mult'];
        $atkMult = (float) $tierConfig['atk_mult'];
        $defMult = (float) $tierConfig['def_mult'];

        return [
            'code' => (string) $definition['code'],
            'name' => (string) $definition['name'],
            'sprite_key' => (string) $definition['sprite_key'],
            'tier' => $tier,
            'tier_label' => (string) $tierConfig['label'],
            'max_hp' => (int) round((int) $definition['base_hp'] * $hpMult),
            'attack' => (int) round((int) $definition['base_attack'] * $atkMult),
            'defense' => (int) round((int) $definition['base_defense'] * $defMult),
            'element' => (string) ($definition['element'] ?? 'neutral'),
            'resistance' => (string) ($definition['resistance'] ?? 'neutral'),
            'dodge_rate' => (float) $definition['dodge_rate'],
            'attack_rate' => (float) $definition['attack_rate'],
            'crit_rate' => (float) $definition['crit_rate'],
            'reward_gold_min' => (int) round((int) ($definition['reward_gold_min'] ?? 3) * (0.8 + ($tier * 0.18))),
            'reward_gold_max' => (int) round((int) ($definition['reward_gold_max'] ?? 6) * (0.9 + ($tier * 0.2))),
            'reward_xp_min' => (int) round((int) ($definition['reward_xp_min'] ?? 10) * (0.85 + ($tier * 0.16))),
            'reward_xp_max' => (int) round((int) ($definition['reward_xp_max'] ?? 16) * (0.9 + ($tier * 0.18))),
            'loot' => array_values((array) ($definition['loot'] ?? [])),
            'is_boss' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function scaledBoss(string $definitionCode, string $biomeName): array
    {
        $scaled = $this->scaledMonster($definitionCode, 3);
        $scaled['name'] = 'Guardiao de ' . $biomeName;
        $scaled['tier'] = 3;
        $scaled['tier_label'] = 'Chefe';
        $scaled['max_hp'] = (int) round((int) $scaled['max_hp'] * 3.2);
        $scaled['attack'] = (int) round((int) $scaled['attack'] * 1.85);
        $scaled['defense'] = (int) round((int) $scaled['defense'] * 1.45);
        $scaled['dodge_rate'] = min(0.18, (float) $scaled['dodge_rate'] * 0.7);
        $scaled['attack_rate'] = min(0.75, max(0.35, (float) $scaled['attack_rate'] * 0.92));
        $scaled['reward_gold_min'] = (int) round((int) $scaled['reward_gold_min'] * 4);
        $scaled['reward_gold_max'] = (int) round((int) $scaled['reward_gold_max'] * 5);
        $scaled['reward_xp_min'] = (int) round((int) $scaled['reward_xp_min'] * 3.5);
        $scaled['reward_xp_max'] = (int) round((int) $scaled['reward_xp_max'] * 4.2);
        $scaled['is_boss'] = true;

        return $scaled;
    }

    public function normalizeBiomeCode(string $biomeCode): string
    {
        $normalized = strtolower(trim($biomeCode));
        $normalized = preg_replace('/[^a-z0-9_]/', '', $normalized) ?: '';

        return $normalized;
    }
}
