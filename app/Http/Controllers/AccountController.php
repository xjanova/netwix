<?php

namespace App\Http\Controllers;

use App\Services\Membership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Member account page — Pro status, coins, the user's own referral code + share
 * links, and the redeem-a-friend's-code form. Mirrors what the mobile app shows
 * via /api/app/membership.
 */
class AccountController extends Controller
{
    public function __construct(private Membership $m) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        return view('frontend.account', [
            'state' => $this->m->state($user),
            'cfg' => $this->m->config(),
        ]);
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
