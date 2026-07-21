<?php

namespace App\Game\Exploration\Services;

use App\Game\Player\Services\PlayerAttributeService;

class ExplorationPassiveConstellationService
{
    /** @var list<array<string, mixed>> */
    private const CONSTELLATIONS = [
        [
            'code' => 'keen_eye',
            'name' => 'Olho Atento',
            'summary' => 'Aumenta o raio de descoberta em todos os biomas.',
            'attribute_code' => 'investigation',
            'min_level' => 2,
            'effects' => ['discovery_radius_bonus' => 0.2],
        ],
        [
            'code' => 'steady_hands',
            'name' => 'Maos Firmes',
            'summary' => 'Reduz a chance de armadilhas em containers trancados.',
            'attribute_code' => 'lockpicking',
            'min_level' => 2,
            'effects' => ['trap_chance_reduction' => 0.08],
        ],
        [
            'code' => 'trailblazer',
            'name' => 'Desbravador',
            'summary' => 'Aumenta o bonus de loot durante expedicoes.',
            'attribute_code' => 'exploration',
            'min_level' => 3,
            'effects' => ['expedition_loot_bonus' => 0.05],
        ],
        [
            'code' => 'sea_legs',
            'name' => 'Pernas de Marinheiro',
            'summary' => 'Melhora a exploracao na Costa Salobra.',
            'attribute_code' => 'exploration',
            'min_level' => 4,
            'biome_codes' => ['costa_salobra'],
            'effects' => ['discovery_radius_bonus' => 0.15],
        ],
        [
            'code' => 'iron_guard',
            'name' => 'Guarda de Ferro',
            'summary' => 'Aumenta reflexao e reduz dano recebido na arena.',
            'attribute_code' => 'defense',
            'min_level' => 2,
            'effects' => ['combat_reflect_bonus' => 0.04, 'combat_damage_reduction' => 0.06],
        ],
        [
            'code' => 'quick_blade',
            'name' => 'Lamina Rapida',
            'summary' => 'Aumenta esquiva e iniciativa na arena.',
            'attribute_code' => 'agility',
            'min_level' => 2,
            'effects' => ['combat_dodge_bonus' => 0.05, 'combat_attack_rate_bonus' => 0.04],
        ],
        [
            'code' => 'berserker_trace',
            'name' => 'Traco do Berserker',
            'summary' => 'Aumenta dano e chance de critico na arena.',
            'attribute_code' => 'strength',
            'min_level' => 3,
            'effects' => ['combat_damage_bonus' => 0.1, 'combat_crit_bonus' => 0.03],
        ],
    ];

    public function __construct(private ?PlayerAttributeService $attributes = null)
    {
        $this->attributes ??= new PlayerAttributeService();
    }

    /** @return list<array<string, mixed>> */
    public function catalog(): array
    {
        return array_map(fn (array $entry): array => [
            'code' => (string) $entry['code'],
            'name' => (string) $entry['name'],
            'summary' => (string) $entry['summary'],
            'attribute_code' => (string) $entry['attribute_code'],
            'min_level' => (int) $entry['min_level'],
            'biome_codes' => array_values((array) ($entry['biome_codes'] ?? [])),
            'effects' => $entry['effects'],
        ], self::CONSTELLATIONS);
    }

    /** @return list<array<string, mixed>> */
    public function activeForPlayer(int $playerId, ?string $biomeCode = null): array
    {
        $levels = $this->attributeLevels($playerId);
        $normalizedBiome = $biomeCode !== null ? (new ExplorationBiomeCatalogService())->normalizeBiomeCode($biomeCode) : null;
        $active = [];

        foreach (self::CONSTELLATIONS as $constellation) {
            $attributeCode = (string) $constellation['attribute_code'];
            $minLevel = (int) $constellation['min_level'];
            $playerLevel = (int) ($levels[$attributeCode] ?? 1);
            if ($playerLevel < $minLevel) {
                continue;
            }

            $biomeCodes = array_values((array) ($constellation['biome_codes'] ?? []));
            if ($biomeCodes !== [] && ($normalizedBiome === null || !in_array($normalizedBiome, $biomeCodes, true))) {
                continue;
            }

            $active[] = [
                'code' => (string) $constellation['code'],
                'name' => (string) $constellation['name'],
                'summary' => (string) $constellation['summary'],
                'attribute_code' => $attributeCode,
                'attribute_level' => $playerLevel,
                'effects' => $constellation['effects'],
            ];
        }

        return $active;
    }

