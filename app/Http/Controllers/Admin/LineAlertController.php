<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\LineNotifier;
use App\Support\SourceHealth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "แจ้งเตือนปัญหาเข้า LINE" — where the owner points alerts at their LINE OA and proves it works.
 *
 * The channel token is a SECRET setting (encrypted at rest) and is therefore WRITE-ONLY here: the
 * form shows whether one is set, never the value. A token that can be read back off an admin page is
 * a token that leaks through a screenshot, a shoulder, or a stale browser cache.
 */
class LineAlertController extends Controller
{
    public function index(): View
    {
        return view('admin.line-alerts.index', [
            'enabled' => Setting::flag('line_alerts_enabled', false),
            'hasToken' => trim((string) Setting::get('line_oa_token', '')) !== '',
            'to' => (string) Setting::get('line_oa_to', ''),
            'ready' => LineNotifier::enabled(),
            'sourcesDown' => array_keys(SourceHealth::down()),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'line_alerts_enabled' => ['sometimes', 'boolean'],
            // Blank = keep the stored token. That's what makes "write-only" workable: the admin can
            // change the recipient without having to paste the token again every time.
            'line_oa_token' => ['nullable', 'string', 'max:300'],
            'line_oa_to' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9_-]*$/'],
        ], [
            'line_oa_to.regex' => 'ID ผู้รับต้องเป็นตัวอักษร/ตัวเลขเท่านั้น (เช่น Uxxxxxxxx หรือ Cxxxxxxxx)',
        ]);

        if (filled($data['line_oa_token'] ?? null)) {
            Setting::write('line_oa_token', trim($data['line_oa_token']));
        }
        Setting::write('line_oa_to', trim((string) ($data['line_oa_to'] ?? '')));
        Setting::write('line_alerts_enabled', $request->boolean('line_alerts_enabled') ? '1' : '0');

        return back()->with('status', 'บันทึกการตั้งค่าแจ้งเตือนแล้ว');
    }

    /** Clear the stored token (e.g. after rotating it in the LINE console). */
    public function forget(): RedirectResponse
    {
        Setting::write('line_oa_token', '');
        Setting::write('line_alerts_enabled', '0');

        return back()->with('status', 'ลบ Token แล้ว และปิดการแจ้งเตือน');
    }

    public function test(): RedirectResponse
    {
        [$ok, $error] = LineNotifier::test();

        return $ok
            ? back()->with('status', 'ส่งข้อความทดสอบแล้ว — ลองเช็คใน LINE')
            : back()->withErrors(['line' => $error ?? 'ส่งไม่สำเร็จ']);
    }
}
