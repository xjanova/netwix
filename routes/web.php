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
use App\Http\Controllers\PublicCatalogController;
use App\Http\Controllers\PublicGenreController;
use App\Http\Controllers\PublicTitleController;
use App\Http\Controllers\Auth\SocialController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\StreamController;
use App\Http\Controllers\TitleController;
use App\Http\Controllers\WatchController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

// ---- SEO: sitemap index + typed children -------------------------------
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('/sitemap-pages.xml', [SitemapController::class, 'pages'])->name('sitemap.pages');
Route::get('/sitemap-titles.xml', [SitemapController::class, 'titles'])->name('sitemap.titles');
Route::get('/sitemap-genres.xml', [SitemapController::class, 'genres'])->name('sitemap.genres');

// ---- Public catalog (crawlable; playback stays login-gated) ------------
// The title + genre pages are the SEO surface: guests + Googlebot can read synopsis, poster, episode
// list and rich JSON-LD, but pressing "เล่น" bounces to /login. Adult titles 404 here (see controllers).
Route::middleware('throttle:120,1')->group(function () {
    Route::get('/title/{content}', [PublicTitleController::class, 'show'])->name('title.show');
    Route::get('/genre/{genre}', [PublicGenreController::class, 'show'])->name('browse.genre');

    // Category hubs — populated & crawlable (rank the head terms "ดูหนัง/ซีรีส์/อนิเมะออนไลน์").
    // NB: these list EVERY public title of the type (incl. anime-tagged), unlike the member type
    // pages — an empty hub would be a thin page that hurts SEO. See PublicCatalogController.
    Route::get('/movies', [PublicCatalogController::class, 'movies'])->name('browse.movies');
    Route::get('/series', [PublicCatalogController::class, 'series'])->name('browse.series');
    Route::get('/anime', [PublicCatalogController::class, 'anime'])->name('browse.anime');
    Route::get('/vertical', [PublicCatalogController::class, 'vertical'])->name('browse.vertical');

    // Public search — results page is noindex,follow; suggest feeds the nav type-ahead. Both sit
    // behind the once-per-session Turnstile human gate (no-op until keys are configured).
    Route::get('/search', [SearchController::class, 'index'])->middleware('turnstile.search')->name('search');
    Route::get('/api/search', [SearchController::class, 'suggest'])->middleware('turnstile.search')->name('search.suggest');
});

// The gate page above POSTs its solved token here to flag the session as human.
Route::post('/turnstile/verify', [\App\Http\Controllers\TurnstileController::class, 'verify'])
    ->middleware('throttle:20,1')->name('turnstile.verify');

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
    Route::post('/profiles/{profile}', [ProfileSelectionController::class, 'update'])->name('profiles.update');
    Route::post('/profiles/{profile}/avatar', [ProfileSelectionController::class, 'avatar'])->middleware('throttle:20,1')->name('profiles.avatar');
    Route::delete('/profiles/{profile}', [ProfileSelectionController::class, 'destroy'])->name('profiles.destroy');
});

