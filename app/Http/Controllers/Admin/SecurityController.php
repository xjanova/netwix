<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlockedIp;
use App\Models\SecurityEvent;
use App\Models\Setting;
use App\Support\FirewallBlocklist;
use App\Support\ScrapeGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * "ความปลอดภัย / พฤติกรรมน่าสงสัย" — the evidence log, and the controls over what is done about it.
 *
 * Two things this page exists to make possible. First, going back later: when something odd happens
 * we want to be able to ask "who has been walking the catalogue, and when" months afterwards, which
 * needs the observations kept rather than summarised away. Second, staying reversible: every block
 * can be lifted here, and every block and unblock is itself written to the log with a reason, so the
 * record of what was done is as durable as the record of what was seen.
 */
class SecurityController extends Controller
{
    public function index(Request $request): View
    {
        $reason = trim((string) $request->query('reason', ''));
        $ip = trim((string) $request->query('ip', ''));

        $events = SecurityEvent::query()
            ->when($reason !== '', fn ($q) => $q->where('reason', $reason))
            ->when($ip !== '', fn ($q) => $q->where('ip', $ip))
            ->orderByDesc('id')
            ->paginate(60)
            ->withQueryString();

        // "Who is worth looking at" — the accumulated score per address over the last day, which is
        // the same shape the guard scores on, just over a longer window.
        $offenders = SecurityEvent::query()
            ->selectRaw('ip, sum(score) as total, count(*) as events, max(created_at) as last_seen')
            ->where('created_at', '>=', now()->subDay())
            ->groupBy('ip')
            ->orderByDesc('total')
            ->limit(20)
            ->get();

        return view('admin.security.index', [
            'events' => $events,
            'offenders' => $offenders,
            'blocked' => BlockedIp::orderByDesc('id')->limit(50)->get(),
            'mode' => ScrapeGuard::mode(),
            'firewall' => FirewallBlocklist::enabled(),
            'blockHours' => ScrapeGuard::blockHours(),
            'reason' => $reason,
            'ip' => $ip,
            'stats' => [
                'today' => SecurityEvent::where('created_at', '>=', now()->startOfDay())->count(),
                'week' => SecurityEvent::where('created_at', '>=', now()->subWeek())->count(),
                'blocked' => BlockedIp::where(fn ($q) => $q->where('manual', true)->orWhere('expires_at', '>', now()))->count(),
            ],
        ]);
    }

    /** off = stop watching · observe = record only · enforce = actually refuse. */
    public function setMode(Request $request): RedirectResponse
    {
        $data = $request->validate(['mode' => ['required', 'in:off,observe,enforce']]);
        Setting::write('scrape_guard_mode', $data['mode']);

        $label = ['off' => 'ปิดระบบ', 'observe' => 'สังเกตอย่างเดียว (ไม่บล็อก)', 'enforce' => 'บล็อกจริง'][$data['mode']];

        return back()->with('status', "เปลี่ยนโหมดเป็น: {$label}");
    }

    /**
     * Turn the Apache-level blocklist on or off.
     *
     * Switching it on writes the list immediately, so the effect (or the failure) is visible now
     * rather than at some later block. [FirewallBlocklist] restores the previous file by itself if
     * the site stops answering, and says so in the message.
     */
    public function toggleFirewall(Request $request): RedirectResponse
    {
        $on = $request->boolean('enabled');
        Setting::write('firewall_blocklist_enabled', $on ? '1' : '0');

        $result = $on ? FirewallBlocklist::sync() : FirewallBlocklist::clear();

        if (! $result['ok']) {
            Setting::write('firewall_blocklist_enabled', '0');

            return back()->withErrors(['firewall' => 'เปิดไม่สำเร็จ: '.$result['error']]);
        }

        return back()->with('status', $on
            ? "เปิดการบล็อกที่ไฟร์วอลล์แล้ว (เขียนกฎ {$result['count']} รายการ) — ผู้ต้องสงสัยจะถูกปฏิเสธก่อนถึงระบบ"
            : 'ปิดการบล็อกที่ไฟร์วอลล์แล้ว — ยังบล็อกในระบบตามปกติ');
    }

