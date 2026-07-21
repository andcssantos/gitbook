<?php

namespace App\Game\Crafting\Services;

use App\Utils\Config;

class CraftingRecipeMatcherService
{
    private const QUALITY_RANK = [
        'common' => 1,
        'uncommon' => 2,
        'magic' => 3,
        'rare' => 4,
        'epic' => 5,
        'legendary' => 6,
        'unique' => 7,
        'divine' => 8,
    ];

    public function __construct(private ?CraftingRecipeCatalog $catalog = null)
    {
        $this->catalog ??= new CraftingRecipeCatalog();
    }

    /**
     * @param array<int, array<string, mixed>|null> $resolvedSlots
     * @return array<string, mixed>
     */
    public function match(string $workspace, array $resolvedSlots, int $playerId = 0): array
    {
        $pool = $this->buildPool($resolvedSlots);
        $matches = [];

        foreach ($this->catalog->recipesForWorkspace($workspace) as $recipe) {
            $evaluation = $this->evaluateRecipe($recipe, $pool);
            if ($evaluation['score'] <= 0) {
                continue;
            }

            $matches[] = $evaluation + [
                'recipe' => $this->presentRecipe($recipe),
            ];
        }

        usort($matches, function (array $a, array $b): int {
            $completeCompare = (int) ($b['is_complete'] ?? false) <=> (int) ($a['is_complete'] ?? false);
            if ($completeCompare !== 0) {
                return $completeCompare;
            }

            return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        });

        $best = $matches[0] ?? null;
        $filledCount = count(array_filter($resolvedSlots, fn ($slot): bool => is_array($slot)));

        return [
            'pool' => $pool,
            'matches' => array_slice($matches, 0, 5),
            'best_match' => $best,
            'is_compatible' => $best !== null && ($best['is_complete'] ?? false),
            'compatibility_label' => $this->compatibilityLabel($best, $filledCount),
            'predicted_output' => $best ? $this->predictOutput($best, $pool) : null,
            'recipe_code' => $best['recipe']['code'] ?? null,
            'guaranteed_success' => $workspace === 'forge' && $best !== null && ($best['is_complete'] ?? false),
        ];
    }

    /**
     * @param array<int, array<string, mixed>|null> $resolvedSlots
     * @return array<string, mixed>
     */
    private function buildPool(array $resolvedSlots): array
    {
        $itemsByDefinition = [];
        $materialsByFamily = [];
        $materialsByFamilyStacks = [];
        $materialsByOrigin = [];
        $qualities = [];
        $totalUnits = 0;

        foreach ($resolvedSlots as $slot) {
            if (!is_array($slot)) {
                continue;
            }

            $qty = max(1, (int) ($slot['consume_quantity'] ?? 1));
            $totalUnits += $qty;
            $quality = strtolower((string) ($slot['quality_bucket'] ?? 'common'));
            $qualities[] = $quality;

            if (($slot['source_kind'] ?? '') === 'material_stack') {
                $family = (string) ($slot['family_code'] ?? $slot['material_family_code'] ?? '');
                $origin = (string) ($slot['origin_code'] ?? '');
                if ($family !== '') {
                    $materialsByFamily[$family] = ($materialsByFamily[$family] ?? 0) + $qty;
                    $materialsByFamilyStacks[$family] = ($materialsByFamilyStacks[$family] ?? 0) + $qty;
                }
                if ($origin !== '') {
                    $materialsByOrigin[$origin] = ($materialsByOrigin[$origin] ?? 0) + $qty;
                }
                continue;
            }

            $definitionCode = (string) ($slot['definition_code'] ?? $slot['item']['definition']['code'] ?? '');
            if ($definitionCode !== '') {
                $itemsByDefinition[$definitionCode] = ($itemsByDefinition[$definitionCode] ?? 0) + $qty;
            }

            $family = (string) ($slot['material_family_code'] ?? '');
            if ($family !== '') {
                $materialsByFamily[$family] = ($materialsByFamily[$family] ?? 0) + $qty;
            }
        }

        return [
            'items_by_definition' => $itemsByDefinition,
            'materials_by_family' => $materialsByFamily,
            'materials_by_family_stacks' => $materialsByFamilyStacks,
            'materials_by_origin' => $materialsByOrigin,
            'qualities' => $qualities,
            'average_quality_rank' => $this->averageQualityRank($qualities),
            'total_units' => $totalUnits,
        ];
    }