// ---- The streaming app (needs an active profile) -----------------------
Route::middleware(['auth', 'profile'])->group(function () {
    Route::get('/browse', [BrowseController::class, 'home'])->name('browse');
    Route::get('/browse/feed', [BrowseController::class, 'feed'])->middleware('throttle:120,1')->name('browse.feed');
    Route::get('/browse/row', [BrowseController::class, 'row'])->middleware('throttle:180,1')->name('browse.row');
    // Category hubs (/series /movies /anime /vertical) + /search are now PUBLIC (see the public
    // catalog group above). Members still get the personalised home at /browse and their my-list here.
    Route::get('/my-list', [BrowseController::class, 'myList'])->name('browse.mylist');

    Route::get('/title/{content}/modal', [TitleController::class, 'modal'])->name('title.modal');

    Route::post('/api/content/{content}/list', [InteractionController::class, 'toggleMyList'])->name('content.list');
    Route::post('/api/content/{content}/like', [InteractionController::class, 'toggleLike'])->name('content.like');
    Route::post('/api/content/{content}/vip/unlock', [\App\Http\Controllers\WalletController::class, 'unlockVip'])
        ->middleware('throttle:30,1')->name('content.vip.unlock');
    Route::post('/api/content/{content}/progress', [InteractionController::class, 'progress'])->name('content.progress');
    Route::post('/api/content/{content}/comment', [InteractionController::class, 'comment'])->middleware(['throttle:30,1', 'turnstile'])->name('content.comment');
    Route::post('/api/content/{content}/rate', [InteractionController::class, 'rate'])->name('content.rate');

    Route::post('/api/episode/{episode}/thumb', [EpisodeSourceController::class, 'captureThumb'])
        ->middleware('throttle:60,1')->name('episode.thumb');
    // Server-side cover fallback for no-CORS sources (anifume) the browser can't frame-grab.
    Route::post('/api/episode/{episode}/gencover', [EpisodeSourceController::class, 'genCover'])
        ->middleware('throttle:60,1')->name('episode.gencover');

    // Member account: Pro status, coins, referral code + share, redeem a code.
    Route::get('/account', [\App\Http\Controllers\AccountController::class, 'index'])->name('account');
    Route::post('/account/redeem', [\App\Http\Controllers\AccountController::class, 'redeem'])
        ->middleware('throttle:10,1')->name('account.redeem');
    // Member self-service: own contact info + profile management.
    Route::get('/account/settings', [\App\Http\Controllers\AccountController::class, 'settings'])->name('account.settings');
    Route::post('/account/settings', [\App\Http\Controllers\AccountController::class, 'updateContact'])->name('account.contact');

    // Missions — watch a video → earn coins. beat is throttled (heartbeat ~every 15s).
    Route::get('/missions', [\App\Http\Controllers\MissionController::class, 'index'])->name('missions.index');
    Route::post('/missions/{mission}/start', [\App\Http\Controllers\MissionController::class, 'start'])->middleware('throttle:20,1')->name('missions.start');
    Route::post('/missions/{mission}/beat', [\App\Http\Controllers\MissionController::class, 'beat'])->middleware('throttle:30,1')->name('missions.beat');

    // Gold wallet + real USDT (BSC) top-up on the account page.
    Route::post('/account/gold/convert', [\App\Http\Controllers\WalletController::class, 'convert'])
        ->middleware('throttle:30,1')->name('account.gold.convert');
    Route::post('/account/pro/buy-gold', [\App\Http\Controllers\WalletController::class, 'buyProWithGold'])
        ->middleware('throttle:30,1')->name('account.pro.buy-gold');
    Route::post('/account/usdt/order', [\App\Http\Controllers\WalletController::class, 'createOrder'])
        ->middleware('throttle:20,1')->name('account.usdt.order');
    Route::get('/account/usdt/order/{order}', [\App\Http\Controllers\WalletController::class, 'orderStatus'])
        ->middleware('throttle:120,1')->name('account.usdt.status');
});

// ---- Watch — guests welcome (campaign: ดูฟรีก่อน สมัครรับของฟรีทีหลัง) ----
// The player page is open to guests now: they can watch general content with no
// account/profile, and the layout shows the register-CTA banner derived from the
// live campaign config (Campaigns::active). A signed-in member still gets the full
// profile behaviour (suspension kick / profile pick) via profile.optional. Adult
// (18+/20+) and VIP titles stay fail-closed — WatchController bounces guests to
// /login. The stream itself was already public (proxy below + /api/app/…/source);
// member-only writes (progress/rate/comment/thumbs) stay in the auth group above
// and the views pass null / hide those for guests.
Route::middleware('profile.optional')->group(function () {
    Route::get('/watch/{content}/{episode?}', [WatchController::class, 'show'])->name('watch');
    Route::get('/api/episode/{episode}/source', [EpisodeSourceController::class, 'resolve'])
        ->middleware('throttle:60,1')->name('episode.source');
});

// ---- Public streaming proxy (guests can watch) -------------------------
// Deliberately NOT behind auth: the mobile app and guest web viewers stream
// without a session (members still get history/resume/favorites via the
// authenticated interaction routes above). This is safe as an open endpoint —
// segment URLs are HMAC-signed so it can't be abused as an SSRF proxy, and it
// only exposes the same imported streams the resolver already hands out.
// Cookieless so Cloudflare can EDGE-CACHE these (a Set-Cookie makes CF skip caching entirely). They
// hold no session state: the manifest is token-gated (minted by the authenticated resolver, which is
// where the Pro/adult gate lives) and each segment URL is HMAC-signed with an expiry. Offloading
// segments to the CF edge is the single biggest scale win — origin PHP stops proxying every segment.
Route::withoutMiddleware([
    \Illuminate\Session\Middleware\StartSession::class,
    \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
])->group(function () {
    Route::get('/stream/{episode}/index.m3u8', [StreamController::class, 'manifest'])
        ->middleware('throttle:60,1')->name('stream.manifest');
    Route::get('/stream/{episode}/segment', [StreamController::class, 'segment'])->name('stream.segment');
});
Route::get('/stream/{episode}/video.mp4', [StreamController::class, 'mp4'])->name('stream.mp4');

