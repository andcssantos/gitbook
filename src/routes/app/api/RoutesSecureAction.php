<?php

use App\Http\Route;

Route::post('/api/example/action', 'App/Api/SecureActionController@store', [
    'as' => 'api.example.action.store',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:30,60',
        'idempotency:api.example.action',
        'validate:action=required|string|max:80,client_tick=required|int|min:0,nonce=required|string|max:120',
        'audit:api.example.action',
    ],
]);
