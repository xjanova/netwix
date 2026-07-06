<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\UsdtOrder;
use App\Services\GoldWallet;
use App\Services\Membership;
use App\Services\UsdtPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Gold wallet + USDT payments for the mobile app (auth.apptoken). Web stays
 * authoritative — this is a thin layer over App\Services\{GoldWallet,UsdtPayment}.
 * A member (bearer token) is required for every route, so guests can never buy.
 */
class WalletController extends Controller
{
    public function __construct(
        private Membership $m,
        private GoldWallet $gold,
        private UsdtPayment $usdt,
    ) {}

    /** Balances (silver + gold), gold/convert rules, and the USDT top-up config. */
    public function state(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->snapshot($request->user())]);
    }

    /** Convert silver → gold at the admin rate (capped per day). Body: {gold}. */
    public function convert(Request $request): JsonResponse
    {
        $request->validate(['gold' => ['required', 'integer', 'min:1', 'max:1000000']]);
        $res = $this->gold->convert($request->user(), (int) $request->input('gold'));

        return response()->json([
            'success' => $res['ok'],
            'error' => $res['error'] ?? null,
            'data' => $this->snapshot($request->user()->refresh()),
        ], $res['ok'] ? 200 : 422);
    }

    /** Buy Pro with gold (instant, no chain). */
    public function buyProWithGold(Request $request): JsonResponse
    {
        $res = $this->gold->buyProWithGold($request->user());

        return response()->json([
            'success' => $res['ok'],
            'error' => $res['error'] ?? null,
            'data' => $this->snapshot($request->user()->refresh()),
        ], $res['ok'] ? 200 : 422);
    }

    /** Create a USDT order. Body: {purpose: gold|pro, usdt?} (usdt required for gold). */
    public function createOrder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'purpose' => ['required', 'in:gold,pro'],
            'usdt' => ['required_if:purpose,gold', 'nullable', 'numeric', 'min:0.000001', 'max:100000'],
        ]);

        try {
            $order = $data['purpose'] === 'pro'
                ? $this->usdt->createProOrder($request->user())
                : $this->usdt->createGoldOrder($request->user(), (float) $data['usdt']);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $this->usdt->payload($order)]);
    }

    /** Poll an order — live-verifies against the chain, then returns its status. */
    public function orderStatus(Request $request, UsdtOrder $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);
        $order = $this->usdt->verify($order);

        return response()->json([
            'success' => true,
            'data' => $this->usdt->payload($order),
            'membership' => $this->m->state($request->user()->refresh()),
        ]);
    }

    /** VIP access for a title: open | pro | unlocked | locked (+ gold price). */
    public function vipAccess(Request $request, Content $content): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'content_id' => $content->id,
                'is_vip' => (bool) $content->is_vip,
                'access' => $this->gold->vipAccess($request->user(), $content),
                'cost_gold' => $this->gold->vipCost($content),
            ],
        ]);
    }

    /** Spend gold to unlock a VIP title. */
    public function unlockVip(Request $request, Content $content): JsonResponse
    {
        $res = $this->gold->unlockVip($request->user(), $content);

        return response()->json([
            'success' => $res['ok'],
            'error' => $res['error'] ?? null,
            'data' => [
                'access' => $res['access'] ?? null,
                'membership' => $this->snapshot($request->user()->refresh()),
            ],
        ], $res['ok'] ? 200 : 422);
    }

    /** A user may only read their own order. */
    private function authorizeOrder(Request $request, UsdtOrder $order): void
    {
        abort_unless($order->user_id === $request->user()->id, 404);
    }

    /** Everything the wallet screen renders in one payload. */
    private function snapshot($user): array
    {
        $cfg = $this->m->config();

        return [
            'membership' => $this->m->state($user),
            'gold' => $this->gold->state($user),
            'usdt' => [
                'enabled' => $this->usdt->enabled(),
                'wallet' => $this->usdt->walletAddress(),
                'network' => 'BEP20 (BSC)',
                'contract' => $this->usdt->contract(),
                'min_usdt' => (float) ($cfg['gold']['min_usdt'] ?? 1),
                'per_usdt' => (float) ($cfg['gold']['per_usdt'] ?? 0),
                'pro_price_usdt' => (float) ($cfg['usdt']['pro_price_usdt'] ?? 0),
                'pro_days' => (int) ($cfg['usdt']['pro_days'] ?? 0),
                'buy_pro_gold' => (int) ($cfg['usdt']['buy_pro_gold'] ?? 0),
            ],
            'vip' => [
                'unlock_cost_gold' => (int) ($cfg['vip']['unlock_cost_gold'] ?? 0),
                'pro_unlocks' => (bool) ($cfg['vip']['pro_unlocks'] ?? true),
            ],
        ];
    }
}
