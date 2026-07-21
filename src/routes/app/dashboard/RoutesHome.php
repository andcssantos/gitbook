<?php

use App\Http\Route;

# | ************************************* | #
# |  Routes Dashboard                     | #
# | ************************************* | #

Route::group(['prefix' => '/dashboard', 'middleware' => ['auth']], function (): void {
    Route::get('/', 'App/Dashboard/InventoryController@index', ['as' => 'dashboard.home']);
    Route::redirect('/inventory', '/dashboard');
    Route::get('/admin', 'App/Dashboard/AdminContentController@index', ['as' => 'dashboard.admin']);
});

Route::get('/campaign', 'App/Dashboard/CampaignPageController@index', [
    'as' => 'campaign.home',
    'middleware' => ['auth'],
]);

Route::redirect('/painel', '/dashboard');

