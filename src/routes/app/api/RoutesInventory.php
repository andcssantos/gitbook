<?php

use App\Http\Route;

Route::get('/api/inventory', 'App/Api/InventoryController@index', [
    'as' => 'api.inventory.index',
    'middleware' => [
        'auth',
        'rateLimit:120,60',
    ],
]);

Route::post('/api/inventory/move', 'App/Api/InventoryController@move', [
    'as' => 'api.inventory.move',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:60,60',
        'idempotency:api.inventory.move',
        'validate:item_public_id=required|string|max:64,source_container_public_id=required|string|max:64,target_container_public_id=required|string|max:64,grid_x=required|int|min:0,grid_y=required|int|min:0,expected_placement_version=required|int|min:1',
        'audit:inventory.move',
    ],
]);

Route::post('/api/inventory/stacks/merge', 'App/Api/InventoryController@mergeStack', [
    'as' => 'api.inventory.stacks.merge',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:60,60',
        'idempotency:api.inventory.stacks.merge',
        'validate:source_item_public_id=required|string|max:64,target_item_public_id=required|string|max:64,quantity=required|int|min:1',
        'audit:inventory.stacks.merge',
    ],
]);

Route::post('/api/inventory/stacks/split', 'App/Api/InventoryController@splitStack', [
    'as' => 'api.inventory.stacks.split',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:60,60',
        'idempotency:api.inventory.stacks.split',
        'validate:source_item_public_id=required|string|max:64,source_container_public_id=required|string|max:64,target_container_public_id=required|string|max:64,quantity=required|int|min:1,grid_x=required|int|min:0,grid_y=required|int|min:0,expected_placement_version=required|int|min:1',
        'audit:inventory.stacks.split',
    ],
]);
