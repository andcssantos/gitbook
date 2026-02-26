<?php

use App\Http\Route;

# | ************************************* | #
# |  Routes Joker Global                  | #
# | ************************************* | #

// Compatibilidade com URLs antigas
Route::redirect('/dashboard/home', '/dashboard');
Route::redirect('/site/home', '/');
