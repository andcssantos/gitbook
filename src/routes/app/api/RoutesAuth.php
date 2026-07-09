<?php

use App\Http\Route;

Route::post('/api/auth/login', 'App/Api/AuthController@login', [
    'as' => 'api.auth.login',
    'middleware' => [
        'csrf',
        'rateLimit:10,60',
        'validate:email=required|email|max:160,password=required|string|min:8|max:255',
        'audit:auth.login',
    ],
]);

Route::post('/api/auth/logout', 'App/Api/AuthController@logout', [
    'as' => 'api.auth.logout',
    'middleware' => [
        'auth',
        'csrf',
        'audit:auth.logout',
    ],
]);

Route::get('/api/auth/me', 'App/Api/AuthController@me', [
    'as' => 'api.auth.me',
    'middleware' => [
        'auth',
    ],
]);
