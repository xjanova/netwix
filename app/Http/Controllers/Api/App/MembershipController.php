<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Services\Membership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Membership API for the mobile app. The app is a thin client — it reads the
 * admin-defined rules from `config` and the member's state from `me`, and posts
 * `referral/redeem`. Web stays authoritative (see App\Services\Membership).
 */
class MembershipController extends Controller
{
    public function __construct(private Membership $m) {}

    /** Public: the current rules (free episodes, coin costs, Pro price, referral rewards). */
    public function config(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->m->config()]);
    }

    /** Auth: this member's Pro / coins / referral state. */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->m->state($request->user())]);
    }

    /** Auth: redeem a friend's referral code → grants the promo to both sides. */
    public function redeem(Request $request): JsonResponse
    {
        $res = $this->m->redeem($request->user(), (string) $request->input('code', ''));

        return response()->json([
            'success' => $res['ok'],
            'error' => $res['error'] ?? null,
            'data' => $res['ok'] ? $this->m->state($request->user()->refresh()) : null,
        ], $res['ok'] ? 200 : 422);
    }
}