// Browser player health ping (ok=played / !ok=couldn't play) → auto-suspend dead titles.
Route::post('/api/playback/{content}/report', [\App\Http\Controllers\PlaybackController::class, 'report'])
    ->middleware('throttle:60,1')->name('playback.report');

// On-demand cover heal: a card whose poster failed to load pings this → re-fetch + locally store the
// cover on the spot (guest-callable; deduped by a per-title lock). Returns the new URL for a live swap.
Route::post('/api/content/{content}/heal-cover', [\App\Http\Controllers\PosterHealController::class, 'heal'])
    ->middleware('throttle:60,1')->name('content.heal-cover');

// ---- Admin -------------------------------------------------------------
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [Admin\DashboardController::class, 'index'])->name('dashboard');

    // Mobile-app diagnostics viewer (app_debug_logs).
    Route::get('debug', [Admin\DebugLogController::class, 'index'])->name('debug.index');
    Route::delete('debug', [Admin\DebugLogController::class, 'clear'])->name('debug.clear');

    // Batch episode-cover generation (ffmpeg → WebP), with a live progress bar.
    // fpm can't run ffmpeg, so `begin` dispatches a SeedEpisodeThumbs job that
    // enqueues GenerateEpisodeThumb jobs onto the `thumbs` queue (drained by the
    // scheduled CLI worker). The run is server-side + resumable: a reopened page
    // re-attaches via `active`, and `redo-failed` re-runs only the failures.
    Route::get('thumbs', [Admin\ThumbController::class, 'index'])->name('thumbs.index');
    Route::get('thumbs/search', [Admin\ThumbController::class, 'search'])->name('thumbs.search');
    Route::get('thumbs/active', [Admin\ThumbController::class, 'active'])->name('thumbs.active');
    Route::post('thumbs/begin', [Admin\ThumbController::class, 'begin'])->name('thumbs.begin');
    Route::post('thumbs/redo-failed', [Admin\ThumbController::class, 'redoFailed'])->name('thumbs.redo-failed');
    Route::get('thumbs/progress', [Admin\ThumbController::class, 'progress'])->name('thumbs.progress');
    Route::post('thumbs/stop', [Admin\ThumbController::class, 'stop'])->name('thumbs.stop');

    // Marketing clip cutter (ffmpeg → FB-ready mp4), Facebook auto-post pipeline Phase 1.
    // `store` enqueues GenerateMarketingClip jobs onto the `clips` queue (CLI workers);
    // the page polls `list` (per-clip status/preview) + `progress` (live agents).
    Route::get('clips', [Admin\ClipController::class, 'index'])->name('clips.index');
    Route::get('clips/search', [Admin\ClipController::class, 'search'])->name('clips.search');
    Route::get('clips/content/{content}/episodes', [Admin\ClipController::class, 'episodes'])->name('clips.episodes');
    Route::get('clips/list', [Admin\ClipController::class, 'list'])->name('clips.list');
    Route::get('clips/progress', [Admin\ClipController::class, 'progress'])->name('clips.progress');
    Route::post('clips', [Admin\ClipController::class, 'store'])->name('clips.store');
    Route::post('clips/stop', [Admin\ClipController::class, 'stop'])->name('clips.stop');
    Route::put('clips/{clip}', [Admin\ClipController::class, 'update'])->name('clips.update');
    Route::post('clips/{clip}/caption', [Admin\ClipController::class, 'caption'])->name('clips.caption');
    Route::post('clips/{clip}/retry', [Admin\ClipController::class, 'retry'])->name('clips.retry');
    // Manual publish/re-publish of a finished clip (the only way a hand-cut clip reaches the page).
    Route::post('clips/{clip}/repost', [Admin\ClipController::class, 'repost'])->name('clips.repost');
    Route::delete('clips/{clip}', [Admin\ClipController::class, 'destroy'])->name('clips.destroy');

    // Clip marketing CAMPAIGNS (Phase 3) — each campaign auto-picks a title on its schedule,
    // cuts a clip, and posts it to the NetWix Facebook page. CRUD + per-campaign toggle + a
    // global kill-switch + "โพสต์ทันที". The actual work runs on the queue (netwix:clips:publish).
    Route::get('clip-campaigns', [Admin\ClipCampaignController::class, 'index'])->name('clip-campaigns.index');
    Route::get('clip-campaigns/create', [Admin\ClipCampaignController::class, 'create'])->name('clip-campaigns.create');
    Route::post('clip-campaigns', [Admin\ClipCampaignController::class, 'store'])->name('clip-campaigns.store');
    Route::post('clip-campaigns/kill', [Admin\ClipCampaignController::class, 'kill'])->name('clip-campaigns.kill');
    Route::get('clip-campaigns/{clipCampaign}/edit', [Admin\ClipCampaignController::class, 'edit'])->name('clip-campaigns.edit');
    Route::put('clip-campaigns/{clipCampaign}', [Admin\ClipCampaignController::class, 'update'])->name('clip-campaigns.update');
    Route::delete('clip-campaigns/{clipCampaign}', [Admin\ClipCampaignController::class, 'destroy'])->name('clip-campaigns.destroy');
    Route::post('clip-campaigns/{clipCampaign}/toggle', [Admin\ClipCampaignController::class, 'toggle'])->name('clip-campaigns.toggle');
    Route::post('clip-campaigns/{clipCampaign}/run', [Admin\ClipCampaignController::class, 'runNow'])->name('clip-campaigns.run');

    // Facebook page connect for the clip auto-post pipeline (OAuth → page token in settings)
    // Branding card burned onto the end of every marketing clip (App\Support\ClipOutro)
    Route::post('clip-outro', [Admin\ClipOutroController::class, 'update'])->name('clip-outro.update');
    Route::get('clip-outro/preview', [Admin\ClipOutroController::class, 'preview'])->name('clip-outro.preview');

    Route::post('facebook/secret', [Admin\FacebookConnectController::class, 'storeSecret'])->name('facebook.secret');
    Route::get('facebook/connect', [Admin\FacebookConnectController::class, 'redirect'])->name('facebook.connect');
    Route::get('facebook/callback', [Admin\FacebookConnectController::class, 'callback'])->name('facebook.callback');
    Route::get('facebook/pick', [Admin\FacebookConnectController::class, 'pick'])->name('facebook.pick');
    Route::post('facebook/select', [Admin\FacebookConnectController::class, 'select'])->name('facebook.select');
    Route::post('facebook/disconnect', [Admin\FacebookConnectController::class, 'disconnect'])->name('facebook.disconnect');

    // Auto-suspended (un-playable) titles for review — re-publish or delete.
    Route::get('suspended', [Admin\SuspendedController::class, 'index'])->name('suspended.index');
    Route::post('suspended/{content}/republish', [Admin\SuspendedController::class, 'republish'])->name('suspended.republish');

    // Titles running on a backup stream (netwix:find-backups) + the daily-finder on/off toggle.
    Route::get('backups', [Admin\BackupController::class, 'index'])->name('backups.index');
    Route::post('backups/toggle', [Admin\BackupController::class, 'toggle'])->name('backups.toggle');

    // "บังคับอัพเดทลิ้งค์หนัง" — search a title in our catalogue, pick one pool site, force its link on.
    Route::get('force-link', [Admin\ForceLinkController::class, 'index'])->name('force-link.index');
    Route::get('force-link/titles', [Admin\ForceLinkController::class, 'searchTitles'])->name('force-link.titles');
    Route::get('force-link/site', [Admin\ForceLinkController::class, 'searchSite'])->name('force-link.site');
    Route::post('force-link/apply', [Admin\ForceLinkController::class, 'apply'])->name('force-link.apply');
    Route::post('force-link/clear', [Admin\ForceLinkController::class, 'clear'])->name('force-link.clear');

    Route::get('import', [Admin\ImportController::class, 'index'])->name('import.index');
    // Catalogue sync runs as a background job; `sync` queues it, these two drive the live overlay.
    Route::post('import/sync', [Admin\ImportController::class, 'sync'])->name('import.sync');
    Route::get('import/sync/progress', [Admin\ImportController::class, 'syncProgress'])->name('import.sync.progress');
    Route::post('import/sync/stop', [Admin\ImportController::class, 'syncStop'])->name('import.sync.stop');
    Route::post('import/auto-toggle', [Admin\ImportController::class, 'autoToggle'])->name('import.auto-toggle');
    Route::post('import/auto-schedule', [Admin\ImportController::class, 'autoSchedule'])->name('import.auto-schedule');
    Route::post('import/source/{source}/visibility', [Admin\ImportController::class, 'toggleVisibility'])->name('import.source-visibility');
    Route::post('import/refresh', [Admin\ImportController::class, 'refreshEpisodes'])->name('import.refresh');
    Route::post('import/batch', [Admin\ImportController::class, 'batch'])->name('import.batch');
    Route::post('import/auto', [Admin\ImportController::class, 'auto'])->name('import.auto');
    Route::post('import', [Admin\ImportController::class, 'import'])->name('import.store');

    // Import history ("ประวัติการนำเข้าหนัง") — read-only log written by the import entry points.
    Route::get('import-logs', [Admin\ImportLogController::class, 'index'])->name('import-logs.index');

    // Admin QA playback — watch anything for verification, bypassing public gates (unpublished/18+/VIP).
    // episode = imported episode (content management); source = not-yet-imported title (import preview).
    Route::get('preview/episode/{episode}', [Admin\AdminPreviewController::class, 'episode'])->name('preview.episode');
    Route::get('preview/source/{sourceTitle}', [Admin\AdminPreviewController::class, 'source'])->name('preview.source');
    Route::get('preview/manifest', [Admin\AdminPreviewController::class, 'manifest'])->name('preview.manifest');
    Route::get('preview/segment', [Admin\AdminPreviewController::class, 'segment'])->name('preview.segment');
    Route::get('preview/mp4', [Admin\AdminPreviewController::class, 'mp4'])->name('preview.mp4');

    Route::get('storage', [Admin\StorageController::class, 'index'])->name('storage.index');
    Route::post('episodes/{episode}/mirror', [Admin\StorageController::class, 'mirror'])->name('storage.mirror');
    Route::post('episodes/{episode}/thumb', [Admin\StorageController::class, 'setThumb'])->name('storage.set-thumb');
    Route::delete('episodes/{episode}/mirror', [Admin\StorageController::class, 'unmirror'])->name('storage.unmirror');
    Route::post('contents/{content}/mirror-all', [Admin\StorageController::class, 'mirrorContent'])->name('storage.mirror-content');
    Route::post('contents/{content}/poster', [Admin\StorageController::class, 'setPoster'])->name('storage.set-poster');

    Route::post('contents/reset-all-thumbs', [Admin\ContentController::class, 'resetAllThumbs'])->name('contents.reset-all-thumbs');
    Route::resource('contents', Admin\ContentController::class)->except('show');
    Route::post('contents/{content}/reset-thumbs', [Admin\ContentController::class, 'resetThumbs'])->name('contents.reset-thumbs');
    Route::post('contents/{content}/review-ignore', [Admin\ContentController::class, 'toggleReviewIgnore'])->name('contents.review-ignore');
    Route::post('contents/{content}/episodes', [Admin\EpisodeController::class, 'store'])->name('contents.episodes.store');
    Route::delete('contents/{content}/episodes/{episode}', [Admin\EpisodeController::class, 'destroy'])->name('contents.episodes.destroy');
    Route::post('contents/{content}/episodes/{episode}/markers', [Admin\EpisodeController::class, 'setMarkers'])->name('contents.episodes.markers');

    Route::get('genres', [Admin\GenreController::class, 'index'])->name('genres.index');
    Route::post('genres', [Admin\GenreController::class, 'store'])->name('genres.store');
    Route::put('genres/{genre}', [Admin\GenreController::class, 'update'])->name('genres.update');
    Route::delete('genres/{genre}', [Admin\GenreController::class, 'destroy'])->name('genres.destroy');

    // Mobile-app broadcast notifications ("แจ้งเตือนในแอป") — the app inbox reads these.
    Route::get('app-notifications', [Admin\AppNotificationController::class, 'index'])->name('app-notifications.index');
    Route::post('app-notifications', [Admin\AppNotificationController::class, 'store'])->name('app-notifications.store');
    Route::put('app-notifications-fcm', [Admin\AppNotificationController::class, 'saveFcm'])->name('app-notifications.fcm');
    Route::put('app-notifications/{notification}', [Admin\AppNotificationController::class, 'update'])->name('app-notifications.update');
    Route::delete('app-notifications/{notification}', [Admin\AppNotificationController::class, 'destroy'])->name('app-notifications.destroy');

    // Mobile-app home-screen promo banners ("แบนเนอร์ในแอป").
    Route::get('app-banners', [Admin\AppBannerController::class, 'index'])->name('app-banners.index');
    Route::post('app-banners', [Admin\AppBannerController::class, 'store'])->name('app-banners.store');
    Route::put('app-banners/{banner}', [Admin\AppBannerController::class, 'update'])->name('app-banners.update');
    Route::post('app-banners/{banner}/toggle', [Admin\AppBannerController::class, 'toggle'])->name('app-banners.toggle');
    Route::delete('app-banners/{banner}', [Admin\AppBannerController::class, 'destroy'])->name('app-banners.destroy');

    // Device statistics collected by the app's telemetry ping (read-only).
    Route::get('app-stats', [Admin\AppStatsController::class, 'index'])->name('app-stats.index');

    // Legal pages editor (terms + privacy shown on web AND inside the app).
    Route::get('legal', [Admin\LegalController::class, 'index'])->name('legal.index');
    Route::put('legal', [Admin\LegalController::class, 'update'])->name('legal.update');

    Route::get('announcements', [Admin\AnnouncementController::class, 'index'])->name('announcements.index');
    Route::post('announcements', [Admin\AnnouncementController::class, 'store'])->name('announcements.store');
    Route::put('announcements/{announcement}', [Admin\AnnouncementController::class, 'update'])->name('announcements.update');
    Route::delete('announcements/{announcement}', [Admin\AnnouncementController::class, 'destroy'])->name('announcements.destroy');

    // Pre-roll ads ("โฆษณาก่อนเล่น") — shown on the player before the video starts.
    Route::get('ads', [Admin\AdController::class, 'index'])->name('ads.index');
    Route::post('ads', [Admin\AdController::class, 'store'])->name('ads.store');
    Route::put('ads/{ad}', [Admin\AdController::class, 'update'])->name('ads.update');
    Route::post('ads/{ad}/toggle', [Admin\AdController::class, 'toggle'])->name('ads.toggle');
    Route::delete('ads/{ad}', [Admin\AdController::class, 'destroy'])->name('ads.destroy');

    // Missions ("ภารกิจ / รางวัล") — watch-a-video → earn coins.
    Route::get('missions', [Admin\MissionController::class, 'index'])->name('missions.index');
    Route::post('missions', [Admin\MissionController::class, 'store'])->name('missions.store');
    Route::put('missions/{mission}', [Admin\MissionController::class, 'update'])->name('missions.update');
    Route::post('missions/{mission}/toggle', [Admin\MissionController::class, 'toggle'])->name('missions.toggle');
    Route::delete('missions/{mission}', [Admin\MissionController::class, 'destroy'])->name('missions.destroy');

    Route::get('comments', [Admin\CommentController::class, 'index'])->name('comments.index');
    Route::delete('comments/{comment}', [Admin\CommentController::class, 'destroy'])->name('comments.destroy');

    Route::get('users', [Admin\UserController::class, 'index'])->name('users.index');
    Route::get('users/{user}/edit', [Admin\UserController::class, 'edit'])->name('users.edit');
    Route::put('users/{user}', [Admin\UserController::class, 'update'])->name('users.update');
    Route::post('users/{user}/active', [Admin\UserController::class, 'toggleActive'])->name('users.active');
    Route::post('users/{user}/profiles/{profile}/avatar', [Admin\UserController::class, 'avatar'])->name('users.avatar');

    Route::get('settings', [Admin\SettingController::class, 'index'])->name('settings.index');
    Route::put('settings', [Admin\SettingController::class, 'update'])->name('settings.update');

    Route::get('membership', [Admin\MembershipController::class, 'index'])->name('membership.index');
    Route::put('membership', [Admin\MembershipController::class, 'update'])->name('membership.update');

    // Gold pricing, silver→gold conversion, VIP default price, and real USDT (BSC) payment settings.
    Route::get('payments', [Admin\PaymentController::class, 'index'])->name('payments.index');
    Route::put('payments', [Admin\PaymentController::class, 'update'])->name('payments.update');

    // App download counts ("ยอดดาวน์โหลดแอป") — read-only, written by AppDownload::record.
    Route::get('downloads', [Admin\DownloadController::class, 'index'])->name('downloads.index');

    // Facebook comment→invite-DM funnel: kill-switch, invite messages, cooldown + webhook setup.
    Route::get('fb-dm', [Admin\FbDmController::class, 'index'])->name('fb-dm.index');
    Route::put('fb-dm', [Admin\FbDmController::class, 'update'])->name('fb-dm.update');

    Route::get('analytics', [Admin\AnalyticsController::class, 'index'])->name('analytics');

    Route::get('seo', [Admin\SeoController::class, 'index'])->name('seo');
    Route::put('seo', [Admin\SeoController::class, 'update'])->name('seo.update');
});
