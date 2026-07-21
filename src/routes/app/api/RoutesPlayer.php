<?php

use App\Http\Route;

Route::get('/api/player/hud', 'App/Api/PlayerController@hud', [
    'as' => 'api.player.hud',
    'middleware' => [
        'auth',
        'rateLimit:120,60',
    ],
]);

Route::post('/api/player/attributes/allocate', 'App/Api/PlayerController@allocateAttribute', [
    'as' => 'api.player.attributes.allocate',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:60,60',
        'validate:attribute_code=required|string|max:40,points=integer|min:1|max:50',
        'audit:player.attribute_allocate',
    ],
]);

Route::post('/api/player/attributes/reset', 'App/Api/PlayerController@resetAttributes', [
    'as' => 'api.player.attributes.reset',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:20,60',
        'audit:player.attribute_reset',
    ],
]);

Route::post('/api/player/rest', 'App/Api/PlayerController@rest', [
    'as' => 'api.player.rest',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:30,60',
        'validate:duration_minutes=integer|min:1|max:120',
        'audit:player.rest',
    ],
]);

Route::post('/api/player/consume', 'App/Api/PlayerController@consume', [
    'as' => 'api.player.consume',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:60,60',
        'validate:item_public_id=required|string|max:64',
        'audit:player.consume',
    ],
]);

Route::post('/api/player/equipment/swap', 'App/Api/PlayerController@swapEquipmentSlots', [
    'as' => 'api.player.equipment.swap',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:60,60',
        'validate:from_slot=required|string|max:40,to_slot=required|string|max:40',
        'audit:player.equipment_swap',
    ],
]);
