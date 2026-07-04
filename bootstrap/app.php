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
        $middleware->alias([
            'profile' => \App\Http\Middleware\EnsureProfileSelected::class,
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
            'auth.apptoken' => \App\Http\Middleware\AuthenticateAppToken::class,
        ]);

        // Ingest bridge is token-authenticated, not session/CSRF based.
        $middleware->validateCsrfTokens(except: [
            'api/ingest/*',
        ]);

        // Log human page views for the admin SEO/traffic dashboard (best-effort, self-pruning).
        $middleware->web(append: [
            \App\Http\Middleware\TrackPageView::class,
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
        //
    })->create();
