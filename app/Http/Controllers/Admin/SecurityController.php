<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlockedIp;
use App\Models\IpOffence;
use App\Models\SecurityEvent;
use App\Models\Setting;
use App\Support\EdgeSecret;
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

        $blocked = BlockedIp::orderByDesc('id')->limit(50)->get();

        // "Which time is this?" for each row on screen. Read in one query rather than per row, and
        // kept beside the block rather than joined into it, because the two have different lifetimes:
        // the block goes away, the record of it does not.
        $offences = IpOffence::whereIn('ip', $blocked->pluck('ip'))->get()->keyBy('ip');

        return view('admin.security.index', [
            'events' => $events,
            'offenders' => $offenders,
            'blocked' => $blocked,
            'offences' => $offences,
            // Only offenders the LADDER still counts. A row whose last strike is past the memory
            // window is reset to the first rung on the client's next offence, so listing it here
            // beside a "ครั้งหน้า: ถาวร" prediction would state a penalty the code will not apply —
            // and the admin would be deciding whether to forgive someone already forgiven.
            'repeatOffenders' => IpOffence::where('offences', '>=', 2)
                ->where('last_at', '>=', now()->subDays(ScrapeGuard::offenceMemoryDays()))
                ->orderByDesc('last_at')->limit(20)->get(),
            'mode' => ScrapeGuard::mode(),
            'firewall' => FirewallBlocklist::enabled(),
            'edge' => [
                'configured' => EdgeSecret::configured(),
                'enforcing' => EdgeSecret::enforcing(),
                'lastSeen' => EdgeSecret::lastSeen(),
                'seenRecently' => EdgeSecret::seenRecently(),
                'missing' => EdgeSecret::missingThisHour(),
                'header' => EdgeSecret::HEADER,
            ],
            'blockHours' => ScrapeGuard::blockHours(),
            'repeatHours' => ScrapeGuard::repeatHours(),
            'reason' => $reason,
            'ip' => $ip,
            'stats' => [
                'today' => SecurityEvent::where('created_at', '>=', now()->startOfDay())->count(),
                'week' => SecurityEvent::where('created_at', '>=', now()->subWeek())->count(),
                'blocked' => BlockedIp::active()->count(),
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
    /**
     * The Cloudflare secret header, in the only order that cannot take the site down: make a secret
     * (shown once, to paste into a Cloudflare Transform Rule), watch it arrive, then enforce. Enforcing
     * before Cloudflare sends it would refuse every visitor, so that is refused here instead.
     */
    public function edge(Request $request): RedirectResponse
    {
        $action = (string) $request->input('action');

        if ($action === 'generate') {
            return back()
                ->with('edge_secret', EdgeSecret::generate())
                ->with('status', 'สร้างรหัสลับใหม่แล้ว — คัดลอกไปใส่ใน Cloudflare ตามขั้นตอนด้านล่าง (รหัสนี้แสดงครั้งเดียว)');
        }
        if ($action === 'enforce') {
            if (! EdgeSecret::configured()) {
                return back()->withErrors(['edge' => 'ยังไม่ได้สร้างรหัสลับ']);
            }
            if (! EdgeSecret::seenRecently()) {
                return back()->withErrors(['edge' => 'ยังไม่เห็นรหัสนี้มากับคำขอจาก Cloudflare ใน 10 นาทีที่ผ่านมา — ถ้าบังคับตอนนี้ผู้ชมทุกคนจะเข้าเว็บไม่ได้ ตั้ง Transform Rule ให้เสร็จก่อน']);
            }
            Setting::write('cf_edge_enforce', '1');

            return back()->with('status', 'บังคับใช้รหัสลับแล้ว — คำขอที่ไม่ได้มาทาง Cloudflare จะถูกปฏิเสธทั้งหมด');
        }
        if ($action === 'relax') {
            Setting::write('cf_edge_enforce', '0');

            return back()->with('status', 'หยุดบังคับรหัสลับแล้ว (กลับไปใช้การตรวจแบบเดิม)');
        }
        if ($action === 'clear') {
            EdgeSecret::clear();

            return back()->with('status', 'ลบรหัสลับแล้ว — อย่าลืมลบ Transform Rule ใน Cloudflare ด้วย');
        }

        return back();
    }

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
        // like it did nothing. Both keys are the block key now (see ScrapeGuard::record), but the raw
        // form is cleared as well so a row written before that change also lets go.
        Cache::forget('guard:score:'.$ip);
        Cache::forget('guard:score:'.ScrapeGuard::blockKey($ip));

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
        $data = $request->validate([
            'hours' => ['required', 'integer', 'min:1', 'max:720'],
            // Optional, not required: a page left open from before the second rung existed posts only
            // `hours`, and rejecting that with an error about a field the admin cannot see on their
            // screen helps nobody. Absent means "leave the repeat penalty as it is".
            'repeat_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
        ]);

        Setting::write('scrape_block_hours', (string) $data['hours']);
        if (isset($data['repeat_hours'])) {
            Setting::write('scrape_block_repeat_hours', (string) $data['repeat_hours']);
        }

        $repeat = ScrapeGuard::repeatHours();

        return back()->with('status', "ครั้งแรกบล็อก {$data['hours']} ชม. · ครั้งที่ 2 บล็อก {$repeat} ชม. · ครั้งที่ 3 ถาวร");
    }

    /**
     * Wipe a client's ban history, so the next offence starts from the first rung again.
     *
     * The escalation deliberately outlives both the block and the unblock — otherwise lifting a ban
     * would erase the very thing that proves someone is a repeat offender. But that cuts the other
     * way too: when a block was a MISTAKE, the record of it is a mistake as well, and leaving it in
     * place means an innocent viewer who trips the same faulty rule twice more is banned forever
     * without anyone deciding to do that. Every rule in this system has produced false positives
     * before (all 96 of the first events were), so exonerating has to be as easy as unblocking.
     */
    public function forgive(Request $request, IpOffence $ipOffence): RedirectResponse
    {
        $ip = $ipOffence->ip;
        $had = $ipOffence->offences;
        $ipOffence->delete();

        $this->note($request, $ip, 'ล้างประวัติการโดนแบน', "เดิม {$had} ครั้ง");

        return back()->with('status', "ล้างประวัติของ {$ip} แล้ว — ครั้งต่อไปจะเริ่มนับใหม่");
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
