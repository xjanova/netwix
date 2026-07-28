<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;

/**
 * The gate an advertiser's destination URL must pass BEFORE they are shown a payment QR. Owner's
 * rule: "เราต้องมีระบบกรองเบื้องต้น ก่อนลูกค้าจะโอนก่อนด้วย" — because the money is non-refundable, it
 * would be indefensible to take it and only then discover the link was never going to be approved.
 *
 * What it checks, in order of how cheaply it can say no:
 *   1. the URL is a plain public http(s) address — no javascript:/data:, no credentials in the URL,
 *      no IP literals or internal hosts (that last one is an SSRF guard, since we fetch the page);
 *   2. it is not a shortener or redirect-hider — the owner requires links that "ตรงไปเว็บเลย";
 *   3. following it lands somewhere sane: few hops, no bouncing between hosts, ends in 2xx;
 *   4. neither the URL, the final URL, nor the destination page's own text matches the blocklist
 *      (gambling and adult in both Thai and English, plus anything the admin adds).
 *
 * A verdict is advisory-but-blocking: `ok=false` stops checkout with a reason the buyer can act on.
 * Passing is NOT approval — a human still reviews the creative after payment. This only exists so
 * that the obvious refusals happen while the buyer can still walk away with their money.
 */
class AdScreening
{
    private const UA = 'Mozilla/5.0 (compatible; NetWixAdCheck/1.0; +https://netwix.online)';

    /** Link shorteners and redirect services — the owner's "ห้ามผ่านเว็บต้องสงสัย" in practice. */
    private const SHORTENERS = [
        'bit.ly', 'tinyurl.com', 'goo.gl', 't.co', 'ow.ly', 'is.gd', 'buff.ly', 'cutt.ly',
        'shorturl.at', 'rebrand.ly', 'rb.gy', 's.id', 'linktr.ee', 'lnk.to', 'shorte.st',
        'adf.ly', 'bc.vc', 'ouo.io', 'exe.io', 'gplinks.co', 'clk.sh', 'za.gl', 'short.gy',
    ];

    /** Categories the owner refuses outright: gambling and adult, Thai + English. */
    private const BLOCK_KEYWORDS = [
        // gambling
        'คาสิโน', 'บาคาร่า', 'สล็อต', 'พนัน', 'แทงบอล', 'หวย', 'ยิงปลา', 'เดิมพัน', 'เว็บตรง',
        'casino', 'baccarat', 'roulette', 'sbobet', 'ufabet', 'pgslot', 'slotxo', 'betting',
        'sportsbook', 'jackpot', 'gclub', 'huay', 'lottery', 'poker',
        // adult
        'โป๊', 'ลามก', 'หนังxxx', 'คลิปหลุด', 'เย็ด', 'หีี',
        'porn', 'xxx', 'hentai', 'sexcam', 'camgirl', 'escort', 'onlyfans', 'nudes',
    ];

    /** Max redirects to follow before calling the destination evasive. */
    private const MAX_HOPS = 3;

