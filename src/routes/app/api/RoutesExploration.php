<?php

use App\Http\Route;

Route::get('/api/exploration/biomes', 'App/Api/ExplorationController@listBiomes', [
    'as' => 'api.exploration.biomes',
    'middleware' => [
        'auth',
        'rateLimit:120,60',
    ],
]);

Route::get('/api/exploration/biomes/{biomeCode:string:60}/objects', 'App/Api/ExplorationController@listBiomeObjects', [
    'as' => 'api.exploration.biomes.objects',
    'middleware' => [
        'auth',
        'rateLimit:120,60',
    ],
]);

Route::post('/api/exploration/biomes/{biomeCode:string:60}/position', 'App/Api/ExplorationController@updateBiomePosition', [
    'as' => 'api.exploration.biomes.position',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:60,60',
        'validate:map_x=required|numeric|min:0,map_y=required|numeric|min:0',
    ],
]);

Route::post('/api/exploration/objects/{objectPublicId:string:64}/analyze', 'App/Api/ExplorationController@analyzeObject', [
    'as' => 'api.exploration.objects.analyze',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:60,60',
        'idempotency:api.exploration.objects.analyze',
        'validate:tool_item_public_id=required|string|max:64',
        'audit:exploration.objects.analyze',
    ],
]);

Route::post('/api/exploration/objects/{objectPublicId:string:64}/actions/{actionCode:string:40}', 'App/Api/ExplorationController@executeObjectAction', [
    'as' => 'api.exploration.objects.actions.execute',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:60,60',
        'idempotency:api.exploration.objects.actions.execute',
        'validate:tool_item_public_id=required|string|max:64',
        'audit:exploration.objects.actions.execute',
    ],
]);
