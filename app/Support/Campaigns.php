<?php

namespace App\Support;

use App\Models\Announcement;
use App\Services\Membership;

/**
 * Active marketing campaigns for the landing ticker + the post-login promo marquee. The two headline
 * promos are DERIVED from the live Membership config (so they always match the real reward): the
 * signup free-Pro window (`pro.free_days`) and the referral reward (`referral.referee_pro_days`).
 * Admin-written [Announcement]s are appended, so the owner can add extra promos without code.
 *
 * @return array<int,array{icon:string,badge:string,text:string,link:string}>
 */
class Campaigns
{
    public static function active(bool $guest = false): array
    {
        $items = [];

        try {
            $cfg = app(Membership::class)->config();

            $freeDays = (int) ($cfg['pro']['free_days'] ?? 0);
            if ($freeDays > 0) {
                $items[] = [
                    'icon' => '🎁',
                    'badge' => 'สมัครฟรี',
                    'text' => 'สมัครใหม่วันนี้ รับ Premium ฟรี '.self::human($freeDays).'!',
                    'link' => $guest ? route('register') : route('account'),
                ];
            }

            $ref = $cfg['referral'] ?? [];
            $refDays = (int) ($ref['referee_pro_days'] ?? 0);
            if (($ref['enabled'] ?? false) && $refDays > 0) {
                $items[] = [
                    'icon' => '👥',
                    'badge' => 'ชวนเพื่อน',
                    'text' => 'ชวนเพื่อนสมัคร รับ Premium ฟรี '.self::human($refDays).' ต่อคน',
                    'link' => $guest ? route('register') : route('account'),
                ];
            }
        } catch (\Throwable $e) {
            // config unavailable → just fall through to announcements
        }

        try {
            foreach (Announcement::active()->get() as $a) {
                $items[] = [
                    'icon' => $a->badge ?: '📢',
                    'badge' => $a->badge ?: 'ข่าว',
                    'text' => (string) $a->body,
                    'link' => $a->link ?: '#',
                ];
            }
        } catch (\Throwable $e) {
            // table missing → ignore
        }

        return $items;
    }

    /** Render a day-count as the nicest Thai unit (365→"1 ปี", 30→"1 เดือน", 7→"1 สัปดาห์"). */
    private static function human(int $days): string
    {
        if ($days >= 365 && $days % 365 === 0) {
            return ($days / 365).' ปี';
        }
        if ($days >= 30 && $days % 30 === 0) {
            return ($days / 30).' เดือน';
        }
        if ($days >= 7 && $days % 7 === 0) {
            return ($days / 7).' สัปดาห์';
        }

        return $days.' วัน';
    }
}
