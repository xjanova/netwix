<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Raises the alarm when a source's CATALOGUE sync dies.
 *
 * This layer had no watchdog at all. [App\Console\Commands\SourceCanaryCommand] probes PLAYBACK, so
 * when wow-drama.com locked its WP REST API behind auth on ~2026-07-11 the canary kept reporting the
 * source healthy — every already-imported episode still played perfectly — while the nightly sync
 * threw a 401 into laravel.log and nothing new arrived for three weeks. AutoImportCommand was worse:
 * it caught the throwable and discarded it outright.
 *
 * A catalogue that has silently stopped growing looks exactly like a catalogue nobody is adding to,
 * which is why this needs to be pushed rather than logged. Throttled at 12h per source: the failure
 * is a standing condition, not an event, and repeating it nightly would train the owner to ignore it.
 */
final class CatalogSyncAlert
{
    public static function failed(string $sourceId, string $displayName, Throwable $e): void
    {
        Log::error('catalogue sync failed', [
            'source' => $sourceId,
            'error' => $e->getMessage(),
            'class' => $e::class,
        ]);

        LineNotifier::alert(
            'catalog-sync-'.$sourceId,
            "⚠️ ดึงรายชื่อเรื่องใหม่จาก {$displayName} ไม่สำเร็จ\n\n"
            .'สาเหตุ: '.self::reason($e)."\n\n"
            ."เรื่องที่นำเข้าไปแล้วยังดูได้ตามปกติ แต่จะไม่มีเรื่องใหม่เข้ามาจนกว่าจะแก้\n"
            .url('/admin/import-logs'),
            throttleMinutes: 720,
        );
    }

    /** A short, human reason — the raw exception text is a stack-trace wall on a phone screen. */
    private static function reason(Throwable $e): string
    {
        $msg = $e->getMessage();

        return match (true) {
            str_contains($msg, 'rest_login_required'), str_contains($msg, '401') => 'ต้นทางปิด API สาธารณะ (ต้องล็อกอิน)',
            str_contains($msg, '403') => 'ต้นทางบล็อกเซิร์ฟเวอร์เรา (403)',
            str_contains($msg, '404') => 'ต้นทางย้าย/ลบหน้าที่เราเรียก (404)',
            str_contains($msg, 'cURL'), str_contains($msg, 'timed out') => 'ต่อไปยังต้นทางไม่ได้ (เน็ต/DNS/ต้นทางล่ม)',
            default => mb_substr($msg, 0, 120),
        };
    }
}
