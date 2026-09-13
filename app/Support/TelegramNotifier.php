<?php

namespace App\Support;

use App\Models\Setting;
use App\Support\Alerts\Alert;
use App\Support\Alerts\AlertCard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The Telegram channel of [AdminAlerts]. Added because LINE's push quota is metered — past the free
 * messages every alert costs money — while a Telegram bot is free and unmetered.
 *
 * Each alert goes out as a photo: the drawn [AlertCard], an HTML caption with the same facts in
 * words, and a button to the admin page. Scanner-noise (INFO) arrives silently. If the card can't
 * be drawn, or Telegram refuses the photo, the alert goes again as plain text — the alert matters
 * more than the picture.
 *
 * THE TOKEN IS IN EVERY URL ("/bot<token>/sendPhoto"). Guzzle's connection errors quote the full
 * URL, and error strings from here reach laravel.log and the admin page's history — so everything
 * that leaves this class goes through [self::redact] first.
 *
 * Config in Settings: `telegram_alerts_enabled`, `telegram_bot_token` (SECRET, encrypted at rest),
 * `telegram_chat_id` (a user id, a -100… group id, or @channel) and `telegram_bot_username` (shown
 * on the admin page so the owner can open the bot).
 */
class TelegramNotifier
{
    private const API = 'https://api.telegram.org';

    /** Telegram's limits are 1024 (caption) and 4096 (message) — stay clear, emoji count double. */
    private const CAPTION_MAX = 1000;

    private const TEXT_MAX = 3800;

    public static function enabled(): bool
    {
        return Setting::flag('telegram_alerts_enabled', false) && self::configured();
    }

    public static function configured(): bool
    {
        return self::token() !== '' && self::chat() !== '';
    }

    private static function token(): string
    {
        return trim((string) Setting::get('telegram_bot_token', ''));
    }

    private static function chat(): string
    {
        return trim((string) Setting::get('telegram_chat_id', ''));
    }

    /**
     * Deliver one alert. Never throws: an alerting failure must not take down whatever was reporting.
     *
     * @return string|null null when delivered, otherwise a short reason (token-free)
     */
    public static function deliver(Alert $alert): ?string
    {
        try {
            return self::send($alert, retryMigrated: true);
        } catch (Throwable $e) {
            $reason = self::redact($e->getMessage());
            Log::warning('telegram-alert: send threw', ['error' => $reason]);

            return mb_substr($reason, 0, 200);
        }
    }

    private static function send(Alert $alert, bool $retryMigrated): ?string
    {
        $keyboard = self::keyboard($alert);
        $png = AlertCard::png($alert);

        if ($png !== null) {
            $res = self::call('sendPhoto', [
                'chat_id' => self::chat(),
                'caption' => self::caption($alert, $keyboard === null, self::CAPTION_MAX),
                'parse_mode' => 'HTML',
                'disable_notification' => $alert->level === Alert::INFO ? 'true' : 'false',
                'reply_markup' => $keyboard !== null ? json_encode($keyboard) : null,
            ], $png);

            if ($res['ok']) {
                return null;
            }
            if ($retryMigrated && self::followMigration($res)) {
                return self::send($alert, retryMigrated: false);
            }
            if (self::isFatal($res)) {
                return self::explain($res);
            }
            // Something about the photo itself was refused — say it in words instead.
            Log::info('telegram-alert: photo refused, falling back to text', ['desc' => $res['desc']]);
        }

        $text = [
            'chat_id' => self::chat(),
            'text' => self::caption($alert, true, self::TEXT_MAX),
            'parse_mode' => 'HTML',
            'disable_notification' => $alert->level === Alert::INFO ? 'true' : 'false',
            'link_preview_options' => json_encode(['is_disabled' => true]),
        ];
        $res = self::call('sendMessage', $text);

        if (! $res['ok'] && $retryMigrated && self::followMigration($res)) {
            return self::send($alert, retryMigrated: false);
        }
        // Last resort: if Telegram could not parse our HTML, the words still matter — send them bare.
        if (! $res['ok'] && str_contains(strtolower($res['desc']), 'entities')) {
            $res = self::call('sendMessage', ['text' => mb_substr($alert->toText(), 0, self::TEXT_MAX), 'parse_mode' => null] + $text);
        }

        return $res['ok'] ? null : self::explain($res);
    }

    // ---------------------------------------------------------------------- setup helpers

