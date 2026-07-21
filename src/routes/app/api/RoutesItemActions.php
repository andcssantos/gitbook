<?php

use App\Http\Route;

Route::post('/api/items/actions/bulk', 'App/Api/ItemActionsController@bulk', [
    'as' => 'api.items.actions.bulk',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:20,60',
        'idempotency:api.items.actions.bulk',
        'audit:items.actions.bulk',
    ],
]);

Route::get('/api/items/actions/bulk/{batchId:string:32}', 'App/Api/ItemActionsController@showBulk', [
    'as' => 'api.items.actions.bulk.show',
    'middleware' => [
        'auth',
        'rateLimit:60,60',
    ],
]);

Route::get('/api/items/{itemPublicId:string:64}/actions', 'App/Api/ItemActionsController@index', [
    'as' => 'api.items.actions.index',
    'middleware' => [
        'auth',
        'rateLimit:120,60',
    ],
]);

Route::post('/api/items/{itemPublicId:string:64}/actions/{actionCode:string:40}', 'App/Api/ItemActionsController@execute', [
    'as' => 'api.items.actions.execute',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:60,60',
        'idempotency:api.items.actions.execute',
        'validate:confirm=nullable|boolean',
        'audit:items.actions.execute',
    ],
]);
