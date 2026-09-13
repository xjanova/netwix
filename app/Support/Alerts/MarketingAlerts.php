<?php

namespace App\Support\Alerts;

use App\Support\AdminAlerts;
use Throwable;

/**
 * Facebook posting alerts for the clip campaign engine.
 *
 * Both failures here are silent by construction: a campaign keeps "posting" into a disconnected page
 * as a dry run (it records honestly, but nobody is told), and an expired page token turns every slot
 * into a failed row on an admin page nobody is looking at. Facebook tokens DO expire — a password
 * change or a security check at Facebook is enough — so this is a when, not an if.
 */
final class MarketingAlerts
{
    public static function postFailed(string $clipLabel, string $error): void
    {
        try {
            $expired = (bool) preg_match('/access token|oauth|session has expired|session has been invalidated|"code"\s*:\s*190|\b190\b/i', $error);

            AdminAlerts::send(new Alert(
                key: $expired ? 'fb-token-expired' : 'fb-post-failed',
                level: $expired ? Alert::CRITICAL : Alert::WARNING,
                title: $expired ? 'Token เพจ Facebook หมดอายุ — ต้องเชื่อมต่อใหม่' : 'โพสต์คลิปลงเพจ Facebook ไม่สำเร็จ',
                body: 'Facebook ตอบว่า: '.mb_substr(Redact::text($error), 0, 300)
                    .($expired
                        ? "\nคลิปทุกช่วงเวลาจะโพสต์ไม่ขึ้นจนกว่าจะกดเชื่อมเพจใหม่ในหน้าแคมเปญคลิป"
                        : "\nระบบจะลองใหม่เอง ถ้ายังไม่ผ่านจะข้ามไปเรื่องถัดไป"),
                facts: ['คลิป' => mb_substr($clipLabel, 0, 40)],
                url: url('/admin/clip-campaigns'),
                urlLabel: 'เปิดหน้าแคมเปญคลิป',
                category: 'marketing',
            ), $expired ? 720 : 360);
        } catch (Throwable) {
        }
    }

    /** A scheduled campaign slot fired, but no page is connected — it went nowhere. */
    public static function notConnected(): void
    {
        try {
            AdminAlerts::send(new Alert(
                key: 'fb-not-connected',
                level: Alert::WARNING,
                title: 'แคมเปญคลิปกำลังรัน แต่ยังไม่ได้เชื่อมเพจ Facebook',
                body: 'คลิปถูกตัดและถึงเวลาโพสต์แล้ว แต่ไม่ได้ขึ้นเพจจริง (บันทึกเป็นโหมดทดลอง) — เชื่อมเพจก่อน หรือปิดแคมเปญไว้',
                url: url('/admin/clip-campaigns'),
                urlLabel: 'เปิดหน้าแคมเปญคลิป',
                category: 'marketing',
            ), 1440);
        } catch (Throwable) {
        }
    }
}