    /** @return array<string, float> */
    public function aggregatedEffects(int $playerId, ?string $biomeCode = null): array
    {
        $totals = [
            'discovery_radius_bonus' => 0.0,
            'trap_chance_reduction' => 0.0,
            'expedition_loot_bonus' => 0.0,
            'combat_damage_bonus' => 0.0,
            'combat_crit_bonus' => 0.0,
            'combat_dodge_bonus' => 0.0,
            'combat_reflect_bonus' => 0.0,
            'combat_attack_rate_bonus' => 0.0,
            'combat_damage_reduction' => 0.0,
        ];

        foreach ($this->activeForPlayer($playerId, $biomeCode) as $constellation) {
            foreach ((array) ($constellation['effects'] ?? []) as $key => $value) {
                if (!is_string($key) || !is_numeric($value)) {
                    continue;
                }

                $totals[$key] = ($totals[$key] ?? 0.0) + (float) $value;
            }
        }

        return $totals;
    }

    /** @return list<array<string, mixed>> */
    public function loadoutForPlayer(int $playerId, ?string $biomeCode = null): array
    {
        $levels = $this->attributeLevels($playerId);
        $attributeNames = $this->attributeNames($playerId);
        $normalizedBiome = $biomeCode !== null ? (new ExplorationBiomeCatalogService())->normalizeBiomeCode($biomeCode) : null;
        $activeCodes = array_flip(array_column($this->activeForPlayer($playerId, $biomeCode), 'code'));
        $loadout = [];

        foreach (self::CONSTELLATIONS as $constellation) {
            $code = (string) $constellation['code'];
            $attributeCode = (string) $constellation['attribute_code'];
            $minLevel = (int) $constellation['min_level'];
            $playerLevel = (int) ($levels[$attributeCode] ?? 1);
            $biomeCodes = array_values((array) ($constellation['biome_codes'] ?? []));
            $attributeName = $attributeNames[$attributeCode] ?? ucfirst($attributeCode);

            if ($playerLevel < $minLevel) {
                $status = 'locked';
                $statusLabel = 'Bloqueada';
            } elseif (isset($activeCodes[$code])) {
                $status = 'active';
                $statusLabel = 'Ativa';
            } elseif ($biomeCodes !== []) {
                $status = 'dormant';
                $statusLabel = 'Inativa neste bioma';
            } else {
                $status = 'active';
                $statusLabel = 'Ativa';
            }

            $loadout[] = [
                'code' => $code,
                'name' => (string) $constellation['name'],
                'summary' => (string) $constellation['summary'],
                'status' => $status,
                'status_label' => $statusLabel,
                'attribute_code' => $attributeCode,
                'attribute_name' => $attributeName,
                'attribute_level' => $playerLevel,
                'min_level' => $minLevel,
                'requirement_label' => $attributeName . ' nv.' . $minLevel,
                'biome_codes' => $biomeCodes,
                'effects' => $constellation['effects'],
            ];
        }

        return $loadout;
    }

    /** @return array<string, int> */
    private function attributeLevels(int $playerId): array
    {
        $levels = [];
        foreach ($this->attributes->listForPlayer($playerId) as $attribute) {
            $levels[(string) ($attribute['code'] ?? '')] = max(1, (int) ($attribute['level'] ?? 1));
        }

        return $levels;
    }

    /** @return array<string, string> */
    private function attributeNames(int $playerId): array
    {
        $names = [];
        foreach ($this->attributes->listForPlayer($playerId) as $attribute) {
            $names[(string) ($attribute['code'] ?? '')] = (string) ($attribute['name'] ?? '');
        }

        return $names;
    }
}
