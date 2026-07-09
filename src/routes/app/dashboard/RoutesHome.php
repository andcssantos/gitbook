<?php

use App\Http\Route;

# | ************************************* | #
# |  Routes Dashboard                     | #
# | ************************************* | #

Route::group(['prefix' => '/dashboard', 'middleware' => ['auth']], function (): void {
    $controller = 'App/Dashboard/HomeController';

    Route::get('/', "{$controller}@index", ['as' => 'dashboard.home']);
    Route::get('/inventory', 'App/Dashboard/InventoryController@index', ['as' => 'dashboard.inventory']);
});

Route::redirect('/painel', '/dashboard');
