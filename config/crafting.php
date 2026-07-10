<?php

return [
    'slot_count' => 6,
    'min_filled_slots' => 2,
    'workspaces' => [
        'forge' => [
            'code' => 'forge',
            'name' => 'Forja',
            'subtitle' => 'Cria itens base comuns',
            'description' => 'Combina materias-primas, bases e componentes para forjar equipamentos e ferramentas sempre comuns.',
            'aura_color' => '#f59e0b',
            'accent_color' => '#fbbf24',
            'forced_quality' => 'common',
        ],
        'alchemy' => [
            'code' => 'alchemy',
            'name' => 'Alquimia',
            'subtitle' => 'Encanta, refina e transmuta',
            'description' => 'Funde essencias, gemas, pocoes e itens especiais para criar encantamentos, colecionaveis e potencializadores.',
            'aura_color' => '#8b5cf6',
            'accent_color' => '#c084fc',
            'forced_quality' => null,
        ],
    ],
    'synergy' => [
        1 => ['level' => 1, 'label' => 'Faísca'],
        2 => ['level' => 1, 'label' => 'Faísca'],
        3 => ['level' => 2, 'label' => 'Harmonia'],
        4 => ['level' => 2, 'label' => 'Harmonia'],
        5 => ['level' => 3, 'label' => 'Ascensao'],
        6 => ['level' => 3, 'label' => 'Ascensao'],
    ],
    'forge_outputs' => [
        'metal' => 'iron_sword',
        'wood' => 'wood',
        'stone' => 'stone',
        'leather' => 'wood',
        'herb' => 'wood',
        'essence' => 'stone',
        'default' => 'stone',
    ],
    'alchemy_outputs' => [
        'consumable' => 'wood',
        'herb' => 'wood',
        'essence' => 'stone',
        'gem' => 'stone',
        'jewel' => 'stone',
        'weapon' => 'iron_sword',
        'armor' => 'stone',
        'pet' => 'wood',
        'material' => 'wood',
        'default' => 'wood',
    ],
    'alchemy_quality_by_synergy' => [
        2 => 'common',
        3 => 'uncommon',
        4 => 'magic',
        5 => 'rare',
        6 => 'epic',
    ],
    'pricing' => [
        'forge' => [
            'base_gold' => 25,
            'per_unit_gold' => 8,
            'rarity_factor_gold' => 6,
        ],
        'alchemy' => [
            'base_gold' => 15,
            'per_unit_gold' => 5,
            'rarity_factor_gold' => 10,
        ],
    ],
    'recipes' => [
        [
            'code' => 'forge_stone_pickaxe',
            'name' => 'Picareta de Pedra',
            'workspace' => 'forge',
            'discovery' => 'public',
            'gold_fee' => 30,
            'description' => 'Forja uma picareta basica combinando madeira e pedra.',
            'requirements' => [
                ['kind' => 'material_family', 'family_code' => 'wood', 'min' => 1, 'label' => 'Madeira', 'weight' => 1],
                ['kind' => 'item_definition', 'definition_code' => 'stone', 'min' => 1, 'label' => 'Pedra', 'weight' => 1],
            ],
            'outputs' => [
                ['definition_code' => 'stone_pickaxe', 'name' => 'Stone Pickaxe', 'quality_bucket' => 'common', 'weight' => 1],
            ],
        ],
        [
            'code' => 'forge_iron_sword',
            'name' => 'Espada de Ferro',
            'workspace' => 'forge',
            'discovery' => 'hidden',
            'gold_fee' => 60,
            'description' => 'Lamina forjada com metal refinado, madeira e pedra como base estrutural.',
            'requirements' => [
                ['kind' => 'material_family', 'family_code' => 'wood', 'min' => 1, 'label' => 'Madeira', 'weight' => 1],
                ['kind' => 'item_definition', 'definition_code' => 'stone', 'min' => 1, 'label' => 'Pedra', 'weight' => 1],
                ['kind' => 'material_family', 'family_code' => 'metal', 'min' => 1, 'label' => 'Metal', 'weight' => 2],
            ],
            'outputs' => [
                ['definition_code' => 'iron_sword', 'name' => 'Iron Sword', 'quality_bucket' => 'common', 'weight' => 1],
            ],
        ],
        [
            'code' => 'alchemy_wood_infusion',
            'name' => 'Infusao de Madeira',
            'workspace' => 'alchemy',
            'discovery' => 'public',
            'gold_fee' => 10,
            'description' => 'Refina madeira em um material mais denso; raridade sobe com componentes melhores.',
            'requirements' => [
                ['kind' => 'material_family', 'family_code' => 'wood', 'min' => 2, 'label' => 'Madeira', 'weight' => 2],
            ],
            'outputs' => [
                ['definition_code' => 'wood', 'name' => 'Wood', 'quality_bucket' => 'common', 'weight' => 3],
                ['definition_code' => 'stone', 'name' => 'Stone', 'quality_bucket' => 'uncommon', 'weight' => 1],
            ],
        ],
        [
            'code' => 'alchemy_essence_transmute',
            'name' => 'Transmutacao de Essencia',
            'workspace' => 'alchemy',
            'discovery' => 'hidden',
            'gold_fee' => 40,
            'description' => 'Combina essencias e gemas para criar itens raros ou epicos.',
            'requirements' => [
                ['kind' => 'material_family', 'family_code' => 'essence', 'min' => 1, 'label' => 'Essencia', 'weight' => 2],
                ['kind' => 'material_family', 'family_code' => 'herb', 'min' => 1, 'label' => 'Erva', 'weight' => 1],
            ],
            'outputs' => [
                ['definition_code' => 'iron_sword', 'name' => 'Iron Sword', 'quality_bucket' => 'rare', 'weight' => 1],
                ['definition_code' => 'stone_pickaxe', 'name' => 'Stone Pickaxe', 'quality_bucket' => 'magic', 'weight' => 2],
            ],
        ],
    ],
];
