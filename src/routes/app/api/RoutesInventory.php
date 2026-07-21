<?php

use App\Http\Route;

Route::get('/api/inventory', 'App/Api/InventoryController@index', [
    'as' => 'api.inventory.index',
    'middleware' => [
        'auth',
        'rateLimit:120,60',
    ],
]);

Route::get('/api/inventory/summary', 'App/Api/InventoryController@summary', [
    'as' => 'api.inventory.summary',
    'middleware' => [
        'auth',
        'rateLimit:120,60',
    ],
]);

Route::get('/api/inventory/containers/{containerPublicId:string:64}', 'App/Api/InventoryController@showContainer', [
    'as' => 'api.inventory.containers.show',
    'middleware' => [
        'auth',
        'rateLimit:120,60',
    ],
]);

Route::get('/api/inventory/items/{itemPublicId:string:64}', 'App/Api/InventoryController@showItem', [
    'as' => 'api.inventory.items.show',
    'middleware' => [
        'auth',
        'rateLimit:120,60',
    ],
]);

Route::get('/api/inventory/items/{itemPublicId:string:64}/investigate', 'App/Api/InventoryController@investigateItem', [
    'as' => 'api.inventory.items.investigate',
    'middleware' => [
        'auth',
        'rateLimit:120,60',
    ],
]);

Route::get('/api/inventory/materials', 'App/Api/InventoryController@materials', [
    'as' => 'api.inventory.materials',
    'middleware' => [
        'auth',
        'rateLimit:120,60',
    ],
]);

Route::get('/api/inventory/crafting/workspaces', 'App/Api/InventoryController@craftingWorkspaces', [
    'as' => 'api.inventory.crafting.workspaces',
    'middleware' => [
        'auth',
        'rateLimit:120,60',
    ],
]);

Route::post('/api/inventory/crafting/preview', 'App/Api/InventoryController@craftingPreview', [
    'as' => 'api.inventory.crafting.preview',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:120,60',
        'validate:workspace=required|string|max:30,slots=required|array',
    ],
]);

Route::post('/api/inventory/crafting/execute', 'App/Api/InventoryController@craftingExecute', [
    'as' => 'api.inventory.crafting.execute',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:30,60',
        'idempotency:api.inventory.crafting.execute',
        'validate:workspace=required|string|max:30,slots=required|array',
        'audit:inventory.crafting.execute',
    ],
]);

Route::post('/api/inventory/crafting/recipes/share', 'App/Api/InventoryController@craftingShareRecipe', [
    'as' => 'api.inventory.crafting.recipes.share',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:30,60',
        'validate:recipe_code=required|string|max:80',
    ],
]);

Route::post('/api/inventory/move', 'App/Api/InventoryController@move', [
    'as' => 'api.inventory.move',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:60,60',
        'idempotency:api.inventory.move',
        'validate:item_public_id=required|string|max:64,source_container_public_id=required|string|max:64,target_container_public_id=required|string|max:64,grid_x=required|int|min:0,grid_y=required|int|min:0,rotated=nullable|boolean,expected_placement_version=required|int|min:1',
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

Route::post('/api/inventory/enhance/preview', 'App/Api/InventoryController@enhancePreview', [
    'as' => 'api.inventory.enhance.preview',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:120,60',
        'validate:jewel_item_public_id=required|string|max:64,target_item_public_id=required|string|max:64',
    ],
]);

Route::post('/api/inventory/enhance', 'App/Api/InventoryController@enhance', [
    'as' => 'api.inventory.enhance',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:60,60',
        'idempotency:api.inventory.enhance',
        'validate:jewel_item_public_id=required|string|max:64,target_item_public_id=required|string|max:64,expected_jewel_placement_version=required|int|min:1,expected_target_placement_version=required|int|min:1,confirm=nullable|boolean',
        'audit:inventory.enhance',
    ],
]);

Route::post('/api/inventory/socket/preview', 'App/Api/InventoryController@socketPreview', [
    'as' => 'api.inventory.socket.preview',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:120,60',
        'validate:gem_item_public_id=required|string|max:64,target_item_public_id=required|string|max:64',
    ],
]);

Route::post('/api/inventory/socket', 'App/Api/InventoryController@socket', [
    'as' => 'api.inventory.socket',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:60,60',
        'idempotency:api.inventory.socket',
        'validate:gem_item_public_id=required|string|max:64,target_item_public_id=required|string|max:64,expected_gem_placement_version=required|int|min:1,expected_target_placement_version=required|int|min:1,confirm=nullable|boolean',
        'audit:inventory.socket',
    ],
]);

Route::post('/api/inventory/containers/{containerPublicId:string:64}/organize', 'App/Api/InventoryController@organizeContainer', [
    'as' => 'api.inventory.containers.organize',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:30,60',
        'idempotency:api.inventory.containers.organize',
        'validate:mode=nullable|string|in:type,rarity,size,name,compact',
        'audit:inventory.organize',
    ],
]);

Route::post('/api/inventory/containers/{containerPublicId:string:64}/expand', 'App/Api/InventoryController@expandContainer', [
    'as' => 'api.inventory.containers.expand',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:20,60',
        'idempotency:api.inventory.containers.expand',
        'audit:inventory.expand',
    ],
]);

