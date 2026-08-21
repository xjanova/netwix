<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushes operational problems to the owner's LINE Official Account — a dead source, titles going
 * un-playable — so a breakage is noticed within minutes instead of whenever someone happens to browse.
 * (24-hdx went down site-wide on 2026-07-28 and nothing told anybody.)
 *
 * THROTTLING IS THE WHOLE DESIGN. An outage is not one event: a dead source produces thousands of
 * failing titles, and a notifier that faithfully reports each one is a notifier the owner mutes by
 * lunchtime — after which it may as well not exist. So every alert carries a key, the same key is
 * silent for a cooling-off period, and high-volume events are counted and sent as one digest instead.
 *
 * Config lives in Settings: `line_alerts_enabled`, `line_oa_token` (SECRET — encrypted at rest,
 * like every other channel token here) and `line_oa_to` (the admin's userId, or a groupId).
 */
class LineNotifier
{
    private const PUSH_URL = 'https://api.line.me/v2/bot/message/push';

    /** LINE hard-caps a text message at 5000 chars; stay well clear and keep it readable on a phone. */
    private const MAX_CHARS = 1500;

    public static function enabled(): bool
    {
        return Setting::flag('line_alerts_enabled', false)
            && self::token() !== '' && self::target() !== '';
    }

    private static function token(): string
    {
        return trim((string) Setting::get('line_oa_token', ''));
    }

    private static function target(): string
    {
        return trim((string) Setting::get('line_oa_to', ''));
    }

    /**
     * Send an alert, at most once per $throttleMinutes for the same $key.
     *
     * The throttle is claimed with Cache::add (atomic) BEFORE sending, so two workers hitting the
     * same problem in the same second can't both fire — the loser simply skips.
     *
     * @return bool true when a message was actually pushed
     */
    public static function alert(string $key, string $message, int $throttleMinutes = 60): bool
    {
        if (! self::enabled()) {
            return false;
        }
        if (! Cache::add('line:alert:'.sha1($key), 1, now()->addMinutes(max(1, $throttleMinutes)))) {
            return false;   // already reported recently — silence is the feature
        }

        return self::push($message, $key);
    }

    /** Send without throttling — for the admin's own "ทดสอบส่ง" button. Returns [ok, error]. */
    public static function test(): array
    {
        if (! self::enabled()) {
            return [false, 'ยังไม่ได้เปิดใช้งาน หรือยังไม่ได้ใส่ Token / ผู้รับ'];
        }
        $ok = self::push("✅ ทดสอบการแจ้งเตือนจาก NetWix\nถ้าคุณเห็นข้อความนี้ แปลว่าระบบแจ้งเตือนปัญหาพร้อมใช้งานแล้ว", 'test');

        return [$ok, $ok ? null : 'ส่งไม่สำเร็จ — ตรวจสอบ Token และ ID ผู้รับ (ดู log)'];
    }

    /** Raw push. Never throws: an alerting failure must not take down whatever was reporting. */
    private static function push(string $text, string $key = ''): bool
    {
        try {
            $resp = Http::withToken(self::token())
                ->connectTimeout(5)->timeout(12)
                ->post(self::PUSH_URL, [
                    'to' => self::target(),
                    'messages' => [[
                        'type' => 'text',
                        'text' => mb_substr($text, 0, self::MAX_CHARS),
                    ]],
                ]);

            if (! $resp->successful()) {
                Log::warning('line-alert: push failed', [
                    'status' => $resp->status(),
                    'body' => mb_substr($resp->body(), 0, 300),
                ]);
                self::record($key, $text, false, 'HTTP '.$resp->status().' '.mb_substr($resp->body(), 0, 180));

                return false;
            }

            self::record($key, $text, true, null);

            return true;
        } catch (Throwable $e) {
            Log::warning('line-alert: push threw', ['error' => $e->getMessage()]);
            self::record($key, $text, false, mb_substr($e->getMessage(), 0, 200));

            return false;
        }
    }

    /**
     * Keep what we sent, so "what was that alert on my phone?" is answerable.
     *
     * Stores the UN-hashed key: the throttle lives in the cache as sha1($key), which is deliberately
     * one-way, so the cache can say "something fired recently" but never what. Swallows its own errors
     * — a bookkeeping failure must not turn into a lost alert.
     */
    private static function record(string $key, string $text, bool $ok, ?string $error): void
    {
        try {
            DB::table('line_alerts')->insert([
                'alert_key' => $key !== '' ? mb_substr($key, 0, 120) : null,
                'body' => mb_substr($text, 0, 2000),
                'ok' => $ok,
                'error' => $error,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // table missing (pre-migrate) or DB hiccup — never break alerting over its own log
        }
    }

    /** The most recent alerts we pushed, newest first — for the admin page and for after-the-fact questions. */
    public static function recent(int $limit = 20): array
    {
        try {
            return DB::table('line_alerts')->orderByDesc('id')->limit($limit)->get()->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    // ---------------------------------------------------------------- digest

    /**
     * Note that a title went un-playable. Deliberately NOT an immediate push: this fires per title,
     * and a single dead source produces thousands. The count is accumulated here and mailed out once
     * by [App\Console\Commands\AlertDigestCommand].
     */
    public static function noteSuspended(int $contentId, string $title, string $source): void
    {
        if (! self::enabled()) {
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
     * Flush the accumulated title-level problems as ONE message. Returns the number reported.
     * Called on a schedule, not inline.
     */
    public static function flushDigest(): int
    {
        if (! self::enabled()) {
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
            $bySource[$row['source'] ?: '—'][] = $row['title'];
        }

        $lines = ['⚠️ มีหนังเล่นไม่ได้ถูกหยุดเผยแพร่อัตโนมัติ '.count($bucket).' เรื่อง'];
        foreach ($bySource as $source => $titles) {
            $lines[] = '';
            $lines[] = "• แหล่ง {$source} — ".count($titles).' เรื่อง';
            foreach (array_slice($titles, 0, 5) as $t) {
                $lines[] = '   - '.mb_substr($t, 0, 60);
            }
            if (count($titles) > 5) {
                $lines[] = '   - … และอีก '.(count($titles) - 5).' เรื่อง';
            }
        }
        $lines[] = '';
        $lines[] = 'ดูรายการทั้งหมด: '.url('/admin/contents?filter=suspended');

        self::push(implode("\n", $lines), 'digest-suspended');

        return count($bucket);
    }
}
