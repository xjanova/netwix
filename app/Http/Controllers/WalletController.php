<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Models\UsdtOrder;
use App\Services\GoldWallet;
use App\Services\Membership;
use App\Services\UsdtPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Web (session-auth) endpoints for the gold wallet + USDT top-up on /account.
 * Returns JSON for the Alpine widgets; the heavy lifting lives in the shared
 * services, so web and the mobile API behave identically.
 */
class WalletController extends Controller
{
    public function __construct(
        private Membership $m,
        private GoldWallet $gold,
        private UsdtPayment $usdt,
    ) {}

    /** Convert silver → gold. Body: {gold}. */
    public function convert(Request $request): JsonResponse
    {
        $request->validate(['gold' => ['required', 'integer', 'min:1', 'max:1000000']]);
        $res = $this->gold->convert($request->user(), (int) $request->input('gold'));

        return response()->json([
            'success' => $res['ok'],
            'error' => $res['error'] ?? null,
            'gold' => $this->gold->state($request->user()->refresh()),
            'coins' => (int) $request->user()->coins,
        ], $res['ok'] ? 200 : 422);
    }

    /** Buy Pro with gold (instant). */
    public function buyProWithGold(Request $request): JsonResponse
    {
        $res = $this->gold->buyProWithGold($request->user());

        return response()->json([
            'success' => $res['ok'],
            'error' => $res['error'] ?? null,
            'membership' => $this->m->state($request->user()->refresh()),
        ], $res['ok'] ? 200 : 422);
    }

    /** Create a USDT order. Body: {purpose: gold|pro, usdt?}. */
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

        return response()->json(['success' => true, 'order' => $this->usdt->payload($order)]);
    }

    /** Spend gold to unlock a VIP title (from the lock screen). */
    public function unlockVip(Request $request, Content $content): JsonResponse
    {
        $res = $this->gold->unlockVip($request->user(), $content);

        return response()->json([
            'success' => $res['ok'],
            'error' => $res['error'] ?? null,
            'access' => $res['access'] ?? null,
            'gold_coins' => (int) $request->user()->refresh()->gold_coins,
        ], $res['ok'] ? 200 : 422);
    }

    /** Poll an order — live-verifies on the chain, then returns status + balances. */
    public function orderStatus(Request $request, UsdtOrder $order): JsonResponse
    {
        abort_unless($order->user_id === $request->user()->id, 404);
        $order = $this->usdt->verify($order);

        return response()->json([
            'success' => true,
            'order' => $this->usdt->payload($order),
            'membership' => $this->m->state($request->user()->refresh()),
            'gold' => $this->gold->state($request->user()),
        ]);
    }
}
