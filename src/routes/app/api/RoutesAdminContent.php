<?php

use App\Http\Route;

Route::group(['prefix' => '/api/admin', 'middleware' => ['auth', 'csrf']], function (): void {
    Route::get('/items/meta', 'App/Api/Admin/AdminItemsController@meta', [
        'as' => 'api.admin.items.meta',
        'middleware' => ['rateLimit:60,60'],
    ]);

    Route::get('/items', 'App/Api/Admin/AdminItemsController@index', [
        'as' => 'api.admin.items.index',
        'middleware' => ['rateLimit:60,60'],
    ]);

    Route::get('/items/{code}', 'App/Api/Admin/AdminItemsController@show', [
        'as' => 'api.admin.items.show',
        'middleware' => [
            'rateLimit:60,60',
            'validate:code=required|string|max:100',
        ],
    ]);

    Route::post('/items', 'App/Api/Admin/AdminItemsController@store', [
        'as' => 'api.admin.items.store',
        'middleware' => [
            'rateLimit:30,60',
            'idempotency:api.admin.items.store',
            'audit:admin.item.create',
        ],
    ]);

    Route::post('/items/{code}', 'App/Api/Admin/AdminItemsController@update', [
        'as' => 'api.admin.items.update',
        'middleware' => [
            'rateLimit:30,60',
            'idempotency:api.admin.items.update',
            'audit:admin.item.update',
            'validate:code=required|string|max:100',
        ],
    ]);

    Route::get('/investigables/meta', 'App/Api/Admin/AdminInvestigablesController@meta', [
        'as' => 'api.admin.investigables.meta',
        'middleware' => ['rateLimit:60,60'],
    ]);

    Route::get('/investigables', 'App/Api/Admin/AdminInvestigablesController@index', [
        'as' => 'api.admin.investigables.index',
        'middleware' => ['rateLimit:60,60'],
    ]);

    Route::get('/investigables/{code}', 'App/Api/Admin/AdminInvestigablesController@show', [
        'as' => 'api.admin.investigables.show',
        'middleware' => [
            'rateLimit:60,60',
            'validate:code=required|string|max:80',
        ],
    ]);

    Route::post('/investigables', 'App/Api/Admin/AdminInvestigablesController@store', [
        'as' => 'api.admin.investigables.store',
        'middleware' => [
            'rateLimit:30,60',
            'idempotency:api.admin.investigables.store',
            'audit:admin.investigable.create',
        ],
    ]);

    Route::post('/investigables/{code}', 'App/Api/Admin/AdminInvestigablesController@update', [
        'as' => 'api.admin.investigables.update',
        'middleware' => [
            'rateLimit:30,60',
            'idempotency:api.admin.investigables.update',
            'audit:admin.investigable.update',
            'validate:code=required|string|max:80',
        ],
    ]);

    Route::post('/investigables/{code}/actions', 'App/Api/Admin/AdminInvestigablesController@upsertAction', [
        'as' => 'api.admin.investigables.actions.upsert',
        'middleware' => [
            'rateLimit:30,60',
            'idempotency:api.admin.investigables.actions.upsert',
            'audit:admin.investigable.action.upsert',
            'validate:code=required|string|max:80',
        ],
    ]);

    Route::post('/investigables/{code}/actions/{action_code}/delete', 'App/Api/Admin/AdminInvestigablesController@deleteAction', [
        'as' => 'api.admin.investigables.actions.delete',
        'middleware' => [
            'rateLimit:30,60',
            'idempotency:api.admin.investigables.actions.delete',
            'audit:admin.investigable.action.delete',
            'validate:code=required|string|max:80,action_code=required|string|max:80',
        ],
    ]);

    Route::get('/properties/meta', 'App/Api/Admin/AdminItemPropertiesController@meta', [
        'as' => 'api.admin.properties.meta',
        'middleware' => ['rateLimit:60,60'],
    ]);

    Route::get('/properties', 'App/Api/Admin/AdminItemPropertiesController@index', [
        'as' => 'api.admin.properties.index',
        'middleware' => ['rateLimit:60,60'],
    ]);

    Route::get('/properties/{code}', 'App/Api/Admin/AdminItemPropertiesController@show', [
        'as' => 'api.admin.properties.show',
        'middleware' => [
            'rateLimit:60,60',
            'validate:code=required|string|max:80',
        ],
    ]);

    Route::post('/properties', 'App/Api/Admin/AdminItemPropertiesController@store', [
        'as' => 'api.admin.properties.store',
        'middleware' => [
            'rateLimit:30,60',
            'idempotency:api.admin.properties.store',
            'audit:admin.property.create',
        ],
    ]);

    Route::post('/properties/{code}', 'App/Api/Admin/AdminItemPropertiesController@update', [
        'as' => 'api.admin.properties.update',
        'middleware' => [
            'rateLimit:30,60',
            'idempotency:api.admin.properties.update',
            'audit:admin.property.update',
            'validate:code=required|string|max:80',
        ],
    ]);

    Route::get('/affixes/meta', 'App/Api/Admin/AdminItemAffixesController@meta', [
        'as' => 'api.admin.affixes.meta',
        'middleware' => ['rateLimit:60,60'],
    ]);

    Route::get('/affixes', 'App/Api/Admin/AdminItemAffixesController@index', [
        'as' => 'api.admin.affixes.index',
        'middleware' => ['rateLimit:60,60'],
    ]);

    Route::get('/affixes/{code}', 'App/Api/Admin/AdminItemAffixesController@show', [
        'as' => 'api.admin.affixes.show',
        'middleware' => [
            'rateLimit:60,60',
            'validate:code=required|string|max:80',
        ],
    ]);

    Route::post('/affixes', 'App/Api/Admin/AdminItemAffixesController@store', [
        'as' => 'api.admin.affixes.store',
        'middleware' => [
            'rateLimit:30,60',
            'idempotency:api.admin.affixes.store',
            'audit:admin.affix.create',
        ],
    ]);

    Route::post('/affixes/{code}', 'App/Api/Admin/AdminItemAffixesController@update', [
        'as' => 'api.admin.affixes.update',
        'middleware' => [
            'rateLimit:30,60',
            'idempotency:api.admin.affixes.update',
            'audit:admin.affix.update',
            'validate:code=required|string|max:80',
        ],
    ]);

    Route::get('/biomes/meta', 'App/Api/Admin/AdminBiomesController@meta', [
        'as' => 'api.admin.biomes.meta',
        'middleware' => ['rateLimit:60,60'],
    ]);

    Route::get('/biomes', 'App/Api/Admin/AdminBiomesController@index', [
        'as' => 'api.admin.biomes.index',
        'middleware' => ['rateLimit:60,60'],
    ]);

    Route::get('/biomes/{code}', 'App/Api/Admin/AdminBiomesController@show', [
        'as' => 'api.admin.biomes.show',
        'middleware' => [
            'rateLimit:60,60',
            'validate:code=required|string|max:80',
        ],
    ]);

    Route::post('/biomes', 'App/Api/Admin/AdminBiomesController@store', [
        'as' => 'api.admin.biomes.store',
        'middleware' => [
            'rateLimit:30,60',
            'idempotency:api.admin.biomes.store',
            'audit:admin.biome.create',
        ],
    ]);

    Route::post('/biomes/{code}', 'App/Api/Admin/AdminBiomesController@update', [
        'as' => 'api.admin.biomes.update',
        'middleware' => [
            'rateLimit:30,60',
            'idempotency:api.admin.biomes.update',
            'audit:admin.biome.update',
            'validate:code=required|string|max:80',
        ],
    ]);

    Route::get('/monsters/meta', 'App/Api/Admin/AdminMonstersController@meta', [
        'as' => 'api.admin.monsters.meta',
        'middleware' => ['rateLimit:60,60'],
    ]);

    Route::get('/monsters', 'App/Api/Admin/AdminMonstersController@index', [
        'as' => 'api.admin.monsters.index',
        'middleware' => ['rateLimit:60,60'],
    ]);

    Route::get('/monsters/{code}', 'App/Api/Admin/AdminMonstersController@show', [
        'as' => 'api.admin.monsters.show',
        'middleware' => [
            'rateLimit:60,60',
            'validate:code=required|string|max:80',
        ],
    ]);

    Route::post('/monsters', 'App/Api/Admin/AdminMonstersController@store', [
        'as' => 'api.admin.monsters.store',
        'middleware' => [
            'rateLimit:30,60',
            'idempotency:api.admin.monsters.store',
            'audit:admin.monster.create',
        ],
    ]);

    Route::post('/monsters/{code}', 'App/Api/Admin/AdminMonstersController@update', [
        'as' => 'api.admin.monsters.update',
        'middleware' => [
            'rateLimit:30,60',
            'idempotency:api.admin.monsters.update',
            'audit:admin.monster.update',
            'validate:code=required|string|max:80',
        ],
    ]);

    Route::get('/craft-recipes/meta', 'App/Api/Admin/AdminCraftRecipesController@meta', [
        'as' => 'api.admin.craft_recipes.meta',
        'middleware' => ['rateLimit:60,60'],
    ]);

    Route::get('/craft-recipes', 'App/Api/Admin/AdminCraftRecipesController@index', [
        'as' => 'api.admin.craft_recipes.index',
        'middleware' => ['rateLimit:60,60'],
    ]);

    Route::get('/craft-recipes/{code}', 'App/Api/Admin/AdminCraftRecipesController@show', [
        'as' => 'api.admin.craft_recipes.show',
        'middleware' => [
            'rateLimit:60,60',
            'validate:code=required|string|max:80',
        ],
    ]);

    Route::post('/craft-recipes', 'App/Api/Admin/AdminCraftRecipesController@store', [
        'as' => 'api.admin.craft_recipes.store',
        'middleware' => [
            'rateLimit:30,60',
            'idempotency:api.admin.craft_recipes.store',
            'audit:admin.craft_recipe.create',
        ],
    ]);

    Route::post('/craft-recipes/{code}', 'App/Api/Admin/AdminCraftRecipesController@update', [
        'as' => 'api.admin.craft_recipes.update',
        'middleware' => [
            'rateLimit:30,60',
            'idempotency:api.admin.craft_recipes.update',
            'audit:admin.craft_recipe.update',
            'validate:code=required|string|max:80',
        ],
    ]);
});
