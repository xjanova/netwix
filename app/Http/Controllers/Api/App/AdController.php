<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\AdCampaign;
use App\Models\Content;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Pre-roll ads for the mobile app — the same campaigns the web player shows.
 *
 * The app used to fetch ads from `main.thaiprompt.online/api/ads`, a different
 * host whose endpoint was never built, so admin-configured AdCampaigns had never
 * once reached mobile. This serves the real thing off AdCampaign::pickFor(),
 * exactly as WatchController::preroll() does for the web.
 *
 * Targeting, scheduling and hide_for_pro are all resolved SERVER-side: the app
 * must not be trusted to decide whether it's allowed to skip an ad. Reached via
 * auth.apptoken.optional so a guest gets ads and a Pro member's hide_for_pro
 * campaigns are filtered out.
 */
class AdController extends Controller
{
    /** GET /api/app/content/{content}/ad — the one pre-roll for this title + viewer, or null. */
    public function preroll(Request $request, Content $content): JsonResponse
    {
        $content->loadMissing('genres');   // genre targeting reads these

        try {
            $ad = AdCampaign::pickFor($content, $request->user())?->toPlayerPayload();
        } catch (Throwable $e) {
            // Table missing / any error → no ad. An ad must never block playback.
            $ad = null;
        }

        return response()->json(['success' => true, 'data' => ['ad' => $ad]]);
    }
}
