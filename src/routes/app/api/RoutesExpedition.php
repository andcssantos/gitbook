<?php

use App\Http\Route;

Route::get('/api/expeditions/active', 'App/Api/ExpeditionController@active', [
    'as' => 'api.expeditions.active',
    'middleware' => [
        'auth',
        'rateLimit:120,60',
    ],
]);

Route::post('/api/expeditions/start', 'App/Api/ExpeditionController@start', [
    'as' => 'api.expeditions.start',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:20,60',
        'idempotency:api.expeditions.start',
        'validate:biome_code=required|string|max:60,duration_minutes=nullable|int|min:5|max:240',
        'audit:expeditions.start',
    ],
]);

Route::post('/api/expeditions/complete', 'App/Api/ExpeditionController@complete', [
    'as' => 'api.expeditions.complete',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:20,60',
        'idempotency:api.expeditions.complete',
        'audit:expeditions.complete',
    ],
]);
