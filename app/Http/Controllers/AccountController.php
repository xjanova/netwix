<?php

namespace App\Http\Controllers;

use App\Services\GoldWallet;
use App\Services\Membership;
use App\Services\UsdtPayment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Member account page — Pro status, coins (silver + gold), the referral code +
 * share links, the redeem form, and the real USDT top-up / silver→gold convert
 * widgets. Mirrors what the mobile app shows via /api/app/membership + /wallet.
 */
class AccountController extends Controller
{
    public function __construct(
        private Membership $m,
        private GoldWallet $gold,
        private UsdtPayment $usdt,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        return view('frontend.account', [
            'state' => $this->m->state($user),
            'cfg' => $this->m->config(),
            'gold' => $this->gold->state($user),
            'usdtEnabled' => $this->usdt->enabled(),
            'usdtWallet' => $this->usdt->walletAddress(),
        ]);
    }

    /** Member self-service: edit own contact details + manage viewing profiles. */
    public function settings(Request $request): View
    {
        return view('frontend.account-settings', [
            'user' => $request->user(),
            'profiles' => $request->user()->profiles()->orderBy('id')->get(),
        ]);
    }

    /** Save the member's own contact info. Email (the login) is intentionally NOT editable here. */
    public function updateContact(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        $request->user()->update([
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
        ]);

        return back()->with('status', 'บันทึกข้อมูลแล้ว');
    }

    public function redeem(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:16']]);

        $res = $this->m->redeem($request->user(), (string) $request->input('code'));

        return back()->with(
            $res['ok'] ? 'status' : 'error',
            $res['ok'] ? 'ใช้โค้ดแนะนำสำเร็จ! รับสิทธิ์เรียบร้อยแล้ว 🎉' : ($res['error'] ?? 'ใช้โค้ดไม่สำเร็จ')
        );
    }
}
