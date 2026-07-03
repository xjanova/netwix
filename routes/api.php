<?php

use App\Http\Controllers\Api\App\AuthController;
use App\Http\Controllers\Api\App\CatalogController;
use App\Http\Controllers\Api\App\FeedbackController;
use App\Http\Controllers\Api\App\LibraryController;
use App\Http\Controllers\Api\App\MembershipController;
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

    // Public reads: comments list + rating summary (guests can see them).
    Route::get('content/{content:id}/comments', [FeedbackController::class, 'comments']);
    Route::get('content/{content:id}/ratings', [FeedbackController::class, 'ratings']);

    // Public: admin-defined membership rules (free episodes, coin costs, Pro, referral rewards).
    Route::get('membership/config', [MembershipController::class, 'config']);

    // Auth: exchange a one-time login code for a bearer token, then member calls.
    Route::post('auth/exchange', [AuthController::class, 'exchange']);
    Route::middleware('auth.apptoken')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        // Member library
        Route::get('my-list', [LibraryController::class, 'myList']);
        Route::get('progress', [LibraryController::class, 'progress']);
        Route::post('progress', [LibraryController::class, 'saveProgress']);
        Route::get('content/{content:id}/state', [LibraryController::class, 'contentState']);
        Route::post('content/{content:id}/list', [LibraryController::class, 'toggleList']);
        Route::post('content/{content:id}/like', [LibraryController::class, 'toggleLike']);

        // Feedback (writes)
        Route::post('content/{content:id}/comments', [FeedbackController::class, 'storeComment']);
        Route::post('content/{content:id}/rating', [FeedbackController::class, 'storeRating']);

        // Membership: this member's Pro/coins/referral state + redeem a code.
        Route::get('membership', [MembershipController::class, 'me']);
        Route::post('referral/redeem', [MembershipController::class, 'redeem']);

        // Coin economy: earn (daily/watch), episode access map, spend-to-unlock.
        Route::post('coins/earn', [MembershipController::class, 'earn']);
        Route::get('content/{content:id}/access', [MembershipController::class, 'access']);
        Route::post('episodes/{episode}/unlock', [MembershipController::class, 'unlock']);
    });
});
