<?php

use App\Http\Route;

Route::get('/api/expeditions/arena', 'App/Api/ExpeditionArenaController@state', [
    'as' => 'api.expeditions.arena.state',
    'middleware' => [
        'auth',
        'rateLimit:120,60',
        'validate:biome_code=required|string|max:60',
    ],
]);

Route::post('/api/expeditions/arena/move', 'App/Api/ExpeditionArenaController@move', [
    'as' => 'api.expeditions.arena.move',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:60,60',
        'validate:biome_code=required|string|max:60,map_x=required|numeric|min:0|max:20,map_y=required|numeric|min:0|max:20',
        'audit:expeditions.arena.move',
    ],
]);

Route::post('/api/expeditions/arena/attack', 'App/Api/ExpeditionArenaController@attack', [
    'as' => 'api.expeditions.arena.attack',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:90,60',
        'validate:encounter_public_id=required|string|max:80',
        'audit:expeditions.arena.attack',
    ],
]);

Route::post('/api/expeditions/arena/focus', 'App/Api/ExpeditionArenaController@focus', [
    'as' => 'api.expeditions.arena.focus',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:120,60',
        'validate:encounter_public_id=string|max:80',
        'audit:expeditions.arena.focus',
    ],
]);

Route::post('/api/expeditions/arena/tick', 'App/Api/ExpeditionArenaController@tick', [
    'as' => 'api.expeditions.arena.tick',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:180,60',
        'audit:expeditions.arena.tick',
    ],
]);

Route::post('/api/expeditions/arena/pickup', 'App/Api/ExpeditionArenaController@pickup', [
    'as' => 'api.expeditions.arena.pickup',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:60,60',
        'validate:loot_public_id=required|string|max:80',
        'audit:expeditions.arena.pickup',
    ],
]);

Route::post('/api/expeditions/arena/potions/use', 'App/Api/ExpeditionArenaController@usePotion', [
    'as' => 'api.expeditions.arena.potions.use',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:30,60',
        'validate:slot_code=string|max:40,item_public_id=string|max:80',
        'audit:expeditions.arena.potions.use',
    ],
]);
