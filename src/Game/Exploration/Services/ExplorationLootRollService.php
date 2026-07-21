<?php

namespace App\Game\Exploration\Services;

class ExplorationLootRollService
{
    /** @param array<int, array<string, mixed>> $lootTable */
    public function roll(
        array $lootTable,
        int $revealTier,
        bool $expeditionActive = false,
        int $rolls = 1,
        ?int $seed = null,
        float $extraLootBonus = 0.0,
        float $rarityBonus = 0.0,
        float $chestFindBonus = 0.0
    ): array {
        $entries = array_values(array_filter($lootTable, static function (mixed $entry) use ($revealTier): bool {
            if (!is_array($entry)) {
                return false;
            }

            $code = (string) ($entry['item_definition_code'] ?? $entry['code'] ?? '');

            return $code !== '' && $revealTier >= max(1, (int) ($entry['min_reveal_tier'] ?? 1));
        }));

        if ($entries === []) {
            return [];
        }

        if ($seed !== null) {
            mt_srand($seed);
        }

        $rolls = max(1, $rolls);
        // chest_find / rarity: chance de roll extra (ate +2).
        $bonusChance = min(0.85, max(0.0, $rarityBonus) + max(0.0, $chestFindBonus) * 0.65);
        if ($bonusChance > 0 && (mt_rand(0, 10000) / 10000) <= $bonusChance) {
            $rolls += 1;
        }
        if ($bonusChance > 0.35 && (mt_rand(0, 10000) / 10000) <= ($bonusChance - 0.35)) {
            $rolls += 1;
        }

        $merged = [];

        for ($rollIndex = 0; $rollIndex < $rolls; $rollIndex++) {
            $entry = $this->pickWeightedEntry($entries, $rarityBonus);
            if ($entry === null) {
                continue;
            }

            $code = (string) ($entry['item_definition_code'] ?? $entry['code'] ?? '');
            $quantity = $this->resolveQuantity($entry, $expeditionActive, $extraLootBonus);
            if ($code === '' || $quantity <= 0) {
                continue;
            }

            $merged[$code] = ($merged[$code] ?? 0) + $quantity;
        }

        if ($seed !== null) {
            mt_srand();
        }

        $results = [];
        foreach ($merged as $code => $quantity) {
            $results[] = [
                'item_definition_code' => $code,
                'quantity' => $quantity,
            ];
        }

        return $results;
    }

    /** @param array<int, array<string, mixed>> $entries */
    private function pickWeightedEntry(array $entries, float $rarityBonus = 0.0): ?array
    {
        if ($entries === []) {
            return null;
        }

        $totalWeight = 0;
        $weighted = [];
        foreach ($entries as $entry) {
            $base = max(1, (int) ($entry['weight'] ?? 100));
            // Preferencia leve a entradas com min_reveal_tier maior quando ha rarity bonus.
            $tier = max(1, (int) ($entry['min_reveal_tier'] ?? 1));
            $weight = (int) round($base * (1 + ($rarityBonus * 0.35 * ($tier - 1))));
            $weighted[] = ['entry' => $entry, 'weight' => max(1, $weight)];
            $totalWeight += max(1, $weight);
        }

        $target = random_int(1, max(1, $totalWeight));
        $cursor = 0;
        foreach ($weighted as $row) {
            $cursor += (int) $row['weight'];
            if ($target <= $cursor) {
                return $row['entry'];
            }
        }

        return $entries[count($entries) - 1];
    }

    private function resolveQuantity(array $entry, bool $expeditionActive, float $extraLootBonus = 0.0): int
    {
        if (isset($entry['quantity'])) {
            $quantity = max(1, (int) $entry['quantity']);
        } else {
            $min = max(1, (int) ($entry['quantity_min'] ?? 1));
            $max = max($min, (int) ($entry['quantity_max'] ?? $min));
            $quantity = $min === $max ? $min : random_int($min, $max);
        }

        $multiplier = 1.0;
        if ($expeditionActive) {
            $multiplier *= 1.25;
        }
        if ($extraLootBonus > 0) {
            $multiplier *= (1 + $extraLootBonus);
        }

        return max(1, (int) ceil($quantity * $multiplier));
    }
}
