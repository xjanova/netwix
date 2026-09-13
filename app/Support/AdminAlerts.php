<?php

namespace App\Support;

use App\Models\Setting;
use App\Support\Alerts\Alert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

use function Illuminate\Support\defer;

/**
 * The one place operational problems are reported from — a dead source, titles going un-playable,
 * a catalogue sync dying, someone attacking us — fanned out to every channel the owner has switched
 * on: Telegram (free) and LINE (metered past its free quota).
 *
 * THROTTLING IS THE WHOLE DESIGN. An outage is not one event: a dead source produces thousands of
 * failing titles, and a notifier that faithfully reports each one is a notifier the owner mutes by
 * lunchtime — after which it may as well not exist. So every alert carries a key, the same key is
 * silent for a cooling-off period, and high-volume events are counted and sent as one digest.
 * The throttle is claimed once, before any channel sends, so adding a channel never doubles the
 * rate and two workers can't both fire.
 */
final class AdminAlerts
{
    /** Channel => notifier. Telegram first: it is free and unmetered. */
    public const CHANNELS = [
        'telegram' => TelegramNotifier::class,
        'line' => LineNotifier::class,
    ];

    /** What an alert is about => [name, what falls under it]. The admin routes each to channels. */
    public const CATEGORIES = [
        'money' => ['เงิน & ยอดขาย', 'มีเงินเข้า · โฆษณารออนุมัติ · เงินโอนเข้าแต่จับคู่ออเดอร์ไม่ได้ · ระบบตรวจยอดโอนเสีย'],
        'sources' => ['แหล่งหนัง & การนำเข้า', 'แหล่งดึงลิ้งค์ไม่ได้/กลับมาแล้ว · ดึงเรื่องใหม่ไม่ได้ · แหล่งไม่มีเรื่องใหม่หลายวัน · หนังเล่นไม่ได้ถูกหยุด'],
        'security' => ['ความปลอดภัย', 'มีคนเจาะระบบ/ดูดลิงก์ · มีการเปลี่ยนกระเป๋ารับเงินหรือคีย์ · บอทสแกน (ส่งแบบไม่มีเสียง)'],
        'system' => ['เซิร์ฟเวอร์ & ระบบ', 'เว็บเกิด error · ดิสก์ใกล้เต็ม · ตัวตั้งเวลา (cron) หยุด · งานเบื้องหลังล้มเหลว'],
        'marketing' => ['การตลาด & Facebook', 'โพสต์คลิปลงเพจไม่สำเร็จ · Token เพจหมดอายุ · แคมเปญรันแต่ยังไม่ได้เชื่อมเพจ'],
        'members' => ['สมาชิกใหม่', 'มีคนสมัครสมาชิก — รวบยอดส่งชั่วโมงละครั้ง'],
        'daily' => ['รายงานประจำวัน', 'สรุปยอดเมื่อวานพร้อมกราฟ ทุกเช้า 09:00 น.'],
    ];

    /**
     * Which categories each channel gets when the admin hasn't chosen. LINE is metered — the reason
     * Telegram exists at all — so it keeps exactly what it received before, and nothing new lands
     * on the owner's LINE bill unless they tick it.
     */
    private const DEFAULT_ROUTES = [
        'telegram' => ['money', 'sources', 'security', 'system', 'marketing', 'members', 'daily'],
        'line' => ['sources', 'security'],
    ];

    /** True when at least one channel is switched on and configured. */
    public static function enabled(): bool
    {
        foreach (self::CHANNELS as $notifier) {
            if ($notifier::enabled()) {
                return true;
            }
        }

        return false;
    }

