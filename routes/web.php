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
use App\Http\Controllers\ProfileSelectionController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\StreamController;
use App\Http\Controllers\TitleController;
use App\Http\Controllers\WatchController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

// ---- First-run setup (only while no admin exists) -----------------------
Route::middleware('throttle:10,1')->group(function () {
    Route::get('/setup', [SetupController::class, 'show'])->name('setup');
    Route::post('/setup', [SetupController::class, 'store']);
});

// ---- Desktop ingest bridge (token-authed, no session) ------------------
Route::prefix('api/ingest')->middleware('throttle:120,1')->group(function () {
    Route::get('pending', [IngestController::class, 'pending'])->name('ingest.pending');
    Route::post('episode', [IngestController::class, 'store'])->name('ingest.store');
});

// ---- Guest auth --------------------------------------------------------
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/register', [RegisterController::class, 'show'])->name('register');
    Route::post('/register', [RegisterController::class, 'register'])->middleware('throttle:10,1');
});

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
    Route::get('/series', [BrowseController::class, 'series'])->name('browse.series');
    Route::get('/movies', [BrowseController::class, 'movies'])->name('browse.movies');
    Route::get('/vertical', [BrowseController::class, 'vertical'])->name('browse.vertical');
    Route::get('/my-list', [BrowseController::class, 'myList'])->name('browse.mylist');

    Route::get('/search', [SearchController::class, 'index'])->name('search');
    Route::get('/api/search', [SearchController::class, 'suggest'])->name('search.suggest');

    Route::get('/title/{content}', [TitleController::class, 'show'])->name('title.show');
    Route::get('/title/{content}/modal', [TitleController::class, 'modal'])->name('title.modal');
    Route::get('/watch/{content}/{episode?}', [WatchController::class, 'show'])->name('watch');

    Route::post('/api/content/{content}/list', [InteractionController::class, 'toggleMyList'])->name('content.list');
    Route::post('/api/content/{content}/like', [InteractionController::class, 'toggleLike'])->name('content.like');
    Route::post('/api/content/{content}/progress', [InteractionController::class, 'progress'])->name('content.progress');

    Route::get('/api/episode/{episode}/source', [EpisodeSourceController::class, 'resolve'])->name('episode.source');

    // Streaming proxy for imported content (hides expiring URLs, strips fake segment headers).
    Route::get('/stream/{episode}/index.m3u8', [StreamController::class, 'manifest'])->name('stream.manifest');
    Route::get('/stream/{episode}/segment', [StreamController::class, 'segment'])->name('stream.segment');
    Route::get('/stream/{episode}/video.mp4', [StreamController::class, 'mp4'])->name('stream.mp4');
});

// ---- Admin -------------------------------------------------------------
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [Admin\DashboardController::class, 'index'])->name('dashboard');

    Route::get('import', [Admin\ImportController::class, 'index'])->name('import.index');
    Route::post('import/sync', [Admin\ImportController::class, 'sync'])->name('import.sync');
    Route::post('import', [Admin\ImportController::class, 'import'])->name('import.store');

    Route::get('storage', [Admin\StorageController::class, 'index'])->name('storage.index');

    Route::resource('contents', Admin\ContentController::class)->except('show');
    Route::post('contents/{content}/episodes', [Admin\EpisodeController::class, 'store'])->name('contents.episodes.store');
    Route::delete('contents/{content}/episodes/{episode}', [Admin\EpisodeController::class, 'destroy'])->name('contents.episodes.destroy');

    Route::get('genres', [Admin\GenreController::class, 'index'])->name('genres.index');
    Route::post('genres', [Admin\GenreController::class, 'store'])->name('genres.store');
    Route::put('genres/{genre}', [Admin\GenreController::class, 'update'])->name('genres.update');
    Route::delete('genres/{genre}', [Admin\GenreController::class, 'destroy'])->name('genres.destroy');

    Route::get('users', [Admin\UserController::class, 'index'])->name('users.index');
    Route::put('users/{user}', [Admin\UserController::class, 'update'])->name('users.update');

    Route::get('analytics', [Admin\AnalyticsController::class, 'index'])->name('analytics');
});
