<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\SetupController;
use App\Http\Controllers\BrowseController;
use App\Http\Controllers\EpisodeSourceController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\IngestController;
use App\Http\Controllers\InteractionController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ProfileSelectionController;
use App\Http\Controllers\Auth\SocialController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\StreamController;
use App\Http\Controllers\TitleController;
use App\Http\Controllers\WatchController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

// ---- SEO ---------------------------------------------------------------
Route::get('/sitemap.xml', \App\Http\Controllers\SitemapController::class)->name('sitemap');

// ---- Public info pages (guests + members) ------------------------------
Route::get('/download', [PageController::class, 'download'])->name('download');
Route::get('/download/apk', [\App\Http\Controllers\AppDownloadController::class, 'apk'])
    ->middleware('throttle:30,1')->name('download.apk');
Route::get('/help', [PageController::class, 'help'])->name('help');
Route::get('/privacy', [PageController::class, 'privacy'])->name('privacy');
Route::get('/terms', [PageController::class, 'terms'])->name('terms');

// ---- First-run setup (only while no admin exists) -----------------------
Route::middleware('throttle:10,1')->group(function () {
    Route::get('/setup', [SetupController::class, 'show'])->name('setup');
    Route::post('/setup', [SetupController::class, 'store']);
});

// ---- Desktop ingest bridge (token-authed, no session) ------------------
Route::prefix('api/ingest')->middleware('throttle:120,1')->group(function () {
    Route::get('pending', [IngestController::class, 'pending'])->name('ingest.pending');
    Route::post('episode', [IngestController::class, 'store'])->name('ingest.store');
    Route::post('episode/{episode}/failed', [IngestController::class, 'failed'])->name('ingest.failed');
});

// ---- Guest auth --------------------------------------------------------
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware(['throttle:10,1', 'turnstile']);
    Route::get('/register', [RegisterController::class, 'show'])->name('register');
    Route::post('/register', [RegisterController::class, 'register'])->middleware(['throttle:10,1', 'turnstile']);

    // Social sign-in (Google / LINE) — requires laravel/socialite on the server.
    Route::get('/auth/{provider}/redirect', [SocialController::class, 'redirect'])
        ->whereIn('provider', ['google', 'line'])->name('social.redirect');
    Route::get('/auth/{provider}/callback', [SocialController::class, 'callback'])
        ->whereIn('provider', ['google', 'line'])->name('social.callback');
});

// ---- Mobile auth bridge (reuses the web sign-in → issues an app token) --
// The app opens /mauth/start in an in-app browser; after the normal web login
// it lands on /mauth/issue, which deep-links back with a one-time code.
// NB: the path is deliberately NOT under /app/… — the root .htaccess 404s the
// top-level /app/ path (it guards the Laravel source dir), which silently ate
// the query string on the bridge routes.
Route::get('/mauth/start', [\App\Http\Controllers\Api\App\AuthController::class, 'start'])
    ->name('app.auth.start');
Route::get('/mauth/issue', [\App\Http\Controllers\Api\App\AuthController::class, 'issue'])
    ->middleware('auth')->name('app.auth.issue');

// ---- Authenticated (choose profile) ------------------------------------
Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    Route::get('/profiles', [ProfileSelectionController::class, 'index'])->name('profiles.index');
    Route::post('/profiles', [ProfileSelectionController::class, 'store'])->name('profiles.store');
    Route::post('/profiles/{profile}/select', [ProfileSelectionController::class, 'select'])->name('profiles.select');
    Route::delete('/profiles/{profile}', [ProfileSelectionController::class, 'destroy'])->name('profiles.destroy');
});

// ---- The streaming app (needs an active profile) -----------------------
Route::middleware(['auth', 'profile'])->group(function () {
    Route::get('/browse', [BrowseController::class, 'home'])->name('browse');
    Route::get('/browse/feed', [BrowseController::class, 'feed'])->middleware('throttle:120,1')->name('browse.feed');
    Route::get('/browse/row', [BrowseController::class, 'row'])->middleware('throttle:180,1')->name('browse.row');
    Route::get('/series', [BrowseController::class, 'series'])->name('browse.series');
    Route::get('/movies', [BrowseController::class, 'movies'])->name('browse.movies');
    Route::get('/anime', [BrowseController::class, 'anime'])->name('browse.anime');
    Route::get('/vertical', [BrowseController::class, 'vertical'])->name('browse.vertical');
    Route::get('/genre/{genre}', [BrowseController::class, 'genre'])->name('browse.genre');
    Route::get('/my-list', [BrowseController::class, 'myList'])->name('browse.mylist');

    Route::get('/search', [SearchController::class, 'index'])->name('search');
    Route::get('/api/search', [SearchController::class, 'suggest'])->name('search.suggest');

    Route::get('/title/{content}', [TitleController::class, 'show'])->name('title.show');
    Route::get('/title/{content}/modal', [TitleController::class, 'modal'])->name('title.modal');
    Route::get('/watch/{content}/{episode?}', [WatchController::class, 'show'])->name('watch');

    Route::post('/api/content/{content}/list', [InteractionController::class, 'toggleMyList'])->name('content.list');
    Route::post('/api/content/{content}/like', [InteractionController::class, 'toggleLike'])->name('content.like');
    Route::post('/api/content/{content}/progress', [InteractionController::class, 'progress'])->name('content.progress');
    Route::post('/api/content/{content}/comment', [InteractionController::class, 'comment'])->middleware(['throttle:30,1', 'turnstile'])->name('content.comment');
    Route::post('/api/content/{content}/rate', [InteractionController::class, 'rate'])->name('content.rate');

    Route::get('/api/episode/{episode}/source', [EpisodeSourceController::class, 'resolve'])->name('episode.source');
    Route::post('/api/episode/{episode}/thumb', [EpisodeSourceController::class, 'captureThumb'])
        ->middleware('throttle:60,1')->name('episode.thumb');

    // Member account: Pro status, coins, referral code + share, redeem a code.
    Route::get('/account', [\App\Http\Controllers\AccountController::class, 'index'])->name('account');
    Route::post('/account/redeem', [\App\Http\Controllers\AccountController::class, 'redeem'])
        ->middleware('throttle:10,1')->name('account.redeem');
});

