<?php

namespace App\Http\Middleware;

use App\Support\ActiveProfile;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a suspended member's web session on their very next request, whatever the page.
 *
 * The same check used to live only in EnsureProfileSelected, so it covered the streaming pages and
 * nothing else: a suspended account kept /admin (EnsureAdmin looked at the role alone), the profile
 * picker, and /mauth/issue — which hands the app a brand-new token. Sessions live 30 days and the
 * remember-me cookie renews itself, so "ระงับ" in the admin panel did not actually end anything.
 */
class EndSuspendedSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || $user->is_active) {
            return $next($request);
        }

        // logout() also cycles the remember token, so every other device's cookie dies with this one.
        Auth::logout();
        ActiveProfile::forget($request);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => 'บัญชีนี้ถูกระงับการใช้งาน'], 401);
        }

        return redirect()->route('login')->withErrors(['email' => 'บัญชีนี้ถูกระงับการใช้งาน']);
    }
}