    /** True when some switched-on channel takes this category — i.e. an alert of it would go somewhere. */
    public static function wants(string $category): bool
    {
        foreach (self::CHANNELS as $channel => $notifier) {
            if ($notifier::enabled() && self::routed($channel, $category)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,array<int,string>> channel => categories it receives */
    public static function routes(): array
    {
        $saved = json_decode((string) Setting::get('alert_routes', ''), true);
        $routes = self::DEFAULT_ROUTES;
        foreach (array_keys(self::CHANNELS) as $channel) {
            if (is_array($saved) && isset($saved[$channel]) && is_array($saved[$channel])) {
                $routes[$channel] = array_values(array_intersect(array_keys(self::CATEGORIES), $saved[$channel]));
            }
        }

        return $routes;
    }

    public static function routed(string $channel, string $category): bool
    {
        return in_array($category, self::routes()[$channel] ?? [], true);
    }

    /** @param array<string,array<int,string>> $routes */
    public static function saveRoutes(array $routes): void
    {
        $clean = [];
        foreach (array_keys(self::CHANNELS) as $channel) {
            $clean[$channel] = array_values(array_intersect(array_keys(self::CATEGORIES), (array) ($routes[$channel] ?? [])));
        }
        Setting::write('alert_routes', json_encode($clean));
    }

    /**
     * Send an alert, at most once per $throttleMinutes for the same key.
     *
     * The throttle is claimed with Cache::add (atomic) BEFORE sending, so two workers hitting the same
     * problem in the same second can't both fire — the loser simply skips. (The "line:" prefix is
     * from when LINE was the only channel. deploy.sh's optimize:clear wipes these, so a standing
     * problem is reported once more after each deploy.)
     *
     * In a web request the delivery happens AFTER the response is sent: drawing the card and two API
     * calls cost about a second, and the request that noticed the problem — a customer polling their
     * payment, a banned scanner — should not wait for the owner's phone. Pass $wait when the response
     * is already gone (a terminate() hook runs after deferred callbacks and would lose it).
     *
     * @return bool true when delivered (web: when queued for after the response)
     */
    public static function send(Alert $alert, int $throttleMinutes = 60, bool $wait = false): bool
    {
        // Checked BEFORE the throttle is claimed: an alert nobody would receive must not use up the
        // cooling-off period of one the admin switches back on a minute later.
        if (! self::wants($alert->category)) {
            return false;
        }
        if (! Cache::add('line:alert:'.sha1($alert->key), 1, now()->addMinutes(max(1, $throttleMinutes)))) {
            return false;   // already reported recently — silence is the feature
        }

        if (! $wait && ! app()->runningInConsole()) {
            defer(fn () => self::deliver($alert, array_keys(self::CHANNELS)), 'admin-alert:'.$alert->key, always: true);

            return true;
        }

        return self::deliver($alert, array_keys(self::CHANNELS));
    }

    /** Reopen a key's cooling-off period early — when the condition it reported has ended. */
    public static function forgetThrottle(string $key): void
    {
        try {
            Cache::forget('line:alert:'.sha1($key));
        } catch (Throwable) {
        }
    }

    /**
     * The admin's "ทดสอบส่ง" button for one channel: no throttle, and it only needs the channel to be
     * configured — proving a token works is exactly what you do BEFORE switching alerts on.
     *
     * @return array{0:bool,1:?string} [delivered, reason]
     */
    public static function test(string $channel): array
    {
        $notifier = self::CHANNELS[$channel] ?? null;
        if ($notifier === null || ! $notifier::configured()) {
            return [false, $channel === 'telegram'
                ? 'ยังไม่ได้ใส่ Bot Token หรือ Chat ID'
                : 'ยังไม่ได้ใส่ Token หรือ ID ผู้รับ'];
        }

        $health = SourceHealth::all();
        $chips = [];
        foreach ($health as $source => $verdict) {
            $chips[$source] = empty($verdict['down']);
        }
        $up = count(array_filter($chips));

        $alert = new Alert(
            key: 'test',
            level: Alert::OK,
            title: 'ทดสอบการแจ้งเตือนจาก NetWix',
            body: 'ถ้าคุณเห็นข้อความนี้ แปลว่าระบบแจ้งเตือนปัญหาพร้อมใช้งานแล้ว',
            facts: array_filter([
                'แหล่งหนัง' => $chips !== [] ? "{$up}/".count($chips).' ปกติ' : null,
                'ช่องทาง' => $channel === 'telegram' ? 'Telegram' : 'LINE',
            ]),
            chips: $chips,
            url: url('/admin/alerts'),
            urlLabel: 'ตั้งค่าการแจ้งเตือน',
        );

        $error = self::deliverTo($channel, $alert);

        return [$error === null, $error];
    }

    /** @param array<int,string> $channels */
    private static function deliver(Alert $alert, array $channels): bool
    {
        $any = false;
        foreach ($channels as $channel) {
            if (! self::CHANNELS[$channel]::enabled() || ! self::routed($channel, $alert->category)) {
                continue;
            }
            $any = self::deliverTo($channel, $alert) === null || $any;
        }

        return $any;
    }

    private static function deliverTo(string $channel, Alert $alert): ?string
    {
        $error = self::CHANNELS[$channel]::deliver($alert);
        self::record($channel, $alert, $error);

        return $error;
    }

    // ---------------------------------------------------------------------------- history

    /**
     * Keep what we sent, per channel, so "what was that alert on my phone?" is answerable.
     *
     * Stores the UN-hashed key: the throttle lives in the cache as sha1($key), which is deliberately
     * one-way. The table is still called line_alerts from when LINE was the only channel. Swallows
     * its own errors — a bookkeeping failure must not turn into a lost alert.
     */
    private static function record(string $channel, Alert $alert, ?string $error): void
    {
        try {
            DB::table('line_alerts')->insert([
                'channel' => $channel,
                'alert_key' => mb_substr($alert->key, 0, 120),
                'body' => mb_substr($alert->toText(), 0, 2000),
                'ok' => $error === null,
                'error' => $error !== null ? mb_substr($error, 0, 255) : null,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // table missing (pre-migrate) or DB hiccup — never break alerting over its own log
        }
    }

    /** The most recent alerts we pushed, newest first — for the admin page and after-the-fact questions. */
    public static function recent(int $limit = 20): array
    {
        try {
            return DB::table('line_alerts')->orderByDesc('id')->limit($limit)->get()->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    // ---------------------------------------------------------------------------- digest

    /**
     * Note that a title went un-playable. Deliberately NOT an immediate push: this fires per title,
     * and a single dead source produces thousands. The count is accumulated here and sent once an
     * hour by [App\Console\Commands\AlertDigestCommand].
     */
    public static function noteSuspended(int $contentId, string $title, string $source): void
    {
        if (! self::wants('sources')) {
            return;
        }
        try {
            $bucket = Cache::get('line:digest:suspended', []);
            if (! is_array($bucket)) {
                $bucket = [];
            }
            // Keyed by id so the same title re-reported inside one window counts once.
            $bucket[$contentId] = ['title' => $title, 'source' => $source];
            Cache::put('line:digest:suspended', array_slice($bucket, 0, 500, true), now()->addHours(6));
        } catch (Throwable $e) {
            // never break playback bookkeeping over an alert
        }
    }

    /**
     * Flush the accumulated title-level problems as ONE alert. Returns the number reported.
     * Called on a schedule, not inline.
     */
    public static function flushDigest(): int
    {
        if (! self::wants('sources')) {
            return 0;
        }
        try {
            $bucket = Cache::pull('line:digest:suspended', []);
        } catch (Throwable $e) {
            return 0;
        }
        if (! is_array($bucket) || $bucket === []) {
            return 0;
        }

        $bySource = [];
        foreach ($bucket as $row) {
            $bySource[($row['source'] ?? '') ?: '—'][] = (string) ($row['title'] ?? '');
        }
        uasort($bySource, fn ($a, $b) => count($b) <=> count($a));

        $lines = [];
        foreach ($bySource as $source => $titles) {
            $lines[] = "• แหล่ง {$source} — ".count($titles).' เรื่อง';
            foreach (array_slice($titles, 0, 5) as $t) {
                $lines[] = '   - '.mb_substr($t, 0, 60);
            }
            if (count($titles) > 5) {
                $lines[] = '   - … และอีก '.(count($titles) - 5).' เรื่อง';
            }
        }

        self::deliver(new Alert(
            key: 'digest-suspended',
            level: Alert::WARNING,
            title: 'หนังเล่นไม่ได้ถูกหยุดเผยแพร่อัตโนมัติ '.number_format(count($bucket)).' เรื่อง',
            body: implode("\n", $lines),
            facts: ['ทั้งหมด' => number_format(count($bucket)).' เรื่อง', 'จาก' => count($bySource).' แหล่ง'],
            bars: array_map('count', $bySource),
            url: url('/admin/contents?filter=suspended'),
            urlLabel: 'ดูรายการที่ถูกหยุด',
            category: 'sources',
        ), array_keys(self::CHANNELS));

        return count($bucket);
    }

    /**
     * Note a new member. Collected like the suspension digest, not pushed: one ping per signup is a
     * delight at six members and a muted channel at six hundred — so they go out hourly, together.
     */
    public static function noteSignup(int $userId, string $name, string $via): void
    {
        if (! self::wants('members')) {
            return;
        }
        try {
            $bucket = Cache::get('alert:digest:signups', []);
            $bucket = is_array($bucket) ? $bucket : [];
            $bucket[$userId] = ['name' => $name, 'via' => $via];
            Cache::put('alert:digest:signups', array_slice($bucket, -500, null, true), now()->addHours(6));
        } catch (Throwable $e) {
            // never break a signup over an alert
        }
    }

    /** Send the hour's signups as one alert. Returns how many were reported. */
    public static function flushSignups(): int
    {
        if (! self::wants('members')) {
            return 0;
        }
        try {
            $bucket = Cache::pull('alert:digest:signups', []);
        } catch (Throwable $e) {
            return 0;
        }
        if (! is_array($bucket) || $bucket === []) {
            return 0;
        }

        $via = array_count_values(array_map(fn ($r) => (string) ($r['via'] ?? 'อีเมล'), $bucket));
        arsort($via);
        $names = array_map(fn ($r) => '• '.mb_substr((string) ($r['name'] ?? ''), 0, 40), array_slice($bucket, -8, null, true));

        try {
            $today = \App\Models\User::where('created_at', '>=', now('Asia/Bangkok')->startOfDay()->utc())->count();
            $total = \App\Models\User::count();
        } catch (Throwable $e) {
            $today = $total = null;
        }

        self::deliver(new Alert(
            key: 'digest-signups',
            level: Alert::OK,
            title: 'สมาชิกใหม่ '.number_format(count($bucket)).' คนในชั่วโมงที่ผ่านมา',
            body: (count($bucket) > 8 ? 'ล่าสุด:' : 'ได้แก่:')."\n".implode("\n", array_reverse($names)),
            facts: array_filter([
                'ชั่วโมงนี้' => number_format(count($bucket)).' คน',
                'วันนี้ทั้งหมด' => $today !== null ? number_format($today).' คน' : null,
                'สมาชิกทั้งหมด' => $total !== null ? number_format($total).' คน' : null,
            ]),
            bars: count($via) > 1 ? $via : [],
            url: url('/admin/users'),
            urlLabel: 'ดูรายชื่อสมาชิก',
            category: 'members',
            barsLabel: 'สมัครผ่าน',
        ), array_keys(self::CHANNELS));

        return count($bucket);
    }
}
