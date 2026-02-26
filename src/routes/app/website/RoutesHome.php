<?php

use App\Http\Route;

# | ************************************* | #
# |  Routes Website                       | #
# | ************************************* | #

Route::group(['prefix' => '/'], function (): void {
    $controller = 'App/Website/HomeController';

    Route::get('/', "{$controller}@index", ['as' => 'website.home']);
});

Route::redirect('/home', '/');
