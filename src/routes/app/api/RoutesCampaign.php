<?php

use App\Http\Route;

Route::get('/api/campaign/worlds/{worldCode:string:60}', 'App/Api/CampaignController@showWorld', [
    'as' => 'api.campaign.worlds.show',
    'middleware' => [
        'auth',
        'rateLimit:120,60',
    ],
]);

Route::get('/api/campaign/stages/active', 'App/Api/CampaignController@activeStage', [
    'as' => 'api.campaign.stages.active',
    'middleware' => [
        'auth',
        'rateLimit:120,60',
    ],
]);

Route::post('/api/campaign/stages/start', 'App/Api/CampaignController@startStage', [
    'as' => 'api.campaign.stages.start',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:30,60',
        'idempotency:api.campaign.stages.start',
    ],
]);

Route::post('/api/campaign/stages/tick', 'App/Api/CampaignController@tickStage', [
    'as' => 'api.campaign.stages.tick',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:180,60',
    ],
]);

Route::post('/api/campaign/stages/leave', 'App/Api/CampaignController@leaveStage', [
    'as' => 'api.campaign.stages.leave',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:30,60',
    ],
]);

Route::get('/api/campaign/stages/loot', 'App/Api/CampaignController@lootState', [
    'as' => 'api.campaign.stages.loot',
    'middleware' => [
        'auth',
        'rateLimit:60,60',
    ],
]);

Route::post('/api/campaign/stages/loot/commit', 'App/Api/CampaignController@lootCommit', [
    'as' => 'api.campaign.stages.loot.commit',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:30,60',
        'idempotency:api.campaign.stages.loot.commit',
    ],
]);

Route::post('/api/campaign/stages/potions/use', 'App/Api/CampaignController@usePotion', [
    'as' => 'api.campaign.stages.potions.use',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:60,60',
    ],
]);

Route::post('/api/campaign/village/interact', 'App/Api/CampaignController@villageInteract', [
    'as' => 'api.campaign.village.interact',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:60,60',
    ],
]);