Route::get('/api/inventory/containers/{containerPublicId:string:64}/expand', 'App/Api/InventoryController@expandContainerPreview', [
    'as' => 'api.inventory.containers.expand.preview',
    'middleware' => [
        'auth',
        'rateLimit:60,60',
    ],
]);

Route::patch('/api/inventory/containers/{containerPublicId:string:64}/rename', 'App/Api/InventoryController@renameContainer', [
    'as' => 'api.inventory.containers.rename',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:60,60',
        'validate:name=nullable|string|max:48',
        'audit:inventory.containers.rename',
    ],
]);

Route::patch('/api/inventory/items/{itemPublicId:string:64}/rename', 'App/Api/InventoryController@renameItem', [
    'as' => 'api.inventory.items.rename',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:60,60',
        'validate:item_name=nullable|string|max:48',
        'audit:inventory.rename',
    ],
]);

Route::get('/api/inventory/sets/codex', 'App/Api/InventoryController@setCodex', ['as' => 'api.inventory.sets.codex', 'middleware' => ['auth', 'rateLimit:120,60']]);
Route::post('/api/inventory/sets/wishlist', 'App/Api/InventoryController@toggleSetWishlist', ['as' => 'api.inventory.sets.wishlist', 'middleware' => ['auth', 'csrf', 'rateLimit:60,60', 'validate:definition_code=required|string|max:80,wishlisted=required|boolean']]);

Route::get('/api/inventory/loadouts', 'App/Api/InventoryController@loadouts', ['as' => 'api.inventory.loadouts', 'middleware' => ['auth', 'rateLimit:120,60']]);
Route::post('/api/inventory/loadouts', 'App/Api/InventoryController@saveLoadout', ['as' => 'api.inventory.loadouts.store', 'middleware' => ['auth', 'csrf', 'rateLimit:30,60', 'idempotency:api.inventory.loadouts.store', 'validate:slot_index=required|int|min:0|max:4,name=required|string|max:48', 'audit:inventory.loadouts.save']]);
Route::post('/api/inventory/loadouts/save', 'App/Api/InventoryController@saveLoadout', ['as' => 'api.inventory.loadouts.save', 'middleware' => ['auth', 'csrf', 'rateLimit:30,60', 'idempotency:api.inventory.loadouts.save', 'validate:slot_index=required|int|min:0|max:4,name=required|string|max:48', 'audit:inventory.loadouts.save']]);
Route::post('/api/inventory/loadouts/apply', 'App/Api/InventoryController@applyLoadout', ['as' => 'api.inventory.loadouts.apply', 'middleware' => ['auth', 'csrf', 'rateLimit:30,60', 'idempotency:api.inventory.loadouts.apply', 'validate:loadout_public_id=required|string|max:64', 'audit:inventory.loadouts.apply']]);

Route::post('/api/inventory/socket/unsocket/preview', 'App/Api/InventoryController@unsocketPreview', ['as' => 'api.inventory.socket.unsocket.preview', 'middleware' => ['auth', 'csrf', 'rateLimit:120,60', 'validate:target_item_public_id=required|string|max:64,socket_index=required|int|min:0']]);
Route::post('/api/inventory/socket/unsocket', 'App/Api/InventoryController@unsocket', ['as' => 'api.inventory.socket.unsocket', 'middleware' => ['auth', 'csrf', 'rateLimit:30,60', 'idempotency:api.inventory.socket.unsocket', 'validate:target_item_public_id=required|string|max:64,socket_index=required|int|min:0,confirm=required|boolean', 'audit:inventory.socket.unsocket']]);

Route::get('/api/inventory/crafting/recipes', 'App/Api/InventoryController@craftingRecipes', ['as' => 'api.inventory.crafting.recipes', 'middleware' => ['auth', 'rateLimit:120,60']]);
Route::get('/api/inventory/stash-vault', 'App/Api/InventoryController@stashVault', ['as' => 'api.inventory.stash-vault', 'middleware' => ['auth', 'rateLimit:120,60']]);

Route::get('/api/inventory/exploration-loadout', 'App/Api/InventoryController@explorationLoadout', ['as' => 'api.inventory.exploration-loadout', 'middleware' => ['auth', 'rateLimit:120,60']]);
Route::put('/api/inventory/exploration-loadout', 'App/Api/InventoryController@saveExplorationLoadout', ['as' => 'api.inventory.exploration-loadout.save', 'middleware' => ['auth', 'csrf', 'rateLimit:30,60', 'validate:backpack_item_public_id=nullable|string|max:64,tool_item_public_ids=nullable|array,potion_item_public_ids=nullable|array,notes=nullable|string|max:180', 'audit:inventory.exploration-loadout.save']]);
Route::post('/api/inventory/exploration-loadout/apply', 'App/Api/InventoryController@applyExplorationLoadout', ['as' => 'api.inventory.exploration-loadout.apply', 'middleware' => ['auth', 'csrf', 'rateLimit:30,60', 'idempotency:api.inventory.exploration-loadout.apply', 'audit:inventory.exploration-loadout.apply']]);
