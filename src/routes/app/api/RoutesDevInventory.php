<?php

use App\Http\Route;

Route::post('/api/dev/inventory/grant-item', 'App/Api/DevInventoryController@grantItem', [
    'as' => 'api.dev.inventory.grant-item',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:30,60',
        'idempotency:api.dev.inventory.grant-item',
        'validate:item_definition_code=required|string|max:80,quantity=required|int|min:1,quality_bucket=nullable|string|max:40,quality_value=nullable|numeric,material_origin_code=nullable|string|max:80',
        'audit:inventory.dev.grant-item',
    ],
]);