    /**
     * Ask Telegram who a token belongs to (getMe) — used when the admin saves a token, so a typo is
     * caught on the spot instead of at the first real outage.
     *
     * @return array{ok:bool,reachable:bool,username:?string,name:?string,error:?string}
     */
    public static function identify(string $token): array
    {
        try {
            $res = self::call('getMe', [], null, $token);
        } catch (Throwable $e) {
            return ['ok' => false, 'reachable' => false, 'username' => null, 'name' => null,
                'error' => 'ต่อ Telegram ไม่ได้ในตอนนี้ ('.mb_substr(self::redact($e->getMessage(), $token), 0, 120).')'];
        }

        return $res['ok']
            ? ['ok' => true, 'reachable' => true, 'username' => $res['result']['username'] ?? null, 'name' => $res['result']['first_name'] ?? null, 'error' => null]
            : ['ok' => false, 'reachable' => true, 'username' => null, 'name' => null, 'error' => self::explain($res)];
    }

    /**
     * The chats that have talked to the bot recently (getUpdates) — so the owner never has to dig a
     * numeric chat id out of Telegram by hand: press Start with the bot, then pick it from a list.
     * Read-only: without an offset, getUpdates does not consume anything.
     *
     * @return array{chats:array<int,array{id:string,type:string,title:string}>,error:?string}
     */
    public static function discoverChats(): array
    {
        if (self::token() === '') {
            return ['chats' => [], 'error' => 'ยังไม่ได้ใส่ Bot Token'];
        }
        try {
            $res = self::call('getUpdates', ['limit' => 100, 'timeout' => 0]);
        } catch (Throwable $e) {
            return ['chats' => [], 'error' => 'ต่อ Telegram ไม่ได้ในตอนนี้ ('.mb_substr(self::redact($e->getMessage()), 0, 120).')'];
        }
        if (! $res['ok']) {
            return ['chats' => [], 'error' => $res['status'] === 409
                ? 'บอทนี้ตั้ง webhook ไว้กับระบบอื่นอยู่ จึงค้นหาอัตโนมัติไม่ได้ — ใส่ Chat ID เอง หรือสร้างบอทใหม่สำหรับแจ้งเตือนโดยเฉพาะ'
                : self::explain($res)];
        }

        $chats = [];
        foreach ((array) $res['result'] as $update) {
            foreach (['message', 'edited_message', 'channel_post', 'edited_channel_post', 'my_chat_member', 'chat_member', 'chat_join_request'] as $kind) {
                $chat = $update[$kind]['chat'] ?? null;
                if (! is_array($chat) || ! isset($chat['id'])) {
                    continue;
                }
                $name = trim((string) ($chat['title'] ?? trim(($chat['first_name'] ?? '').' '.($chat['last_name'] ?? ''))));
                $id = (string) $chat['id'];
                unset($chats[$id]);     // re-insert so the newest activity ends up last
                $chats[$id] = [
                    'id' => $id,
                    'type' => (string) ($chat['type'] ?? ''),
                    'title' => $name !== '' ? $name : '@'.($chat['username'] ?? $id),
                ];
            }
        }

        if ($chats === []) {
            return ['chats' => [], 'error' => 'ยังไม่พบแชทที่คุยกับบอท — เปิดแชทกับบอทแล้วกด Start (หรือเพิ่มบอทเข้ากลุ่ม) แล้วกดค้นหาอีกครั้ง'];
        }

        return ['chats' => array_reverse(array_values($chats)), 'error' => null];
    }

    // ---------------------------------------------------------------------- transport

    /**
     * One Bot API call. Null params are dropped; everything else is sent as a string field, which
     * is what a multipart request needs and what Telegram accepts either way.
     *
     * @return array{ok:bool,status:int,desc:string,result:mixed,params:array}
     */
    private static function call(string $method, array $params, ?string $photo = null, ?string $token = null): array
    {
        $params = array_map('strval', array_filter($params, fn ($v) => $v !== null));
        $request = Http::connectTimeout(5)->timeout($photo !== null ? 25 : 12);
        if ($photo !== null) {
            $request = $request->attach('photo', $photo, 'netwix-alert.png', ['Content-Type' => 'image/png']);
        } else {
            $request = $request->asForm();
        }

        $resp = $request->post(self::API.'/bot'.($token ?? self::token()).'/'.$method, $params);
        $json = $resp->json();
        $ok = $resp->successful() && is_array($json) && ($json['ok'] ?? false) === true;

        return [
            'ok' => $ok,
            'status' => $resp->status(),
            'desc' => $ok ? '' : (string) (is_array($json) ? ($json['description'] ?? '') : mb_substr($resp->body(), 0, 200)),
            'result' => $ok ? ($json['result'] ?? []) : [],
            'params' => is_array($json) ? (array) ($json['parameters'] ?? []) : [],
        ];
    }

    /**
     * A group that gets upgraded to a supergroup changes its chat id, and Telegram answers the old
     * one with the new id attached. Follow it once and remember it, instead of going silent.
     */
    private static function followMigration(array $res): bool
    {
        $to = $res['params']['migrate_to_chat_id'] ?? null;
        if (! is_numeric($to)) {
            return false;
        }
        Setting::write('telegram_chat_id', (string) $to);
        Log::info('telegram-alert: chat migrated to a supergroup, chat id updated', ['to' => (string) $to]);

        return true;
    }

