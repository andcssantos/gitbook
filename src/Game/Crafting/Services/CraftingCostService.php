<?php

namespace App\Game\Crafting\Services;

use App\Utils\Config;

class CraftingCostService
{
    private const QUALITY_MULTIPLIER = [
        'common' => 1.0,
        'uncommon' => 1.2,
        'magic' => 1.45,
        'rare' => 1.8,
        'epic' => 2.2,
        'legendary' => 2.8,
        'unique' => 3.2,
        'divine' => 3.6,
    ];

    /**
     * @param array<int, array<string, mixed>|null> $resolvedSlots
     */
    public function calculate(string $workspace, array $resolvedSlots, ?array $recipe = null): array
    {
        $pricing = (array) Config::get("crafting.pricing.{$workspace}", Config::get('crafting.pricing.forge', []));
        $base = (int) ($pricing['base_gold'] ?? 25);
        $perUnit = (int) ($pricing['per_unit_gold'] ?? 8);
        $recipeFee = (int) ($recipe['gold_fee'] ?? 0);

        $units = 0;
        $rarityFactor = 0.0;

        foreach ($resolvedSlots as $slot) {
            if (!is_array($slot)) {
                continue;
            }

            $qty = max(1, (int) ($slot['consume_quantity'] ?? 1));
            $units += $qty;
            $quality = strtolower((string) ($slot['quality_bucket'] ?? 'common'));
            $rarityFactor += (self::QUALITY_MULTIPLIER[$quality] ?? 1.0) * $qty;
        }

        $gold = (int) round($base + ($units * $perUnit) + $recipeFee + ($rarityFactor * (int) ($pricing['rarity_factor_gold'] ?? 5)));

        return [
            'gold' => max(0, $gold),
            'currency_code' => 'gold',
            'breakdown' => [
                'base' => $base,
                'units' => $units,
                'per_unit' => $perUnit,
                'recipe_fee' => $recipeFee,
                'rarity_bonus' => (int) round($rarityFactor * (int) ($pricing['rarity_factor_gold'] ?? 5)),
            ],
        ];
    }
}
