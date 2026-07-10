<?php

namespace App\Game\Items\Services;

class RarityTierService
{
    public const ORDER = [
        'common',
        'uncommon',
        'magic',
        'rare',
        'legendary',
        'epic',
        'divine',
    ];

    public function normalize(string $bucket): string
    {
        $normalized = strtolower(trim($bucket));

        return match ($normalized) {
            'normal', 'basic', 'white' => 'common',
            'green' => 'uncommon',
            'blue' => 'magic',
            'yellow', 'heroic' => 'rare',
            'gold', 'mythic', 'orange' => 'legendary',
            'purple' => 'epic',
            'unique', 'relic', 'pink', 'rosy' => 'divine',
            default => $normalized !== '' ? $normalized : 'common',
        };
    }

    public function index(string $bucket): int
    {
        $normalized = $this->normalize($bucket);
        $index = array_search($normalized, self::ORDER, true);

        return $index === false ? 0 : (int) $index;
    }

    public function upgradeLevelFor(string $bucket): int
    {
        return match ($this->normalize($bucket)) {
            'uncommon' => 0,
            'magic' => 1,
            'rare' => 2,
            'legendary' => 3,
            'epic' => 4,
            'divine' => 5,
            default => 0,
        };
    }

    public function affixTargetCount(string $bucket): int
    {
        return match ($this->normalize($bucket)) {
            'uncommon', 'magic' => 1,
            'rare' => 2,
            'legendary' => 3,
            'epic' => 4,
            'divine' => 4,
            default => 0,
        };
    }

    public function socketTargetCount(string $bucket): int
    {
        return match ($this->normalize($bucket)) {
            'magic', 'uncommon' => 1,
            'rare', 'legendary' => 2,
            'epic' => 3,
            'divine' => 4,
            default => 0,
        };
    }

    public function isChaosCap(string $bucket): bool
    {
        return in_array($this->normalize($bucket), ['legendary', 'epic', 'divine'], true);
    }
}
