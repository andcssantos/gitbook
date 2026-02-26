<?php

use App\Http\Route;

# | ************************************* | #
# |  Routes Dashboard                     | #
# | ************************************* | #

Route::group(['prefix' => '/dashboard'], function (): void {
    $controller = 'App/Dashboard/HomeController';

    Route::get('/', "{$controller}@index", ['as' => 'dashboard.home']);
});

Route::redirect('/painel', '/dashboard');
