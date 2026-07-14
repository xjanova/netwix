<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Membership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The "promo builder" — admins tune every membership rule here (referral
 * rewards, Pro duration, free episodes, coin costs, earn rates). Saved as one
 * JSON blob via Membership::saveConfig; the web and the mobile app both read it.
 */
class MembershipController extends Controller
{
    public function __construct(private Membership $m) {}

    public function index(): View
    {
        return view('admin.membership.index', ['cfg' => $this->m->config()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'referee_pro_days' => ['required', 'integer', 'between:0,3650'],
            'referee_coins' => ['required', 'integer', 'between:0,1000000'],
            'referrer_pro_days' => ['required', 'integer', 'between:0,3650'],
            'referrer_coins' => ['required', 'integer', 'between:0,1000000'],
            'max_referrals' => ['required', 'integer', 'between:0,1000000'],
            'free_episodes' => ['required', 'integer', 'between:0,10000'],
            'unlock_cost_coins' => ['required', 'integer', 'between:0,1000000'],
            'signup_bonus_coins' => ['required', 'integer', 'between:0,1000000'],
            'pro_price_thb' => ['required', 'integer', 'between:0,1000000'],
            'pro_free_days' => ['required', 'integer', 'between:0,3650'],
            'daily_checkin_coins' => ['required', 'integer', 'between:0,1000000'],
            'watch_reward_coins' => ['required', 'integer', 'between:0,1000000'],
            'watch_reward_daily_cap' => ['required', 'integer', 'between:0,10000'],
        ]);

        // mergeConfig (not saveConfig): keep the gold / vip / usdt slices that the
        // payment admin page owns — this form only writes the promo/coin rules.
        $this->m->mergeConfig([
            'referral' => [
                'enabled' => $request->boolean('referral_enabled'),
                'referee_pro_days' => (int) $data['referee_pro_days'],
                'referee_coins' => (int) $data['referee_coins'],
                'referrer_pro_days' => (int) $data['referrer_pro_days'],
                'referrer_coins' => (int) $data['referrer_coins'],
                'max_referrals' => (int) $data['max_referrals'],
            ],
            'free_episodes' => (int) $data['free_episodes'],
            'unlock_cost_coins' => (int) $data['unlock_cost_coins'],
            'signup_bonus_coins' => (int) $data['signup_bonus_coins'],
            'pro' => [
                'price_thb' => (int) $data['pro_price_thb'],
                'free_days' => (int) $data['pro_free_days'],
                'removes_ads' => $request->boolean('pro_removes_ads'),
                'unlocks_all' => $request->boolean('pro_unlocks_all'),
            ],
            'earn' => [
                'daily_checkin_coins' => (int) $data['daily_checkin_coins'],
                'watch_reward_coins' => (int) $data['watch_reward_coins'],
                'watch_reward_daily_cap' => (int) $data['watch_reward_daily_cap'],
            ],
        ]);

        return back()->with('status', 'บันทึกกติกาสมาชิก / โปรแล้ว');
    }
}