    /**
     * @param array<string, mixed> $recipe
     * @param array<string, mixed> $pool
     * @return array<string, mixed>
     */
    private function evaluateRecipe(array $recipe, array $pool): array
    {
        $requirements = (array) ($recipe['requirements'] ?? []);
        $matched = [];
        $missing = [];
        $score = 0;
        $maxScore = 0;

        foreach ($requirements as $requirement) {
            $kind = (string) ($requirement['kind'] ?? '');
            $min = max(1, (int) ($requirement['min'] ?? 1));
            $weight = max(1, (int) ($requirement['weight'] ?? 1));
            $maxScore += 10 * $weight;

            $available = match ($kind) {
                'item_definition' => (int) ($pool['items_by_definition'][(string) ($requirement['definition_code'] ?? '')] ?? 0),
                'material_family' => (int) ($pool['materials_by_family'][(string) ($requirement['family_code'] ?? '')] ?? 0),
                'material_origin' => (int) ($pool['materials_by_origin'][(string) ($requirement['origin_code'] ?? '')] ?? 0),
                'material_origin_any' => $this->sumOrigins($pool, (array) ($requirement['origin_codes'] ?? [])),
                'material_origin_any_or_family' => max(
                    $this->sumOrigins($pool, (array) ($requirement['origin_codes'] ?? [])),
                    (int) ($pool['materials_by_family_stacks'][(string) ($requirement['family_code'] ?? '')] ?? 0)
                ),
                default => 0,
            };

            if ($available >= $min) {
                $matched[] = [
                    'label' => (string) ($requirement['label'] ?? $kind),
                    'required' => $min,
                    'available' => $available,
                ];
                $score += 10 * $weight;
                if ($available > $min) {
                    $score += min(3, $available - $min);
                }
            } else {
                $missing[] = [
                    'label' => (string) ($requirement['label'] ?? $kind),
                    'required' => $min,
                    'available' => $available,
                ];
            }
        }

        if ($maxScore > 0) {
            $score = (int) round(($score / $maxScore) * 100);
        }

        return [
            'score' => $score,
            'is_complete' => $missing === [] && $matched !== [],
            'matched' => $matched,
            'missing' => $missing,
        ];
    }

    /**
     * @param array<string, mixed> $match
     * @param array<string, mixed> $pool
     * @return array<string, mixed>
     */
    private function predictOutput(array $match, array $pool): array
    {
        $recipe = $match['recipe'] ?? [];
        $outputs = (array) ($recipe['outputs'] ?? []);
        $output = $outputs[0] ?? [];
        $baseQuality = (string) ($output['quality_bucket'] ?? 'common');
        $avgRank = (int) ($pool['average_quality_rank'] ?? 1);
        $quality = $this->qualityFromInputs($baseQuality, $avgRank, (string) ($recipe['workspace'] ?? 'forge'));

        return [
            'definition_code' => (string) ($output['definition_code'] ?? ''),
            'quality_bucket' => $quality,
            'rarity_label' => ucfirst($quality),
            'name' => (string) ($output['name'] ?? ''),
            'description' => (string) ($recipe['description'] ?? ''),
            'possible_outputs' => array_map(fn (array $row): array => [
                'definition_code' => (string) ($row['definition_code'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'weight' => (int) ($row['weight'] ?? 1),
            ], $outputs),
        ];
    }

    private function qualityFromInputs(string $baseQuality, int $averageRank, string $workspace): string
    {
        if ($workspace === 'forge') {
            return (string) (Config::get('crafting.workspaces.forge.forced_quality') ?? 'common');
        }

        $rank = max(self::QUALITY_RANK[$baseQuality] ?? 1, $averageRank);
        if ($rank >= 6) return 'legendary';
        if ($rank >= 5) return 'epic';
        if ($rank >= 4) return 'rare';
        if ($rank >= 3) return 'magic';
        if ($rank >= 2) return 'uncommon';

        return 'common';
    }

    /**
     * @param array<string, mixed>|null $best
     */
    private function compatibilityLabel(?array $best, int $filledCount): string
    {
        if ($filledCount < (int) Config::get('crafting.min_filled_slots', 2)) {
            return 'Insuficiente';
        }

        if ($best === null) {
            return 'Incompativel';
        }

        if ($best['is_complete'] ?? false) {
            return 'Compativel';
        }

        return 'Parcial';
    }

    /**
     * @param array<string, mixed> $recipe
     * @return array<string, mixed>
     */
    private function presentRecipe(array $recipe): array
    {
        return [
            'code' => (string) ($recipe['code'] ?? ''),
            'name' => (string) ($recipe['name'] ?? ''),
            'workspace' => (string) ($recipe['workspace'] ?? ''),
            'description' => (string) ($recipe['description'] ?? ''),
            'discovery' => (string) ($recipe['discovery'] ?? 'public'),
            'outputs' => (array) ($recipe['outputs'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $pool
     * @param array<int, string> $originCodes
     */
    private function sumOrigins(array $pool, array $originCodes): int
    {
        $sum = 0;
        foreach ($originCodes as $originCode) {
            $sum += (int) ($pool['materials_by_origin'][(string) $originCode] ?? 0);
        }

        return $sum;
    }

    /**
     * @param array<int, string> $qualities
     */
    private function averageQualityRank(array $qualities): int
    {
        if ($qualities === []) {
            return 1;
        }

        $sum = 0;
        foreach ($qualities as $quality) {
            $sum += self::QUALITY_RANK[strtolower($quality)] ?? 1;
        }

        return (int) round($sum / count($qualities));
    }
}
