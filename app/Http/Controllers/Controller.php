<?php

namespace App\Http\Controllers;

use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

abstract class Controller
{
    /**
     * Resolve the signed-in member's active profile for a route that lives OUTSIDE the ['auth','profile']
     * group — i.e. the public catalog/genre pages. Mirrors EnsureProfileSelected (but never redirects):
     * on success it sets the `profile` request attribute and shares currentProfile/otherProfiles so the
     * member nav renders. Returns null for guests, and for members who haven't picked a profile yet —
     * callers then fall back to the public (crawlable) view, which is exactly what Googlebot (always
     * session-less) sees, so the same URL stays canonical for SEO.
     */
    protected function activeMemberProfile(Request $request): ?Profile
    {
        if (! $request->user()) {
            return null;
        }

        $profileId = $request->session()->get('profile_id');
        $profile = $profileId ? $request->user()->profiles()->find($profileId) : null;
        if (! $profile) {
            return null;
        }

        $request->attributes->set('profile', $profile);
        View::share('currentProfile', $profile);
        View::share('otherProfiles', $request->user()->profiles()->whereKeyNot($profile->id)->get());

        return $profile;
    }
}
