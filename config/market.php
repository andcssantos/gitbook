<?php

return [
    'currencies' => [
        'gold' => [
            'code' => 'gold',
            'name' => 'Ouro',
            'symbol' => 'G',
        ],
        'premium' => [
            'code' => 'premium',
            'name' => 'Eter Cristal',
            'symbol' => '💎',
        ],
    ],
    'npc_sell' => [
        'rate_by_category' => [
            'material' => 0.60,
            'consumable' => 0.60,
            'currency' => 0.60,
            'tool' => 0.62,
            'container' => 0.62,
            'weapon' => 0.65,
            'armor' => 0.65,
            'default' => 0.65,
        ],
        'rare_bonus' => [
            'rare' => 0.03,
            'epic' => 0.04,
            'legendary' => 0.05,
            'unique' => 0.05,
            'divine' => 0.05,
        ],
        'max_rate' => 0.70,
    ],
    'listing' => [
        'fee_premium' => 1,
        'seller_fee_percent' => 0.05,
        'min_price_premium' => 1,
        'max_price_premium' => 999999,
        'min_price_factor' => 0.5,
        'max_price_factor' => 2.0,
    ],
    'pricing' => [
        'upgrade_bonus_per_level' => 0.02,
        'default_demand_factor' => 1.0,
        'min_final_price' => 1,
        'gold_to_premium_ratio' => 8,
    ],
    'recalculate' => [
        'sale_window_days' => 7,
        'demand_per_sale' => 0.05,
        'demand_per_listing' => 0.02,
        'min_demand_factor' => 0.75,
        'max_demand_factor' => 1.8,
    ],
];
