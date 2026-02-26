<?php

use App\Http\Route;

# | ************************************* | #
# |  Routes Website Login                 | #
# | ************************************* | #

Route::group(['prefix' => '/'], function () {

    # | Define a controller
    $controller = "App/Website/HomeController";

    # | Listagem das empresas para emissão de NF's
    # | Agora, esta rota "/" só será acessível se o middleware "auth" permitir.
    Route::get("/", "{$controller}@index");

});