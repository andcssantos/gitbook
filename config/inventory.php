<?php

return [
    /*
     | Expansao paga do inventario principal (main_inventory).
     | Largura permanece fixa; cada tier adiciona linhas.
     */
    'main_expansion' => [
        'currency' => 'gold',
        'base_columns' => 12,
        'base_rows' => 5,
        'tiers' => [
            ['rows' => 6, 'gold_cost' => 250],
            ['rows' => 7, 'gold_cost' => 500],
            ['rows' => 8, 'gold_cost' => 900],
            ['rows' => 9, 'gold_cost' => 1500],
            ['rows' => 10, 'gold_cost' => 2400],
        ],
    ],
];