    /** Block an address by hand. Manual blocks never expire on their own. */
    public function block(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ip' => ['required', 'ip'],
            'hours' => ['nullable', 'integer', 'min:0', 'max:8760'],   // 0 = ถาวร
            'note' => ['nullable', 'string', 'max:120'],
        ]);

        // Blocked by the same key an automatic block would use, so a hand-typed IPv6 address covers
        // the whole subscriber instead of the one address that happened to be on screen.
        $key = ScrapeGuard::blockKey($data['ip']);
        $hours = $data['hours'] ?? null;

        BlockedIp::updateOrCreate(
            ['ip' => $key],
            [
                'reason' => 'manual', 'score' => 0, 'manual' => true,
                'expires_at' => ($hours === null || $hours === 0) ? null : now()->addHours($hours),
            ],
        );
        Cache::forget('guard:block:'.$key);
        Cache::forget('guard:block:'.$data['ip']);

        $this->note($request, $key, 'บล็อกด้วยตนเอง', $data['note'] ?? null);
        FirewallBlocklist::sync();

        return back()->with('status', "บล็อก {$key} แล้ว".($hours ? " ({$hours} ชม.)" : ' (ถาวร)'));
    }

    /** Lift a block, and remember that it was lifted, by whom, and why. */
    public function unblock(Request $request, BlockedIp $blockedIp): RedirectResponse
    {
        $ip = $blockedIp->ip;
        $note = trim((string) $request->input('note', ''));

        $blockedIp->delete();
        Cache::forget('guard:block:'.$ip);
        // Clear the running score too, or the next request re-blocks instantly and the unblock looks
        // like it did nothing.
        Cache::forget('guard:score:'.$ip);

        $this->note($request, $ip, 'ปลดบล็อก', $note !== '' ? $note : null);
        FirewallBlocklist::sync();

        return back()->with('status', "ปลดบล็อก {$ip} แล้ว");
    }

    /**
     * Change how long an existing block lasts — extend it, cut it short, or make it permanent.
     *
     * Separate from unblock() because "this one deserves longer" and "this was a mistake" are
     * different judgements, and collapsing them into delete-and-re-add would lose the block's history
     * and its hit count, which are the evidence for making that judgement in the first place.
     */
    public function setDuration(Request $request, BlockedIp $blockedIp): RedirectResponse
    {
        $data = $request->validate(['hours' => ['required', 'integer', 'min:0', 'max:8760']]);
        $hours = (int) $data['hours'];

        $blockedIp->update([
            'expires_at' => $hours === 0 ? null : now()->addHours($hours),
            'manual' => $hours === 0 ? true : $blockedIp->manual,
        ]);
        Cache::forget('guard:block:'.$blockedIp->ip);

        $label = $hours === 0 ? 'ถาวร' : "{$hours} ชม. นับจากนี้";
        $this->note($request, $blockedIp->ip, 'แก้เวลาบล็อก', $label);
        FirewallBlocklist::sync();

        return back()->with('status', "ตั้งเวลาบล็อก {$blockedIp->ip} เป็น {$label}");
    }

    /** The default length of an automatic block, in hours. 0 is not offered — that is what mode=off is for. */
    public function setDefaultHours(Request $request): RedirectResponse
    {
        $data = $request->validate(['hours' => ['required', 'integer', 'min:1', 'max:720']]);
        Setting::write('scrape_block_hours', (string) $data['hours']);

        return back()->with('status', "บล็อกอัตโนมัติจะนาน {$data['hours']} ชม.");
    }

    /** Record an admin action in the same log as the observations, so the history is one story. */
    private function note(Request $request, string $ip, string $action, ?string $note): void
    {
        SecurityEvent::create([
            'ip' => $ip,
            'reason' => 'admin',
            'score' => 0,
            'method' => $request->method(),
            'path' => 'admin/security',
            'user_agent' => 'admin: '.($request->user()->name ?? $request->user()->email ?? '?'),
            'meta' => array_filter(['action' => $action, 'note' => $note]),
            'created_at' => now(),
        ]);
    }
}