    /** Failures a text retry would only repeat: the token, the chat, or rate limiting. */
    private static function isFatal(array $res): bool
    {
        $d = strtolower($res['desc']);

        return in_array($res['status'], [401, 403, 404, 429], true)
            || str_contains($d, 'chat not found') || str_contains($d, 'upgraded');
    }

    /** A short Thai reason for the admin page, never the raw API text alone. */
    private static function explain(array $res): string
    {
        $d = strtolower($res['desc']);

        return match (true) {
            in_array($res['status'], [401, 404], true) => 'Token ไม่ถูกต้อง หรือบอทถูกลบไปแล้ว — ขอ Token ใหม่จาก @BotFather',
            str_contains($d, 'chat not found') => 'ไม่พบแชทปลายทาง — ต้องกด Start ในแชทกับบอทก่อน (หรือเพิ่มบอทเข้ากลุ่ม) แล้วตรวจ Chat ID อีกครั้ง',
            str_contains($d, 'blocked by the user') => 'บอทถูกบล็อกอยู่ — เปิดแชทกับบอทแล้วกด Restart',
            str_contains($d, 'not a member'), str_contains($d, 'kicked'), str_contains($d, 'not enough rights') => 'บอทไม่ได้อยู่ในกลุ่ม/ช่องนี้ หรือไม่มีสิทธิ์ส่งข้อความ — เพิ่มบอทเข้าไป (ช่องต้องตั้งบอทเป็นแอดมิน)',
            str_contains($d, 'upgraded') => 'กลุ่มถูกอัปเกรดเป็น supergroup และ Chat ID เปลี่ยนแล้ว — กดค้นหา Chat ID ใหม่',
            $res['status'] === 429 => 'Telegram ให้รอสักครู่ (ส่งถี่เกินไป)',
            default => mb_substr(self::redact('HTTP '.$res['status'].' '.$res['desc']), 0, 200),
        };
    }

    /** Strip the bot token out of anything that is about to be logged, stored or shown. */
    public static function redact(string $text, ?string $token = null): string
    {
        foreach (array_filter([$token, self::token()]) as $secret) {
            $text = str_replace($secret, '***', $text);
        }

        return preg_replace('~bot\d{5,}:[A-Za-z0-9_-]{20,}~', 'bot***', $text) ?? $text;
    }

    // ---------------------------------------------------------------------- message body

    /** HTML caption: headline, the facts as a list, then the explanation — trimmed to fit $max. */
    private static function caption(Alert $a, bool $withUrl, int $max): string
    {
        $e = fn (string $s): string => htmlspecialchars($s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $head = $a->emoji().' <b>'.$e($a->title).'</b>';
        $headPlain = $a->emoji().' '.$a->title;

        $facts = [];
        $factsPlain = '';
        foreach ($a->facts as $label => $value) {
            $facts[] = '• '.$e((string) $label).': <b>'.$e((string) $value).'</b>';
            $factsPlain .= '• '.$label.': '.$value."\n";
        }

        $url = $withUrl && $a->url ? $a->url : '';

        // Budget the body on VISIBLE characters — that is what Telegram counts, after tags.
        $room = $max - mb_strlen($headPlain.$factsPlain.$url) - 8;
        $body = trim($a->body);
        if (mb_strlen($body) > $room) {
            $body = rtrim(mb_substr($body, 0, max(0, $room - 1))).'…';
        }
        if ($body !== '') {
            // A long report (a ban's behaviour breakdown) collapses; a sentence or two stays open.
            $body = substr_count($body, "\n") >= 3
                ? '<blockquote expandable>'.$e($body).'</blockquote>'
                : $e($body);
        }

        return implode("\n\n", array_filter([
            $head,
            implode("\n", $facts),
            $body,
            $url !== '' ? $e($url) : '',
        ], fn ($part) => $part !== ''));
    }

    /** A button to the admin page — only for a URL Telegram will accept (not localhost/an IP). */
    private static function keyboard(Alert $a): ?array
    {
        if (! $a->url) {
            return null;
        }
        $host = (string) parse_url($a->url, PHP_URL_HOST);
        $scheme = (string) parse_url($a->url, PHP_URL_SCHEME);
        $public = in_array($scheme, ['http', 'https'], true) && str_contains($host, '.')
            && filter_var($host, FILTER_VALIDATE_IP) === false
            && ! preg_match('/\.(test|local|localhost)$/', $host);

        return $public ? ['inline_keyboard' => [[['text' => $a->urlLabel, 'url' => $a->url]]]] : null;
    }
}
