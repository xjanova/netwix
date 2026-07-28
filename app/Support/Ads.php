<?php

namespace App\Support;

use App\Models\Content;
use App\Models\HouseBanner;
use App\Models\Setting;
use App\Models\User;

/**
 * Google ad placements — AdSense on the web, AdMob in the app. One place decides whether a given
 * viewer sees a given slot, so the answer can't drift between the layouts, the page views and the
 * app's config endpoint.
 *
 * Distinct from [App\Models\AdCampaign], which is the OWNER's own pre-roll creatives on the player.
 * These are network-served banners in the page furniture. Both honour the same rule: **a Pro member
 * never sees an ad** ("ใครใช้โปร จะไม่เห็น ads").
 *
 * Deliberately provider-agnostic. Each slot holds either an AdSense ad-unit id (rendered as a normal
 * <ins class="adsbygoogle">) or a raw HTML snippet from any other network, because a film-streaming
 * catalogue is a poor fit for AdSense's content policy and being rejected should cost a settings
 * change, not a rewrite. Adult (18+/20+) pages are refused outright by [self::allowedFor] — that's a
 * flat policy breach for AdSense and the fastest way to lose an account.
 */
class Ads
{
    /** The named placements. Adding one = a Setting key + an <x-ad-slot> in a view. */
    public const SLOTS = ['header', 'infeed', 'sidebar', 'footer'];

    /** AdSense publisher ids look like this and nothing else — the guard against injecting junk. */
    private const PUB_ID = '~^ca-pub-\d{10,20}$~';

    // ------------------------------------------------------------------ web

    /** Master switch AND a usable publisher id / custom snippet — otherwise nothing renders at all. */
    public static function enabled(): bool
    {
        return Setting::flag('ads_enabled', false);
    }

    /** Validated AdSense publisher id, or null. Never interpolate the raw setting anywhere else. */
    public static function clientId(): ?string
    {
        $id = trim((string) Setting::get('adsense_client_id', ''));
        if ($id !== '' && ! str_starts_with($id, 'ca-')) {
            $id = 'ca-'.ltrim($id, '-');    // accept a bare "pub-…" paste
        }

        return preg_match(self::PUB_ID, $id) ? $id : null;
    }

    /** AdSense ad-unit id for a slot (digits), or null when that slot isn't configured. */
    public static function unit(string $slot): ?string
    {
        $v = trim((string) Setting::get('adsense_slot_'.$slot, ''));

        return preg_match('~^\d{6,20}$~', $v) ? $v : null;
    }

    /**
     * Raw ad HTML for a slot from a NON-AdSense network. Stored by an admin and rendered unescaped, so
     * it is exactly as trusted as the admin who pasted it — the same trust level as the existing
     * `custom_code_head` setting. Only reachable behind the admin auth gate.
     */
    public static function customHtml(string $slot): ?string
    {
        $v = trim((string) Setting::get('ads_custom_'.$slot, ''));

        return $v !== '' ? $v : null;
    }

    /**
     * Should this viewer see ads at all?
     *  - Pro members never do (that's what they paid for),
     *  - and neither does anyone on an adult title, whatever their plan.
     *
     * $content is the title being viewed, when the page is about one. It's typed loosely because the
     * layouts pass whatever the page happens to call `$content`, which is not always a Content —
     * anything else is simply treated as "not a title page" rather than blowing up a page render.
     */
    public static function allowedFor(?User $user = null, mixed $content = null): bool
    {
        if (! self::enabled()) {
            return false;
        }
        if ($user?->isProMember()) {
            return false;
        }
        if ($content instanceof Content && ($content->is_adult || $content->is_vip)) {
            return false;   // AdSense policy — and an account ban is not worth one impression
        }

        return true;
    }

