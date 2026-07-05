<?php

namespace App\Support;

/**
 * Pulls the plot synopsis out of a movie/series detail page for the Thai WordPress sources
 * (24-hdx / anime108 / wow-drama) whose WP REST feed carries no synopsis. The plot is the longest
 * Thai text block on the page once the site's promo/SEO boilerplate (present on every page) is
 * filtered out — returns null when a page genuinely has only promo text.
 */
class SynopsisScraper
{
    /** Promo / nav / SEO phrases that mark a text block as boilerplate, not a plot. */
    private const BOILERPLATE = '/ดูหนังฟรี|ไม่มีโฆ|Smart ?TV|ดูหนังออนไลน์|ดูอนิเมะ|ดูซีรี|ตัวอย่าง|24-?HD|WOW-?DRAMA|ANIME108|มีให้คุณเลือกชม|คอซีรี่ย์|หาเว็บไซต์|ดาวน์โหลด|เว็บอนิเมะ|เลือกดูครบจบ|เติมเงิน|สมัครสมาชิก|โหลดเร็ว|รับชมได้ฟรี|เลือกชมได้|internet|Bilibili|Netflix ไม่ต้อง/iu';

    public static function fromHtml(string $html): ?string
    {
        $h = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        if (! preg_match_all('/>([^<]{60,})</u', $h, $m)) {
            return null;
        }

        $best = null;
        $bestLen = 0;
        foreach ($m[1] as $raw) {
            $s = trim(preg_replace('/\s+/', ' ', html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $len = mb_strlen($s);
            if ($len < 60 || ! preg_match('/[\x{0E00}-\x{0E7F}]/u', $s)) {
                continue; // must be a real Thai text block
            }
            if (preg_match(self::BOILERPLATE, $s)) {
                continue; // site promo / nav / SEO — not a plot
            }
            if ($len > $bestLen) {
                $best = $s;
                $bestLen = $len;
            }
        }

        return $best !== null ? mb_substr($best, 0, 1500) : null;
    }
}
