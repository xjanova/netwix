<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\UsdtOrder;
use App\Services\Membership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin: gold pricing, silver→gold conversion rules, the VIP zone default price,
 * and the real USDT (BSC) payment settings (wallet address + BscScan key). Gold /
 * VIP / USDT config is a slice of the same membership JSON, saved via mergeConfig
 * so it never clobbers the promo-builder page. Secrets follow the SettingController
 * pattern (masked; blank submit keeps the stored key; a "ล้างค่า" tick clears it).
 */
class PaymentController extends Controller
{
    public function __construct(private Membership $m) {}

    public function index(): View
    {
        return view('admin.payments.index', [
            'cfg' => $this->m->config(),
            'wallet' => (string) Setting::get('usdt_wallet_address', ''),
            'apiBase' => (string) Setting::get('bscscan_api_base', 'https://api.bscscan.com/api'),
            'hasApiKey' => filled(Setting::get('bscscan_api_key')),
            'orders' => UsdtOrder::with('user:id,name')->latest()->limit(30)->get(),
            'stats' => [
                'paid' => UsdtOrder::where('status', 'paid')->count(),
                'pending' => UsdtOrder::where('status', 'pending')->count(),
                'usdt_in' => (float) UsdtOrder::where('status', 'paid')->sum('base_usdt'),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'per_usdt' => ['required', 'integer', 'between:1,1000000'],
            'min_usdt' => ['required', 'numeric', 'between:0.000001,100000'],
            'convert_rate' => ['required', 'integer', 'between:1,1000000'],
            'convert_fee_pct' => ['required', 'numeric', 'between:0,100'],
            'convert_daily_cap' => ['required', 'integer', 'between:0,1000000'],
            'vip_unlock_cost_gold' => ['required', 'integer', 'between:0,1000000'],
            'usdt_pro_price_usdt' => ['required', 'numeric', 'between:0,100000'],
            'usdt_pro_days' => ['required', 'integer', 'between:0,3650'],
            'buy_pro_gold' => ['required', 'integer', 'between:0,1000000'],
            'contract' => ['required', 'regex:/^0x[0-9a-fA-F]{40}$/'],
            'decimals' => ['required', 'integer', 'between:0,36'],
            'min_confirmations' => ['required', 'integer', 'between:1,200'],
            'order_ttl_minutes' => ['required', 'integer', 'between:5,1440'],
            'usdt_wallet_address' => ['nullable', 'regex:/^0x[0-9a-fA-F]{40}$/'],
            'bscscan_api_base' => ['nullable', 'url:http,https', 'max:255'],
            'bscscan_api_key' => ['nullable', 'string', 'max:255'],
        ], [
            'contract.regex' => 'ที่อยู่สัญญา USDT ต้องเป็น 0x ตามด้วยเลขฐาน 16 จำนวน 40 ตัว',
            'usdt_wallet_address.regex' => 'ที่อยู่กระเป๋าต้องเป็น 0x ตามด้วยเลขฐาน 16 จำนวน 40 ตัว',
        ]);

        $this->m->mergeConfig([
            'gold' => [
                'per_usdt' => (int) $data['per_usdt'],
                'min_usdt' => (float) $data['min_usdt'],
                'convert_enabled' => $request->boolean('convert_enabled'),
                'convert_rate' => (int) $data['convert_rate'],
                'convert_fee_pct' => (float) $data['convert_fee_pct'],
                'convert_daily_cap' => (int) $data['convert_daily_cap'],
            ],
            'vip' => [
                'unlock_cost_gold' => (int) $data['vip_unlock_cost_gold'],
                'pro_unlocks' => $request->boolean('vip_pro_unlocks'),
            ],
            'usdt' => [
                'enabled' => $request->boolean('usdt_enabled'),
                'contract' => $data['contract'],
                'decimals' => (int) $data['decimals'],
                'min_confirmations' => (int) $data['min_confirmations'],
                'order_ttl_minutes' => (int) $data['order_ttl_minutes'],
                'pro_price_usdt' => (float) $data['usdt_pro_price_usdt'],
                'pro_days' => (int) $data['usdt_pro_days'],
                'buy_pro_gold' => (int) $data['buy_pro_gold'],
            ],
        ]);

        // Wallet address + API base are plain + pre-filled → write as-is (null clears).
        Setting::write('usdt_wallet_address', $data['usdt_wallet_address'] ?? null);
        Setting::write('bscscan_api_base', $data['bscscan_api_base'] ?? null);

        // BscScan key is a masked secret: blank submit keeps it; tick "ล้างค่า" to wipe.
        if ($request->boolean('bscscan_api_key_clear')) {
            Setting::write('bscscan_api_key', null);
        } elseif (filled($data['bscscan_api_key'] ?? null)) {
            Setting::write('bscscan_api_key', $data['bscscan_api_key']);
        }

        return back()->with('status', 'บันทึกการตั้งค่าการชำระเงิน / เหรียญทองแล้ว');
    }
}
