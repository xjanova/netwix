<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class EnsureProfileSelected
{
    public function handle(Request $request, Closure $next): Response
    {
        $profileId = $request->session()->get('profile_id');

        $profile = $profileId
            ? $request->user()->profiles()->find($profileId)
            : null;

        if (! $profile) {
            $request->session()->forget('profile_id');

            return redirect()->route('profiles.index');
        }

        // Make the active profile available everywhere.
        $request->attributes->set('profile', $profile);
        View::share('currentProfile', $profile);
        View::share('otherProfiles', $request->user()->profiles()->whereKeyNot($profile->id)->get());

        return $next($request);
    }
}
