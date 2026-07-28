<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\Ads;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "โฆษณา Google (AdSense / AdMob)" — the network-served banner ads, as opposed to the owner's own
 * pre-roll creatives on [AdController]. Everything is a [Setting], so there's no schema to migrate
 * and switching networks is a form submit.
 *
 * Every id is validated server-side to its known shape before it's stored: these values are printed
 * into a <script> src and into data-* attributes, and a publisher id is the one field an attacker
 * would want to swap. The per-slot custom HTML is the deliberate exception — see the trust note on
 * [Ads::customHtml].
 */
class GoogleAdsController extends Controller
{
    public function index(): View
    {
        return view('admin.google-ads.index', [
            'slots' => Ads::SLOTS,
            'settings' => $this->current(),
            'clientOk' => Ads::clientId() !== null,
            'adsTxt' => (string) Setting::get('ads_txt', ''),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ads_enabled' => ['sometimes', 'boolean'],
            'adsense_client_id' => ['nullable', 'string', 'max:40'],
            'admob_enabled' => ['sometimes', 'boolean'],
            'admob_android_app_id' => ['nullable', 'string', 'max:60'],
            'admob_ios_app_id' => ['nullable', 'string', 'max:60'],
            'admob_unit_banner' => ['nullable', 'string', 'max:60'],
            'admob_unit_interstitial' => ['nullable', 'string', 'max:60'],
            'admob_unit_native' => ['nullable', 'string', 'max:60'],
            'admob_unit_rewarded' => ['nullable', 'string', 'max:60'],
            'admob_interstitial_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'ads_txt' => ['nullable', 'string', 'max:20000'],
        ]);

        // A pasted "pub-…" is the commonest form of the id; normalise rather than reject it.
        $client = trim((string) ($data['adsense_client_id'] ?? ''));
        if ($client !== '' && ! str_starts_with($client, 'ca-')) {
            $client = 'ca-'.ltrim($client, '-');
        }
        if ($client !== '' && ! preg_match('~^ca-pub-\d{10,20}$~', $client)) {
            return back()->withInput()->withErrors([
                'adsense_client_id' => 'รหัสผู้เผยแพร่ AdSense ต้องอยู่ในรูปแบบ ca-pub-1234567890123456',
            ]);
        }

        foreach (['admob_android_app_id', 'admob_ios_app_id', 'admob_unit_banner',
            'admob_unit_interstitial', 'admob_unit_native', 'admob_unit_rewarded'] as $key) {
            $v = trim((string) ($data[$key] ?? ''));
            // # delimiter — "~" is part of an AdMob app id, see [Ads::admobId].
            if ($v !== '' && ! preg_match('#^ca-app-pub-\d{10,20}[~/]\d{6,20}$#', $v)) {
                return back()->withInput()->withErrors([
                    $key => 'รหัส AdMob ต้องอยู่ในรูปแบบ ca-app-pub-XXXXXXXXXXXXXXXX~YYYYYYYYYY (แอป) หรือ .../YYYYYYYYYY (หน่วยโฆษณา)',
                ]);
            }
            Setting::write($key, $v);
        }

        Setting::write('adsense_client_id', $client);
        Setting::write('ads_enabled', $request->boolean('ads_enabled') ? '1' : '0');
        Setting::write('admob_enabled', $request->boolean('admob_enabled') ? '1' : '0');
        Setting::write('admob_interstitial_minutes', (string) ($data['admob_interstitial_minutes'] ?? 8));
        Setting::write('ads_txt', trim((string) ($data['ads_txt'] ?? '')));

        // Per-slot: an AdSense unit id (digits) OR a raw snippet from another network.
        foreach (Ads::SLOTS as $slot) {
            $unit = trim((string) $request->input('adsense_slot_'.$slot, ''));
            if ($unit !== '' && ! preg_match('~^\d{6,20}$~', $unit)) {
                return back()->withInput()->withErrors([
                    'adsense_slot_'.$slot => 'รหัสหน่วยโฆษณา (Ad unit) ต้องเป็นตัวเลขล้วน',
                ]);
            }
            Setting::write('adsense_slot_'.$slot, $unit);
            Setting::write('ads_custom_'.$slot, trim((string) $request->input('ads_custom_'.$slot, '')));
        }

        return back()->with('status', 'บันทึกการตั้งค่าโฆษณาแล้ว — สมาชิก Pro จะไม่เห็นโฆษณาทุกจุด');
    }

    /** @return array<string,string> current values for the form */
    private function current(): array
    {
        $keys = ['adsense_client_id', 'admob_android_app_id', 'admob_ios_app_id', 'admob_unit_banner',
            'admob_unit_interstitial', 'admob_unit_native', 'admob_unit_rewarded', 'admob_interstitial_minutes'];

        foreach (Ads::SLOTS as $slot) {
            $keys[] = 'adsense_slot_'.$slot;
            $keys[] = 'ads_custom_'.$slot;
        }

        $out = [];
        foreach ($keys as $k) {
            $out[$k] = (string) Setting::get($k, '');
        }
        $out['ads_enabled'] = Setting::flag('ads_enabled', false) ? '1' : '0';
        $out['admob_enabled'] = Setting::flag('admob_enabled', false) ? '1' : '0';

        return $out;
    }
}
