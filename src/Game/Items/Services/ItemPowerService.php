<?php

namespace App\Game\Items\Services;

class ItemPowerService
{
    /** @var array<string, float> */
    private const RARITY_MULTIPLIER = [
        'common' => 1.0,
        'uncommon' => 1.08,
        'magic' => 1.16,
        'rare' => 1.28,
        'epic' => 1.44,
        'legendary' => 1.62,
        'unique' => 1.75,
        'divine' => 1.9,
    ];

    /** @var array<string, float> */
    private const STAT_WEIGHT = [
        'attack_power' => 2.4,
        'strength' => 2.4,
        'defense' => 2.0,
        'armor' => 2.0,
        'agility' => 1.4,
        'vitality' => 1.2,
        'max_health' => 0.35,
        'energy' => 1.1,
        'critical_chance' => 18.0,
        'critical_damage' => 1.6,
        'fire_damage' => 1.8,
        'cold_damage' => 1.8,
        'lightning_damage' => 1.8,
        'poison_damage' => 1.8,
        'life_steal' => 22.0,
        'movement_speed' => 14.0,
        'gold_find' => 8.0,
        'experience_gain' => 10.0,
        'experience_bonus' => 10.0,
        'expedition_carry_bonus' => 24.0,
        'attack_speed' => 16.0,
        'dodge_chance' => 20.0,
        'loot_pickup_radius' => 36.0,
        'item_rarity_bonus' => 12.0,
        'chest_find_chance' => 10.0,
        'map_duration_bonus' => 8.0,
        'monster_spawn_bonus' => 10.0,
        'monster_rare_chance' => 14.0,
        'monster_elite_chance' => 12.0,
    ];

    /** @var array<string> */
    private const IGNORED_PROPERTY_CODES = [
        'upgrade_level',
        'upgrade_success_rate',
        'socket_count',
    ];

    public function forItem(array $item): int
    {
        $bucket = strtolower(trim((string) ($item['quality_bucket'] ?? 'common')));
        $multiplier = self::RARITY_MULTIPLIER[$bucket] ?? 1.0;
        $power = 0.0;

        foreach ($item['properties'] ?? [] as $property) {
            $code = (string) ($property['code'] ?? '');
            if ($code === '' || in_array($code, self::IGNORED_PROPERTY_CODES, true)) {
                continue;
            }

            $power += abs($this->numericValue($property)) * (self::STAT_WEIGHT[$code] ?? 1.0);
        }

        foreach ($item['affixes'] ?? [] as $affix) {
            $code = (string) ($affix['property_code'] ?? '');
            $power += abs((float) ($affix['value'] ?? 0)) * (self::STAT_WEIGHT[$code] ?? 1.2);
        }

        $upgradeLevel = $this->upgradeLevel($item);
        $power += $upgradeLevel * 28;

        $socketedGems = 0;
        foreach ($item['sockets'] ?? [] as $socket) {
            if (!empty($socket['gem'])) {
                $socketedGems += 1;
            }
        }
        $power += $socketedGems * 42;

        return max(0, (int) round($power * $multiplier));
    }

    /** @return array{attack:int,armor:int,life:int,total:int} */
    public function forEquippedPlayer(array $equipment, array $characterStats = []): array
    {
        $total = 0;
        foreach ($equipment as $slot) {
            if (!is_array($slot['item'] ?? null)) {
                continue;
            }

            $total += $this->forItem($slot['item']);
        }

        $byCode = [];
        foreach ($characterStats as $stat) {
            $byCode[(string) ($stat['code'] ?? '')] = (float) ($stat['value'] ?? 0);
        }

        // Poder total = equipamentos + contribuicao dos atributos de combate.
        $attributeTotal = (int) round(
            (($byCode['strength'] ?? 0) * (self::STAT_WEIGHT['strength'] ?? 2.4))
            + (($byCode['defense'] ?? 0) * (self::STAT_WEIGHT['defense'] ?? 2.0))
            + (($byCode['agility'] ?? 0) * (self::STAT_WEIGHT['agility'] ?? 1.4))
            + (($byCode['energy'] ?? 0) * (self::STAT_WEIGHT['energy'] ?? 1.1))
        );

        return [
            'attack' => (int) round(($byCode['attack_power'] ?? 0) + ($byCode['strength'] ?? 0)),
            'armor' => (int) round(($byCode['armor'] ?? 0) + ($byCode['defense'] ?? 0)),
            'life' => (int) round(($byCode['max_health'] ?? 0) + (($byCode['vitality'] ?? 0) * 2)),
            'agility' => (int) round($byCode['agility'] ?? 0),
            'equipment_total' => $total,
            'attribute_total' => $attributeTotal,
            'total' => $total + $attributeTotal,
        ];
    }

    private function upgradeLevel(array $item): int
    {
        foreach ($item['properties'] ?? [] as $property) {
            if ((string) ($property['code'] ?? '') !== 'upgrade_level') {
                continue;
            }

            return max(0, (int) ($property['value'] ?? 0));
        }

        return 0;
    }

    private function numericValue(array $property): float
    {
        $value = $property['value'] ?? 0;

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
