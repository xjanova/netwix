<?php

use App\Http\Controllers\Api\App\AuthController;
use App\Http\Controllers\Api\App\CatalogController;
use App\Http\Controllers\Api\App\SourceController;
use Illuminate\Support\Facades\Route;

/*
|----------------------------------------------------------------------
| Mobile app API  (/api/app/*)
|----------------------------------------------------------------------
| Public JSON API for the NetWix mobile app. Everything here is public
| (guests browse + watch mirrored content). Member routes (auth token,
| progress, likes, comments, ratings) are added in a later phase.
| Responses use the {success, data} envelope.
*/

// Public + unauthenticated, so rate-limit it (the source endpoint hands out signed CDN links and
// resolves upstream on a cache miss — cap enumeration/abuse per IP).
Route::prefix('app')->middleware('throttle:90,1')->group(function () {
    Route::get('home', [CatalogController::class, 'home']);
    Route::get('titles', [CatalogController::class, 'titles']);
    Route::get('titles/{slug}', [CatalogController::class, 'show']);
    Route::get('search', [CatalogController::class, 'search']);
    Route::get('episodes/{episode}/source', [SourceController::class, 'source']);

    // Auth: exchange a one-time login code for a bearer token, then member calls.
    Route::post('auth/exchange', [AuthController::class, 'exchange']);
    Route::middleware('auth.apptoken')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
    });
});