    /**
     * Everything a slot needs to render, or null when it shouldn't.
     *
     * Order of preference:
     *   1. a per-slot custom snippet (an admin pasted another network's code — they meant it),
     *   2. otherwise AdSense or a HOUSE banner, split by `house_ads_fill`,
     *   3. and either one falls back to the other rather than leaving the slot empty.
     *
     * The fill share is what makes house banners more than a stopgap: at 0 they only appear when no
     * network unit is configured, at 100 they take the slot outright, and in between the owner can run
     * their own promos alongside paid inventory without touching code.
     */
    public static function slot(string $slot, ?User $user = null, mixed $content = null): ?array
    {
        if (! in_array($slot, self::SLOTS, true) || ! self::allowedFor($user, $content)) {
            return null;
        }

        if (($html = self::customHtml($slot)) !== null) {
            return ['kind' => 'custom', 'html' => $html];
        }

        $client = self::clientId();
        $unit = self::unit($slot);
        $network = ($client && $unit) ? ['kind' => 'adsense', 'client' => $client, 'unit' => $unit] : null;

        // TODO(ad-marketplace): a PAID booking will take the slot `ad_paid_share` percent of the time
        // (owner: "แบ่งกันตามเปอร์เซ็นต์ที่ตั้ง"). Deliberately NOT wired yet — ad_bookings does not
        // exist on production, and this method runs on every page render, so a half-finished branch
        // here is a site-wide 500 rather than a missing feature.

        // No network unit for this slot → the house banner is the only candidate. Otherwise it gets
        // the slot `house_ads_fill` percent of the time. Picked at most ONCE: in rotate mode the pick
        // advances a cursor, so calling it twice would skip a banner in the cycle.
        $wantHouse = $network === null || random_int(1, 100) <= self::houseFill();
        $house = $wantHouse ? self::houseSlot($slot) : null;

        return $house ?? $network ?? ($wantHouse ? null : self::houseSlot($slot));
    }

    /** Percentage of impressions house banners take when a network unit IS configured (0–100). */
    public static function houseFill(): int
    {
        return max(0, min(100, (int) Setting::get('house_ads_fill', 0)));
    }

    /** @return array{kind:string,id:int,src:string,link:?string,name:?string}|null */
    private static function houseSlot(string $slot): ?array
    {
        $b = HouseBanner::pickFor($slot);

        return $b ? ['kind' => 'house'] + $b : null;
    }

    /** The app needs an absolute URL — Storage::url is site-relative on the local disk. */
    private static function absolute(string $src): string
    {
        return str_starts_with($src, '/') ? rtrim((string) config('app.url'), '/').$src : $src;
    }

    // ------------------------------------------------------------------ app

    /**
     * AdMob configuration for the mobile app (GET /api/app/ads/config). The app renders nothing unless
     * `show_ads` is true, and that decision is made HERE rather than in the client — a Pro member's
     * ad-free experience must not depend on the app being up to date or honest.
     *
     * @return array<string,mixed>
     */
    public static function appConfig(?User $user = null): array
    {
        // House banners are the app's fallback too: AdMob may be unconfigured or simply unfilled, and
        // the app should still have something to put in the slot. They're gated on the same Pro rule.
        $pro = (bool) $user?->isProMember();
        $on = Setting::flag('admob_enabled', false) && ! $pro;
        $house = (! $pro && self::enabled()) ? HouseBanner::pickFor('header') : null;

        return [
            'show_ads' => $on,
            // Shown when show_ads is false-but-not-Pro, or when AdMob returns no fill.
            'house_banner' => $house ? [
                'image' => self::absolute($house['src']),
                'link' => $house['link'],
                'name' => $house['name'],
            ] : null,
            'android_app_id' => $on ? self::admobId('admob_android_app_id') : null,
            'ios_app_id' => $on ? self::admobId('admob_ios_app_id') : null,
            'units' => [
                'banner' => $on ? self::admobId('admob_unit_banner') : null,
                'interstitial' => $on ? self::admobId('admob_unit_interstitial') : null,
                'native' => $on ? self::admobId('admob_unit_native') : null,
                'rewarded' => $on ? self::admobId('admob_unit_rewarded') : null,
            ],
            // How often the app may show a full-screen interstitial, so pacing is tunable
            // server-side without shipping an app update.
            'interstitial_every_minutes' => max(0, (int) Setting::get('admob_interstitial_minutes', 8)),
        ];
    }

    /**
     * AdMob ids are "ca-app-pub-<digits>~<digits>" (app) or "…/<digits>" (unit); anything else is
     * dropped. Delimiter is # rather than ~ on purpose: the separator "~" is part of the id itself and
     * inside the character class it would close the pattern early ("Unknown modifier '/'"), silently
     * matching nothing — which reads exactly like "the admin hasn't configured AdMob yet".
     */
    private static function admobId(string $key): ?string
    {
        $v = trim((string) Setting::get($key, ''));

        return preg_match('#^ca-app-pub-\d{10,20}[~/]\d{6,20}$#', $v) ? $v : null;
    }
}
