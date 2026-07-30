<?php

namespace App\Http\Middleware;

use Closure;
use App\Support\ActiveProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class EnsureProfileSelected
{
    public function handle(Request $request, Closure $next): Response
    {
        // A member suspended mid-session is logged out on their next authenticated request.
        if (! $request->user()->is_active) {
            \Illuminate\Support\Facades\Auth::logout();
            ActiveProfile::forget($request);
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'บัญชีนี้ถูกระงับการใช้งาน']);
        }

        // Session, then this device's remembered pick — so a member returning on a
        // rolled-over session goes straight to browse, not back to the picker.
        $profile = ActiveProfile::resolve($request);

        if (! $profile) {
            return redirect()->route('profiles.index');
        }

        // Make the active profile available everywhere.
        $request->attributes->set('profile', $profile);
        View::share('currentProfile', $profile);
        View::share('otherProfiles', $request->user()->profiles()->whereKeyNot($profile->id)->get());

        return $next($request);
    }
}
