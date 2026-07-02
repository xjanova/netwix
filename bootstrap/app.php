<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'profile' => \App\Http\Middleware\EnsureProfileSelected::class,
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
        ]);

        // Ingest bridge is token-authenticated, not session/CSRF based.
        $middleware->validateCsrfTokens(except: [
            'api/ingest/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
