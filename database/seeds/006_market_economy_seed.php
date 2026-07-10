<?php

return function (PDO $pdo): void {
    $basePrices = [
        ['material', 'common', 0, 10],
        ['material', 'uncommon', 0, 14],
        ['material', 'magic', 0, 22],
        ['material', 'rare', 0, 35],
        ['weapon', 'common', 0, 18],
        ['weapon', 'uncommon', 0, 28],
        ['weapon', 'rare', 0, 55],
        ['weapon', 'rare', 1, 68],
        ['armor', 'common', 0, 16],
        ['armor', 'uncommon', 0, 26],
        ['armor', 'rare', 0, 50],
        ['container', 'common', 0, 20],
        ['container', 'rare', 0, 42],
    ];

    foreach ($basePrices as [$category, $bucket, $tier, $price]) {
        $existing = $pdo->prepare('SELECT id FROM market_base_prices WHERE category_code = :category_code AND quality_bucket = :quality_bucket AND upgrade_tier = :upgrade_tier LIMIT 1');
        $existing->execute([
            'category_code' => $category,
            'quality_bucket' => $bucket,
            'upgrade_tier' => $tier,
        ]);
        if ($existing->fetchColumn()) {
            $pdo->prepare('UPDATE market_base_prices SET base_price = :base_price, status = :status WHERE category_code = :category_code AND quality_bucket = :quality_bucket AND upgrade_tier = :upgrade_tier')
                ->execute([
                    'base_price' => $price,
                    'status' => 'active',
                    'category_code' => $category,
                    'quality_bucket' => $bucket,
                    'upgrade_tier' => $tier,
                ]);
            continue;
        }

        $pdo->prepare('INSERT INTO market_base_prices (category_code, quality_bucket, upgrade_tier, base_price, status) VALUES (:category_code, :quality_bucket, :upgrade_tier, :base_price, :status)')
            ->execute([
                'category_code' => $category,
                'quality_bucket' => $bucket,
                'upgrade_tier' => $tier,
                'base_price' => $price,
                'status' => 'active',
            ]);
    }

    $affixWeights = [
        ['powerful', 1.35, 1.2],
        ['greedy', 1.15, 1.0],
        ['swift', 1.1, 1.0],
        ['vitality', 1.25, 1.1],
        ['masterwork', 1.4, 1.3],
    ];

    foreach ($affixWeights as [$code, $demand, $tier]) {
        $existing = $pdo->prepare('SELECT id FROM affix_demand_weights WHERE affix_code = :affix_code LIMIT 1');
        $existing->execute(['affix_code' => $code]);
        if ($existing->fetchColumn()) {
            $pdo->prepare('UPDATE affix_demand_weights SET demand_weight = :demand_weight, tier_weight = :tier_weight, status = :status WHERE affix_code = :affix_code')
                ->execute([
                    'demand_weight' => $demand,
                    'tier_weight' => $tier,
                    'status' => 'active',
                    'affix_code' => $code,
                ]);
            continue;
        }

        $pdo->prepare('INSERT INTO affix_demand_weights (affix_code, demand_weight, tier_weight, status) VALUES (:affix_code, :demand_weight, :tier_weight, :status)')
            ->execute([
                'affix_code' => $code,
                'demand_weight' => $demand,
                'tier_weight' => $tier,
                'status' => 'active',
            ]);
    }
};