// ---- Public streaming proxy (guests can watch) -------------------------
// Deliberately NOT behind auth: the mobile app and guest web viewers stream
// without a session (members still get history/resume/favorites via the
// authenticated interaction routes above). This is safe as an open endpoint —
// segment URLs are HMAC-signed so it can't be abused as an SSRF proxy, and it
// only exposes the same imported streams the resolver already hands out.
Route::get('/stream/{episode}/index.m3u8', [StreamController::class, 'manifest'])->name('stream.manifest');
Route::get('/stream/{episode}/segment', [StreamController::class, 'segment'])->name('stream.segment');
Route::get('/stream/{episode}/video.mp4', [StreamController::class, 'mp4'])->name('stream.mp4');

// ---- Admin -------------------------------------------------------------
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [Admin\DashboardController::class, 'index'])->name('dashboard');

    // Mobile-app diagnostics viewer (app_debug_logs).
    Route::get('debug', [Admin\DebugLogController::class, 'index'])->name('debug.index');
    Route::delete('debug', [Admin\DebugLogController::class, 'clear'])->name('debug.clear');

    // Batch episode-cover generation (ffmpeg → WebP), with a live progress bar.
    Route::get('thumbs', [Admin\ThumbController::class, 'index'])->name('thumbs.index');
    Route::get('thumbs/search', [Admin\ThumbController::class, 'search'])->name('thumbs.search');
    Route::post('thumbs/count', [Admin\ThumbController::class, 'count'])->name('thumbs.count');
    Route::post('thumbs/run', [Admin\ThumbController::class, 'run'])->name('thumbs.run');

    Route::get('import', [Admin\ImportController::class, 'index'])->name('import.index');
    Route::post('import/sync', [Admin\ImportController::class, 'sync'])->name('import.sync');
    Route::post('import/batch', [Admin\ImportController::class, 'batch'])->name('import.batch');
    Route::post('import/auto', [Admin\ImportController::class, 'auto'])->name('import.auto');
    Route::post('import', [Admin\ImportController::class, 'import'])->name('import.store');

    Route::get('storage', [Admin\StorageController::class, 'index'])->name('storage.index');
    Route::post('episodes/{episode}/mirror', [Admin\StorageController::class, 'mirror'])->name('storage.mirror');
    Route::post('episodes/{episode}/thumb', [Admin\StorageController::class, 'setThumb'])->name('storage.set-thumb');
    Route::delete('episodes/{episode}/mirror', [Admin\StorageController::class, 'unmirror'])->name('storage.unmirror');
    Route::post('contents/{content}/mirror-all', [Admin\StorageController::class, 'mirrorContent'])->name('storage.mirror-content');
    Route::post('contents/{content}/poster', [Admin\StorageController::class, 'setPoster'])->name('storage.set-poster');

    Route::post('contents/reset-all-thumbs', [Admin\ContentController::class, 'resetAllThumbs'])->name('contents.reset-all-thumbs');
    Route::resource('contents', Admin\ContentController::class)->except('show');
    Route::post('contents/{content}/reset-thumbs', [Admin\ContentController::class, 'resetThumbs'])->name('contents.reset-thumbs');
    Route::post('contents/{content}/episodes', [Admin\EpisodeController::class, 'store'])->name('contents.episodes.store');
    Route::delete('contents/{content}/episodes/{episode}', [Admin\EpisodeController::class, 'destroy'])->name('contents.episodes.destroy');

    Route::get('genres', [Admin\GenreController::class, 'index'])->name('genres.index');
    Route::post('genres', [Admin\GenreController::class, 'store'])->name('genres.store');
    Route::put('genres/{genre}', [Admin\GenreController::class, 'update'])->name('genres.update');
    Route::delete('genres/{genre}', [Admin\GenreController::class, 'destroy'])->name('genres.destroy');

    Route::get('announcements', [Admin\AnnouncementController::class, 'index'])->name('announcements.index');
    Route::post('announcements', [Admin\AnnouncementController::class, 'store'])->name('announcements.store');
    Route::put('announcements/{announcement}', [Admin\AnnouncementController::class, 'update'])->name('announcements.update');
    Route::delete('announcements/{announcement}', [Admin\AnnouncementController::class, 'destroy'])->name('announcements.destroy');

    Route::get('comments', [Admin\CommentController::class, 'index'])->name('comments.index');
    Route::delete('comments/{comment}', [Admin\CommentController::class, 'destroy'])->name('comments.destroy');

    Route::get('users', [Admin\UserController::class, 'index'])->name('users.index');
    Route::put('users/{user}', [Admin\UserController::class, 'update'])->name('users.update');

    Route::get('settings', [Admin\SettingController::class, 'index'])->name('settings.index');
    Route::put('settings', [Admin\SettingController::class, 'update'])->name('settings.update');

    Route::get('membership', [Admin\MembershipController::class, 'index'])->name('membership.index');
    Route::put('membership', [Admin\MembershipController::class, 'update'])->name('membership.update');

    Route::get('analytics', [Admin\AnalyticsController::class, 'index'])->name('analytics');

    Route::get('seo', [Admin\SeoController::class, 'index'])->name('seo');
    Route::put('seo', [Admin\SeoController::class, 'update'])->name('seo.update');
});
