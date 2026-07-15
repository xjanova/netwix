<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FbEngagement;
use App\Models\Setting;
use App\Support\FacebookMessenger;
use App\Support\FbInviteFunnel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Admin control panel for the Facebook comment→invite-DM funnel: the master kill-switch, the
 * rotated invite templates, the cooldown/cap, and the webhook credentials the owner pastes into
 * the Facebook App dashboard. Config is one JSON Setting (see [App\Support\FbInviteFunnel]).
 */
class FbDmController extends Controller
{
    public function __construct(private FbInviteFunnel $funnel, private FacebookMessenger $fb) {}

    public function index(): View
    {
        // Give the owner a verify token to paste into Facebook on first visit (never blank).
        if ($this->fb->webhookVerifyToken() === '') {
            Setting::write('fb_webhook_verify_token', Str::random(24));
        }

        $cfg = $this->funnel->config();
        $stats = [
            'total' => FbEngagement::count(),
            'sent' => FbEngagement::where('dm_status', 'sent')->count(),
            'skipped' => FbEngagement::where('dm_status', 'skipped')->count(),
            'failed' => FbEngagement::where('dm_status', 'failed')->count(),
            'today' => FbEngagement::whereDate('created_at', today())->count(),
        ];

        return view('admin.fb-dm.index', [
            'cfg' => $cfg,
            'stats' => $stats,
            'recent' => FbEngagement::with('content:id,title')->latest()->take(20)->get(),
            'webhookUrl' => url('/api/fb/webhook'),
            'verifyToken' => $this->fb->webhookVerifyToken(),
            'connected' => $this->fb->connected(),
            'pageName' => Setting::get('fb_page_name'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'cooldown_days' => ['required', 'integer', 'between:0,365'],
            'daily_cap_per_user' => ['required', 'integer', 'between:0,1000'],
            'messages' => ['required', 'string', 'max:20000'],
            'verify_token' => ['nullable', 'string', 'max:120'],
        ]);

        // Templates are separated by a line containing only "---" (a template itself has newlines).
        $messages = array_values(array_filter(
            array_map('trim', preg_split('/^\s*---\s*$/m', $data['messages']) ?: []),
            fn ($m) => $m !== '',
        ));

        $this->funnel->saveConfig(array_replace($this->funnel->config(), [
            'enabled' => $request->boolean('enabled'),
            'cooldown_days' => (int) $data['cooldown_days'],
            'daily_cap_per_user' => (int) $data['daily_cap_per_user'],
            'messages' => $messages ?: FbInviteFunnel::DEFAULTS['messages'],
        ]));

        if (filled($data['verify_token'] ?? null)) {
            Setting::write('fb_webhook_verify_token', trim($data['verify_token']));
        }

        return back()->with('status', 'บันทึกการตั้งค่า DM ชวนดูหนังแล้ว');
    }
}
