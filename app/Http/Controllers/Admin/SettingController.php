<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Genre;
use App\Models\Setting;
use App\Services\AppRelease;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingController extends Controller
{
    public function index(): View
    {
        return view('admin.settings.index', [
            'google_client_id' => Setting::get('google_client_id'),
            'line_client_id' => Setting::get('line_client_id'),
            'support_line_url' => Setting::get('support_line_url', config('services.support.line_url')),
            'support_email' => Setting::get('support_email', config('services.support.email')),
            // Never echo secrets — only whether one is stored (drives the "ตั้งค่าแล้ว" pill).
            'hasGoogleSecret' => filled(Setting::get('google_client_secret')),
            'hasLineSecret' => filled(Setting::get('line_client_secret')),
            'callbackBase' => rtrim(config('app.url'), '/'),
            // App (APK) auto-update from GitHub Releases
            'app_github_repo' => Setting::get('app_github_repo'),
            'hasAppToken' => filled(Setting::get('app_github_token')),
            'appRelease' => app(AppRelease::class)->latest(),
            'emailRegEnabled' => Setting::flag('email_registration_enabled', true),
            // Cloudflare Turnstile anti-spam (login / register / comments)
            'turnstile_site_key' => Setting::get('turnstile_site_key'),
            'hasTurnstileSecret' => filled(Setting::get('turnstile_secret')),
            // Home hero billboard: which titles rotate — 'featured' or 'genre:<id>'.
            'home_hero_source' => Setting::get('home_hero_source', 'featured'),
            'home_hero_seconds' => (int) Setting::get('home_hero_seconds', 8),
            'genres' => Genre::orderBy('sort')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'google_client_id' => ['nullable', 'string', 'max:255'],
            'google_client_secret' => ['nullable', 'string', 'max:255'],
            'line_client_id' => ['nullable', 'string', 'max:255'],
            'line_client_secret' => ['nullable', 'string', 'max:255'],
            'support_line_url' => ['nullable', 'url:http,https', 'max:255'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'app_github_repo' => ['nullable', 'string', 'max:255', 'regex:/^[\w.-]+\/[\w.-]+$/'],
            'app_github_token' => ['nullable', 'string', 'max:255'],
            'turnstile_site_key' => ['nullable', 'string', 'max:255'],
            'turnstile_secret' => ['nullable', 'string', 'max:255'],
            'home_hero_source' => ['nullable', 'string', 'max:40', 'regex:/^(featured|genre:\d+)$/'],
            'home_hero_seconds' => ['nullable', 'integer', 'between:0,60'],
        ], [
            'support_line_url.url' => 'ลิงก์ LINE ต้องเป็น URL ที่ขึ้นต้นด้วย http/https',
            'support_email.email' => 'อีเมลไม่ถูกต้อง',
            'app_github_repo.regex' => 'รูปแบบต้องเป็น owner/repo เช่น xjanova/netwixmobile',
        ]);

        // Plain (non-secret) fields are pre-filled in the form, so they resubmit
        // their real value — safe to write as-is (null clears them).
        foreach (['google_client_id', 'line_client_id', 'support_line_url', 'support_email', 'app_github_repo', 'turnstile_site_key', 'home_hero_source', 'home_hero_seconds'] as $field) {
            Setting::write($field, $data[$field] ?? null);
        }

        // Registration mode toggle (checkbox → keep email/password sign-up or go social-only).
        Setting::write('email_registration_enabled', $request->boolean('email_registration_enabled') ? '1' : '0');

        // Secret fields are masked and never echoed, so the form ALWAYS submits them
        // empty. Laravel's ConvertEmptyStringsToNull turns that '' into null — so a
        // blank submit must be treated as "keep the existing secret", NOT a wipe.
        // (brain: SlipOK/Stripe key-wiped-on-save — check blank(), not === '')
        // An explicit "ล้างค่า" checkbox is the only way to clear a stored secret.
        foreach (['google_client_secret', 'line_client_secret', 'app_github_token', 'turnstile_secret'] as $field) {
            if ($request->boolean($field.'_clear')) {
                Setting::write($field, null);
            } elseif (filled($data[$field] ?? null)) {
                Setting::write($field, $data[$field]);
            }
        }

        // A repo/token change should reflect on the download page right away.
        AppRelease::forget();

        return back()->with('status', 'บันทึกการตั้งค่าแล้ว');
    }
}
