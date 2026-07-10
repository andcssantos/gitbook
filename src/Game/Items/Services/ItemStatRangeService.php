<?php

namespace App\Game\Items\Services;

class ItemStatRangeService
{
    public const BLESS_STATS = [
        'strength',
        'attack_power',
        'defense',
        'armor',
        'agility',
        'vitality',
        'max_health',
        'energy',
    ];

    /** @var array<string, array{0: float, 1: float}> */
    private const QUALITY_FORMULAS = [
        'attack_power' => [1 / 6, 1 / 3],
        'strength' => [1 / 6, 1 / 3],
        'armor' => [1 / 5, 1 / 2.5],
        'defense' => [1 / 5, 1 / 2.5],
        'agility' => [1 / 8, 1 / 4],
        'energy' => [1 / 7, 1 / 3.5],
        'vitality' => [1 / 4, 1 / 2],
        'max_health' => [1 / 2, 1],
    ];

    /** @var array<string, float> */
    private const RARITY_QUALITY_ANCHOR = [
        'common' => 40.0,
        'uncommon' => 48.0,
        'magic' => 56.0,
        'rare' => 68.0,
        'legendary' => 82.0,
        'epic' => 90.0,
        'divine' => 98.0,
    ];

    public function __construct(
        private ?RarityTierService $rarities = null
    ) {
        $this->rarities ??= new RarityTierService();
    }

    /** @return array{min:int,max:int} */
    public function rangeForItem(array $item, string $statCode): array
    {
        $quality = $this->resolveQualityValue($item);
        $bucket = $this->rarities->normalize((string) ($item['quality_bucket'] ?? 'common'));
        $upgradeLevel = $this->upgradeLevelFromItem($item);
        $multiplier = $this->rarityMultiplier($bucket);
        $levelBonus = 1 + ($upgradeLevel * 0.04);

        [$minFormula, $maxFormula] = self::QUALITY_FORMULAS[$statCode] ?? [1 / 8, 1 / 4];
        $min = max(1, (int) round($quality * $minFormula * $multiplier * $levelBonus));
        $max = max($min + 1, (int) round($quality * $maxFormula * $multiplier * $levelBonus));

        return ['min' => $min, 'max' => $max];
    }

    public function blessRangeConsumptionPercent(int $upgradeLevel): float
    {
        $level = max(0, min(25, $upgradeLevel));

        if ($level <= 0) {
            return 0.0;
        }

        if ($level <= 5) {
            return ($level / 5) * 0.20;
        }

        if ($level <= 15) {
            return 0.20 + (($level - 5) / 10) * 0.40;
        }

        return min(1.0, 0.60 + (($level - 15) / 10) * 0.40);
    }

    public function allowedCapAtUpgradeLevel(array $item, string $statCode, int $upgradeLevel): int
    {
        $range = $this->rangeForItem($item, $statCode);
        $span = max(0, $range['max'] - $range['min']);
        $consumption = $this->blessRangeConsumptionPercent($upgradeLevel);

        return $range['min'] + (int) round($span * $consumption);
    }

    public function cappedBlessValue(int $currentValue, int $proposedDelta, array $item, string $statCode, int $upgradeLevel): int
    {
        $cap = $this->allowedCapAtUpgradeLevel($item, $statCode, $upgradeLevel);
        $range = $this->rangeForItem($item, $statCode);
        $hardMax = $range['max'];
        $targetCap = min($cap, $hardMax);

        if ($currentValue >= $targetCap) {
            return $currentValue;
        }

        $remaining = $targetCap - $currentValue;
        $delta = max(1, min($proposedDelta, $remaining));

        return $currentValue + $delta;
    }

    public function scaleStatForRarityUpgrade(int $currentValue, array $item, string $statCode, string $fromBucket, string $toBucket): int
    {
        if ($currentValue <= 0) {
            return $currentValue;
        }

        $fromItem = $item;
        $fromItem['quality_bucket'] = $fromBucket;
        $toItem = $item;
        $toItem['quality_bucket'] = $toBucket;

        $fromRange = $this->rangeForItem($fromItem, $statCode);
        $toRange = $this->rangeForItem($toItem, $statCode);
        $fromSpan = max(1, $fromRange['max'] - $fromRange['min']);
        $percent = max(0.0, min(1.0, ($currentValue - $fromRange['min']) / $fromSpan));
        $toSpan = max(1, $toRange['max'] - $toRange['min']);

        return max(1, (int) round($toRange['min'] + ($toSpan * $percent)));
    }

    public function suggestedQualityValue(array $item, string $qualityBucket): float
    {
        $current = $this->resolveQualityValue($item);
        $anchor = self::RARITY_QUALITY_ANCHOR[$this->rarities->normalize($qualityBucket)] ?? 40.0;
        $fromAnchor = self::RARITY_QUALITY_ANCHOR[$this->rarities->normalize((string) ($item['quality_bucket'] ?? 'common'))] ?? 40.0;

        if ($anchor <= $fromAnchor) {
            return $current;
        }

        $ratio = $anchor / max(1.0, $fromAnchor);

        return round(min(100.0, max(1.0, $current * $ratio)), 3);
    }

    public function rarityMultiplier(string $qualityBucket): float
    {
        return match ($this->rarities->normalize($qualityBucket)) {
            'common' => 1.0,
            'uncommon' => 1.06,
            'magic' => 1.12,
            'rare' => 1.2,
            'legendary' => 1.32,
            'epic' => 1.46,
            'divine' => 1.62,
            default => 1.0,
        };
    }

    private function resolveQualityValue(array $item): float
    {
        $value = $item['quality_value'] ?? null;
        if (is_numeric($value) && (float) $value > 0) {
            return (float) $value;
        }

        $bucket = $this->rarities->normalize((string) ($item['quality_bucket'] ?? 'common'));

        return self::RARITY_QUALITY_ANCHOR[$bucket] ?? 40.0;
    }

    private function upgradeLevelFromItem(array $item): int
    {
        foreach ($item['properties'] ?? [] as $property) {
            if ((string) ($property['code'] ?? '') !== 'upgrade_level') {
                continue;
            }

            return max(0, (int) ($property['value'] ?? 0));
        }

        return 0;
    }
}
