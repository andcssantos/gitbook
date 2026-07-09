<?php

namespace App\Game\Inventory\Services;

class MaterialQualityCalculator
{
    public function weightedAverage(?float $targetQuality, int $targetQuantity, ?float $sourceQuality, int $sourceQuantity): ?float
    {
        if ($targetQuality === null && $sourceQuality === null) {
            return null;
        }

        if ($targetQuality === null) {
            $targetQuality = (float) $sourceQuality;
        }

        if ($sourceQuality === null) {
            $sourceQuality = $targetQuality;
        }
        $quantity = $targetQuantity + $sourceQuantity;

        if ($quantity <= 0) {
            return null;
        }

        return round((($targetQuality * $targetQuantity) + ($sourceQuality * $sourceQuantity)) / $quantity, 3);
    }
}
