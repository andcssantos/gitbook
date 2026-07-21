<?php

use App\Http\Route;

Route::get('/api/seasons/active', 'App/Api/SeasonController@active', [
    'as' => 'api.seasons.active',
    'middleware' => [
        'auth',
        'rateLimit:60,60',
    ],
]);
