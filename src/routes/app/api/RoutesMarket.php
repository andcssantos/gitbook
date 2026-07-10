<?php

use App\Http\Route;

Route::get('/api/market/wallets', 'App/Api/MarketController@wallets', [
    'as' => 'api.market.wallets',
    'middleware' => [
        'auth',
        'rateLimit:120,60',
    ],
]);

Route::get('/api/market/listings', 'App/Api/MarketController@listings', [
    'as' => 'api.market.listings',
    'middleware' => [
        'auth',
        'rateLimit:120,60',
    ],
]);

Route::get('/api/market/items/{itemPublicId:string:64}/price-preview', 'App/Api/MarketController@pricePreview', [
    'as' => 'api.market.items.price-preview',
    'middleware' => [
        'auth',
        'rateLimit:120,60',
    ],
]);

Route::post('/api/market/listings/{listingPublicId:string:64}/buy', 'App/Api/MarketController@buyListing', [
    'as' => 'api.market.listings.buy',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:30,60',
        'idempotency:api.market.listings.buy',
        'audit:market.buy',
    ],
]);

Route::post('/api/market/listings/{listingPublicId:string:64}/cancel', 'App/Api/MarketController@cancelListing', [
    'as' => 'api.market.listings.cancel',
    'middleware' => [
        'auth',
        'csrf',
        'rateLimit:30,60',
        'idempotency:api.market.listings.cancel',
        'audit:market.cancel',
    ],
]);
