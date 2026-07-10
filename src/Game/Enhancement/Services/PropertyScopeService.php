<?php

namespace App\Game\Enhancement\Services;

class PropertyScopeService
{
    private const OFFENSE_CATEGORIES = ['weapon', 'tool'];
    private const DEFENSE_CATEGORIES = ['armor'];

    public function isAllowedForCategory(string $equipmentScope, string $categoryCode): bool
    {
        $categoryCode = strtolower(trim($categoryCode));
        $equipmentScope = strtolower(trim($equipmentScope));

        return match ($equipmentScope) {
            'offense' => in_array($categoryCode, self::OFFENSE_CATEGORIES, true),
            'defense' => in_array($categoryCode, self::DEFENSE_CATEGORIES, true),
            'shared' => in_array($categoryCode, array_merge(self::OFFENSE_CATEGORIES, self::DEFENSE_CATEGORIES), true),
            'exclusive_offense' => $categoryCode === 'weapon',
            'exclusive_defense' => $categoryCode === 'armor',
            default => false,
        };
    }

    public function defaultScope(): string
    {
        return 'shared';
    }
}
