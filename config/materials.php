<?php

return [
    'grid_columns' => 12,
    'grid_cell_px' => 52,
    'tabs' => [
        'metals' => [
            'code' => 'metals',
            'name' => 'Metais',
            'icon' => '⚙',
        ],
        'gems' => [
            'code' => 'gems',
            'name' => 'Gemas',
            'icon' => '💠',
        ],
        'essences' => [
            'code' => 'essences',
            'name' => 'Essencias',
            'icon' => '✦',
        ],
        'fragments' => [
            'code' => 'fragments',
            'name' => 'Fragmentos',
            'icon' => '◆',
        ],
    ],
    'family_tab_map' => [
        'metal' => 'metals',
        'currency_metal' => 'metals',
        'essence' => 'essences',
        'herb' => 'essences',
        'stone' => 'fragments',
        'wood' => 'fragments',
        'leather' => 'fragments',
    ],
    'dismantle' => [
        'default_origin_code' => 'starter_forest',
        'base_units' => [
            'weapon' => 6,
            'armor' => 5,
            'material' => 3,
            'tool' => 4,
            'consumable' => 2,
            'container' => 4,
            'default' => 3,
        ],
        'rarity_multiplier' => [
            'common' => 1.0,
            'uncommon' => 1.15,
            'magic' => 1.3,
            'rare' => 1.5,
            'epic' => 1.8,
            'legendary' => 2.2,
            'unique' => 2.5,
            'divine' => 2.8,
        ],
        'upgrade_bonus_per_level' => 0.04,
        'socket_gem_yield' => 1,
    ],
];
