<?php

namespace App\Game\Inventory\Services;

class MaterialCompositionCalculator
{
    public function merge(array $targetRows, int $targetQuantity, array $sourceRows, int $sourceQuantity): array
    {
        if ($targetRows === [] && $sourceRows === []) {
            return [];
        }

        $totalQuantity = $targetQuantity + $sourceQuantity;
        if ($totalQuantity <= 0) {
            return [];
        }

        $merged = [];
        foreach ([[$targetRows, $targetQuantity], [$sourceRows, $sourceQuantity]] as [$rows, $quantity]) {
            foreach ($rows as $row) {
                $key = (int) $row['material_family_id'] . ':' . (int) $row['material_origin_id'];
                $weightedPercentage = ((float) $row['percentage'] * $quantity) / $totalQuantity;
                $weightedQuality = $row['average_quality'] !== null
                    ? ((float) $row['average_quality'] * (float) $row['percentage'] * $quantity)
                    : null;

                if (!isset($merged[$key])) {
                    $merged[$key] = [
                        'material_family_id' => (int) $row['material_family_id'],
                        'material_origin_id' => (int) $row['material_origin_id'],
                        'percentage' => 0.0,
                        'quality_weight' => 0.0,
                        'quality_basis' => 0.0,
                    ];
                }

                $merged[$key]['percentage'] += $weightedPercentage;
                if ($weightedQuality !== null) {
                    $merged[$key]['quality_weight'] += $weightedQuality;
                    $merged[$key]['quality_basis'] += ((float) $row['percentage'] * $quantity);
                }
            }
        }

        return array_values(array_map(static function (array $row): array {
            return [
                'material_family_id' => $row['material_family_id'],
                'material_origin_id' => $row['material_origin_id'],
                'percentage' => round($row['percentage'], 3),
                'average_quality' => $row['quality_basis'] > 0
                    ? round($row['quality_weight'] / $row['quality_basis'], 3)
                    : null,
            ];
        }, $merged));
    }
}
