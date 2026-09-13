<?php

namespace App\Support;

use App\Models\Setting;
use App\Support\Alerts\Alert;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The LINE Official Account channel of [AdminAlerts]: pushes the alert as plain text.
 *
 * LINE was the first channel (24-hdx went down site-wide on 2026-07-28 and nothing told anybody).
 * Its push messages are metered — past the OA plan's free quota each one costs money — which is why
 * Telegram now sits next to it and either can be switched off on its own.
 *
 * Config in Settings: `line_alerts_enabled`, `line_oa_token` (SECRET — encrypted at rest, like every
 * other channel token here) and `line_oa_to` (the admin's userId, or a groupId).
 */
class LineNotifier
{
    private const PUSH_URL = 'https://api.line.me/v2/bot/message/push';

    /** LINE hard-caps a text message at 5000 chars; stay well clear and keep it readable on a phone. */
    private const MAX_CHARS = 1500;

    public static function enabled(): bool
    {
        return Setting::flag('line_alerts_enabled', false) && self::configured();
    }

    public static function configured(): bool
    {
        return self::token() !== '' && self::target() !== '';
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
     * Push one alert as text. Never throws: an alerting failure must not take down whatever was
     * reporting. (The token travels in a header, so unlike Telegram's it can't leak via a URL.)
     *
     * @return string|null null when delivered, otherwise a short reason
     */
    public static function deliver(Alert $alert): ?string
    {
        try {
            $resp = Http::withToken(self::token())
                ->connectTimeout(5)->timeout(12)
                ->post(self::PUSH_URL, [
                    'to' => self::target(),
                    'messages' => [[
                        'type' => 'text',
                        'text' => mb_substr($alert->toText(), 0, self::MAX_CHARS),
                    ]],
                ]);

            if ($resp->successful()) {
                return null;
            }
            Log::warning('line-alert: push failed', [
                'status' => $resp->status(),
                'body' => mb_substr($resp->body(), 0, 300),
            ]);

            return match ($resp->status()) {
                // The failure this whole second channel exists for: the month's free pushes are gone.
                429 => 'โควตาข้อความ LINE ของเดือนนี้หมดแล้ว หรือส่งถี่เกินไป — ใช้ Telegram แทนได้ฟรี',
                401 => 'Token ของ LINE ไม่ถูกต้อง หรือถูกออกใหม่แล้ว',
                default => 'HTTP '.$resp->status().' '.mb_substr($resp->body(), 0, 180),
            };
        } catch (Throwable $e) {
            Log::warning('line-alert: push threw', ['error' => $e->getMessage()]);

            return mb_substr($e->getMessage(), 0, 200);
        }
    }
}
