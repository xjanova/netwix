<?php

use App\Http\Controllers\Api\App\AdController;
use App\Http\Controllers\Api\App\AffiliateController;
use App\Http\Controllers\Api\App\AuthController;
use App\Http\Controllers\Api\App\CatalogController;
use App\Http\Controllers\Api\App\DebugController;
use App\Http\Controllers\Api\App\FeedbackController;
use App\Http\Controllers\Api\App\LibraryController;
use App\Http\Controllers\Api\App\MembershipController;
use App\Http\Controllers\Api\App\MissionController;
use App\Http\Controllers\Api\App\SourceController;
use App\Http\Controllers\Api\App\WalletController;
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
    // Catalogue reads are browsable by guests, but the viewer still has to be
    // resolvable: a guest is held to the public listing (never adult), a member
    // sees the full catalogue. Without this the app served 18+ titles to anyone.
    Route::middleware('auth.apptoken.optional')->group(function () {
        Route::get('home', [CatalogController::class, 'home']);
        Route::get('titles', [CatalogController::class, 'titles']);
        Route::get('titles/{slug}', [CatalogController::class, 'show']);
        Route::get('genres', [CatalogController::class, 'genres']);
        Route::get('search', [CatalogController::class, 'search']);

        // Pre-roll ad for a title — same campaigns as the web player. Optional
        // auth so hide_for_pro can be honoured for a signed-in Pro member.
        Route::get('content/{content:id}/ad', [AdController::class, 'preroll']);
    });

    Route::get('episodes/{episode}/source', [SourceController::class, 'source']);

    // Public reads: comments list + rating summary (guests can see them).
    Route::get('content/{content:id}/comments', [FeedbackController::class, 'comments']);
    Route::get('content/{content:id}/ratings', [FeedbackController::class, 'ratings']);

    // Public: count a watch (deduped server-side).
    Route::post('content/{content:id}/view', [CatalogController::class, 'view']);

    // Public: admin-defined membership rules (free episodes, coin costs, Pro, referral rewards).
    Route::get('membership/config', [MembershipController::class, 'config']);

    // Diagnostics sink — public (must accept guest + failed-sign-in reports),
    // extra-throttled on top of the group limit.
    Route::post('debug', [DebugController::class, 'store'])->middleware('throttle:20,1');

    // Public: which social providers are configured (app hides the rest).
    Route::get('auth/providers', [AuthController::class, 'providers']);

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

        // Affiliate downline (levels + dividend earned) for the "My Team" screen.
        Route::get('team', [AffiliateController::class, 'team']);

        // Missions — watch a clip to earn silver/gold coins. Same MissionService
        // anti-cheat as the web /missions page; beat throttle matches the ~15s
        // client heartbeat.
        Route::get('missions', [MissionController::class, 'index']);
        Route::post('missions/{mission}/start', [MissionController::class, 'start'])->middleware('throttle:20,1');
        Route::post('missions/{mission}/beat', [MissionController::class, 'beat'])->middleware('throttle:30,1');

        // Coin economy: earn (daily/watch), episode access map, spend-to-unlock.
        Route::post('coins/earn', [MembershipController::class, 'earn']);
        Route::get('content/{content:id}/access', [MembershipController::class, 'access']);
        Route::post('episodes/{episode}/unlock', [MembershipController::class, 'unlock']);

        // Gold wallet + VIP zone: balances/rules, silver→gold convert, VIP unlock, buy Pro with gold.
        Route::get('wallet', [WalletController::class, 'state']);
        Route::post('gold/convert', [WalletController::class, 'convert']);
        Route::post('pro/buy-gold', [WalletController::class, 'buyProWithGold']);
        Route::get('content/{content:id}/vip', [WalletController::class, 'vipAccess']);
        Route::post('content/{content:id}/vip/unlock', [WalletController::class, 'unlockVip']);

        // USDT (BSC) payments: create an order, then poll it (live-verifies on the chain).
        Route::post('usdt/order', [WalletController::class, 'createOrder']);
        Route::get('usdt/order/{order}', [WalletController::class, 'orderStatus']);
        Route::post('usdt/order/{order}/check', [WalletController::class, 'orderStatus']);
    });
});
