<?php

namespace App\Http\Middleware;

use App\Support\Turnstile;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate applied to public write endpoints (login, register, comment). No-op unless Turnstile is
 * configured, so it's safe to leave on every route. On a failed challenge it returns a clean
 * error — 422 JSON for AJAX (comments), or a redirect back with a message for the auth forms.
 */
class VerifyTurnstile
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Turnstile::enabled()) {
            return $next($request);
        }

        if (Turnstile::passes($request->input('cf-turnstile-response'), $request->ip())) {
            return $next($request);
        }

        $msg = 'ยืนยันว่าไม่ใช่บอทไม่สำเร็จ กรุณาลองใหม่อีกครั้ง';

        if ($request->expectsJson()) {
            return response()->json(['message' => $msg, 'errors' => ['turnstile' => [$msg]]], 422);
        }

        return back()
            ->withInput($request->except(['password', 'password_confirmation', 'cf-turnstile-response']))
            ->withErrors(['email' => $msg]);
    }
}
