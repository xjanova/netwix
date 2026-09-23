<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Refuse anything that did not arrive through Cloudflare. FIRST, before every other rule:
        // the edge WAF, bot protection and rate limiting are the outer layer, and a request that
        // skipped them has skipped everything. Reversible from the DB (`require_cloudflare`).
        $middleware->prepend(\App\Http\Middleware\EnsureBehindCloudflare::class);

        // Credential scanning is judged globally, because a scanner asks for paths we have no route
        // for and route-group middleware never runs on a 404. Identity-dependent rules stay in the
        // groups below, where the session exists.
        $middleware->prepend(\App\Http\Middleware\DetectProbes::class);

        // Fold the www alias onto the APP_URL host before anything else runs — see CanonicalHost.
        // Global (not web-only) so the API and stream surfaces canonicalise too.
        $middleware->prepend(\App\Http\Middleware\CanonicalHost::class);

        // Browser security headers on every response, including errors and the stream proxy.
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // Watch the content endpoints for scraping-shaped traffic. Appended to BOTH groups rather
        // than prepended globally: on the global stack it ran before StartSession and before route
        // middleware, so it could never see a logged-in admin or a validated app token, and its two
        // most important exemptions were dead code. Every route we serve is in one of these groups.
        // Ships in observe-only mode.
        $middleware->appendToGroup('web', \App\Http\Middleware\DetectScraping::class);
        $middleware->appendToGroup('api', \App\Http\Middleware\DetectScraping::class);

        $middleware->alias([
            'profile' => \App\Http\Middleware\EnsureProfileSelected::class,
            'profile.optional' => \App\Http\Middleware\OptionalProfile::class,
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
            'auth.apptoken' => \App\Http\Middleware\AuthenticateAppToken::class,
            'auth.apptoken.optional' => \App\Http\Middleware\OptionalAppToken::class,
            'turnstile' => \App\Http\Middleware\VerifyTurnstile::class,
            'turnstile.search' => \App\Http\Middleware\TurnstileSearchGate::class,
        ]);

        // Ingest bridge is token-authenticated, not session/CSRF based.
        $middleware->validateCsrfTokens(except: [
            'api/ingest/*',
        ]);

        // Log human page views for the admin SEO/traffic dashboard (best-effort, self-pruning).
        // RefreshRememberCookie keeps "จดจำฉันไว้" perpetual — it needs the session
        // started, hence appended to the group rather than prepended.
        $middleware->web(append: [
            // First, so a suspended member's session ends before anything else acts on it.
            \App\Http\Middleware\EndSuspendedSession::class,
            \App\Http\Middleware\RefreshRememberCookie::class,
            \App\Http\Middleware\TrackPageView::class,
            // Dead man's switch for the cron — checked after the response is sent.
            \App\Http\Middleware\WatchScheduler::class,
        ]);

        // During a deploy (`artisan down`), keep the mobile app alive: streaming, API and the
        // auth bridge bypass maintenance so active video playback (ExoPlayer) never gets a 503.
        // Only web HTML page-loads see the brief "be right back" page.
        $middleware->preventRequestsDuringMaintenance(except: [
            'stream/*',
            'api/*',
            'mauth/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A failed validation flashes the submitted form into the session so it can be refilled —
        // including a pasted API token, which then sits in plaintext in the session store. Every
        // secret setting's form field is named after its key, so none of them is ever flashed.
        $exceptions->dontFlash(\App\Models\Setting::SECRET_KEYS);

        // Tell the owner when something throws (a 500, a dying command, a failing job) — throttled
        // hard and sent after the response. Returns nothing, so normal logging still happens.
        $exceptions->report(function (\Throwable $e): void {
            \App\Support\Alerts\ErrorAlert::report($e);
        });
    })->create();