    /**
     * Screen a destination URL.
     *
     * @return array{ok:bool,reason:?string,final_url:?string,hops:int,checked_at:string}
     */
    public function check(string $url): array
    {
        $url = trim($url);

        if (($why = $this->staticProblem($url)) !== null) {
            return $this->verdict(false, $why);
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($this->isShortener($host)) {
            return $this->verdict(false, 'ไม่รับลิงก์ย่อ/ลิงก์เปลี่ยนเส้นทาง ('.$host.') — ต้องเป็นลิงก์ตรงไปเว็บของคุณ');
        }
        if (($hit = $this->blockedTerm($url)) !== null) {
            return $this->verdict(false, 'ลิงก์มีคำที่ไม่อนุญาต: "'.$hit.'"');
        }

        // Follow it ourselves. A destination that will not resolve for us will not resolve for a
        // viewer either, and the hop trail is where cloaking shows up.
        [$final, $hops, $body, $error] = $this->trace($url);

        if ($error !== null) {
            return $this->verdict(false, $error, $final, $hops);
        }
        if ($hops > self::MAX_HOPS) {
            return $this->verdict(false, 'ลิงก์เด้งต่อหลายทอดเกินไป ('.$hops.' ครั้ง) — ต้องเป็นลิงก์ตรง', $final, $hops);
        }
        if (($hit = $this->blockedTerm($final)) !== null) {
            return $this->verdict(false, 'ปลายทางมีคำที่ไม่อนุญาต: "'.$hit.'"', $final, $hops);
        }
        if ($body !== '' && ($hit = $this->blockedTerm($body)) !== null) {
            return $this->verdict(false, 'เนื้อหาปลายทางเข้าข่ายเว็บพนัน/ผู้ใหญ่ (พบคำว่า "'.$hit.'")', $final, $hops);
        }

        return $this->verdict(true, null, $final, $hops);
    }

    /** Cheap structural rejections — no network needed. Returns a reason, or null when fine. */
    private function staticProblem(string $url): ?string
    {
        if ($url === '' || ! preg_match('~^https?://~i', $url)) {
            return 'ลิงก์ต้องขึ้นต้นด้วย http:// หรือ https://';
        }
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return 'รูปแบบลิงก์ไม่ถูกต้อง';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'ลิงก์ต้องไม่มีชื่อผู้ใช้/รหัสผ่านฝังอยู่';
        }

        $host = strtolower($parts['host']);

        // Never fetch something that resolves inward — this class makes an outbound request with a
        // user-supplied URL, which is textbook SSRF if internal targets are allowed.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return 'ลิงก์ต้องเป็นชื่อโดเมน ไม่ใช่เลข IP';
        }
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return 'ลิงก์ปลายทางไม่ถูกต้อง';
        }
        if (! str_contains($host, '.')) {
            return 'รูปแบบโดเมนไม่ถูกต้อง';
        }

        return null;
    }

    /**
     * Walk the redirect chain by hand rather than letting the client follow it, so each hop can be
     * inspected and an internal address can be refused mid-chain (a public URL is free to redirect to
     * 127.0.0.1, which is how an SSRF slips past a check that only looked at the first URL).
     *
     * @return array{0:string,1:int,2:string,3:?string} [finalUrl, hops, body, error]
     */
    private function trace(string $url): array
    {
        $current = $url;
        $hops = 0;

        for ($i = 0; $i <= self::MAX_HOPS; $i++) {
            if (($why = $this->staticProblem($current)) !== null) {
                return [$current, $hops, '', 'ปลายทางระหว่างทางไม่ถูกต้อง'];
            }
            try {
                $resp = Http::withHeaders(['User-Agent' => self::UA, 'Accept-Language' => 'th,en;q=0.8'])
                    ->withoutRedirecting()->connectTimeout(8)->timeout(15)->get($current);
            } catch (\Throwable) {
                return [$current, $hops, '', 'เปิดลิงก์ปลายทางไม่ได้ — ตรวจสอบว่าเว็บเปิดใช้งานอยู่'];
            }

            if ($resp->redirect()) {
                $next = (string) $resp->header('Location');
                if ($next === '') {
                    return [$current, $hops, '', 'ลิงก์เปลี่ยนเส้นทางแบบไม่ระบุปลายทาง'];
                }
                $current = $this->absolute($next, $current);
                $hops++;

                continue;
            }

            if ($resp->status() >= 400) {
                return [$current, $hops, '', 'ปลายทางตอบกลับ HTTP '.$resp->status().' — เว็บต้องเปิดได้ปกติ'];
            }

            // Only the first slice matters; a blocklist hit will be in the visible copy, and reading a
            // whole page of an untrusted host is needless.
            return [$current, $hops, mb_substr(strip_tags($resp->body()), 0, 20000), null];
        }

        return [$current, $hops, '', 'ลิงก์เด้งต่อหลายทอดเกินไป — ต้องเป็นลิงก์ตรง'];
    }

    private function isShortener(string $host): bool
    {
        $host = preg_replace('~^www\.~', '', $host) ?? $host;

        return in_array($host, array_merge(self::SHORTENERS, $this->adminList('ad_block_domains')), true);
    }

    /** First blocked term found in a haystack, or null. Case-insensitive; Thai needs no word bounds. */
    private function blockedTerm(string $haystack): ?string
    {
        $hay = mb_strtolower($haystack, 'UTF-8');
        foreach (array_merge(self::BLOCK_KEYWORDS, $this->adminList('ad_block_keywords')) as $term) {
            if ($term !== '' && str_contains($hay, mb_strtolower($term, 'UTF-8'))) {
                return $term;
            }
        }

        return null;
    }

    /** Admin-extendable list (comma or newline separated) so new scam patterns need no deploy. */
    private function adminList(string $key): array
    {
        $raw = (string) Setting::get($key, '');

        return array_values(array_filter(array_map(
            fn ($s) => strtolower(trim($s)),
            preg_split('~[\n,]+~', $raw) ?: [],
        )));
    }

    private function absolute(string $uri, string $base): string
    {
        if (preg_match('~^https?://~i', $uri)) {
            return $uri;
        }
        $p = parse_url($base);
        $origin = ($p['scheme'] ?? 'https').'://'.($p['host'] ?? '');

        return str_starts_with($uri, '/') ? $origin.$uri : $origin.'/'.ltrim($uri, '/');
    }

    /** @return array{ok:bool,reason:?string,final_url:?string,hops:int,checked_at:string} */
    private function verdict(bool $ok, ?string $reason, ?string $final = null, int $hops = 0): array
    {
        return [
            'ok' => $ok,
            'reason' => $reason,
            'final_url' => $final,
            'hops' => $hops,
            'checked_at' => now()->toIso8601String(),
        ];
    }
}
