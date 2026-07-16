<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\AppBanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Promo banners for the app home screen (GET /api/app/banners). Scheduling and
 * hide_for_pro are resolved SERVER-side (auth.apptoken.optional binds the
 * viewer), mirroring how AdController serves pre-rolls.
 */
class BannerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $banners = AppBanner::forViewer($request->user())
                ->map(fn (AppBanner $b) => $b->toAppPayload())
                ->values()
                ->all();
        } catch (Throwable $e) {
            // Table missing / any error → no banners. A banner must never break home.
            $banners = [];
        }

        return response()->json(['success' => true, 'data' => ['banners' => $banners]]);
    }
}
