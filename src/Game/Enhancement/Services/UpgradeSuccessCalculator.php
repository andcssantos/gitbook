<?php

namespace App\Game\Enhancement\Services;

class UpgradeSuccessCalculator
{
    public const MAX_BLESS_LEVEL = 25;

    public function blessSuccessRate(float $baseRate, int $currentLevel, float $itemBonusPercent = 0.0): float
    {
        if ($currentLevel >= self::MAX_BLESS_LEVEL) {
            return 0.0;
        }

        $decay = max(0.02, 1 - ($currentLevel * $currentLevel * 0.0012));
        $afterDecay = $baseRate * $decay;
        $withBonus = $afterDecay * (1 + (max(0.0, $itemBonusPercent) / 100));

        return round(max(0.5, min(100.0, $withBonus)), 2);
    }

    /** @return array{base_rate:float,level_decay_multiplier:float,after_decay:float,item_bonus_percent:float,final_rate:float} */
    public function blessSuccessBreakdown(float $baseRate, int $currentLevel, float $itemBonusPercent = 0.0): array
    {
        if ($currentLevel >= self::MAX_BLESS_LEVEL) {
            return [
                'base_rate' => round($baseRate, 2),
                'level_decay_multiplier' => 0.0,
                'after_decay' => 0.0,
                'item_bonus_percent' => round(max(0.0, $itemBonusPercent), 2),
                'final_rate' => 0.0,
            ];
        }

        $decay = max(0.02, 1 - ($currentLevel * $currentLevel * 0.0012));
        $afterDecay = $baseRate * $decay;

        return [
            'base_rate' => round($baseRate, 2),
            'level_decay_multiplier' => round($decay, 4),
            'after_decay' => round($afterDecay, 2),
            'item_bonus_percent' => round(max(0.0, $itemBonusPercent), 2),
            'final_rate' => $this->blessSuccessRate($baseRate, $currentLevel, $itemBonusPercent),
        ];
    }

    public function soulSuccessRate(float $baseRate): float
    {
        return round(max(1.0, min(100.0, $baseRate)), 2);
    }

    public function soulNewAffixChance(): float
    {
        return 1.0;
    }
}
