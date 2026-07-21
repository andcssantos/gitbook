<?php

use App\Http\Route;

Route::get('/api/missions', 'App/Api/MissionController@list', [
    'as' => 'api.missions.list',
    'middleware' => [
        'auth',
        'rateLimit:60,60',
    ],
]);

Route::post('/api/missions/claim', 'App/Api/MissionController@claim', [
    'as' => 'api.missions.claim',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:30,60',
        'validate:mission_code=required|string|max:120',
        'audit:missions.claim',
    ],
]);
