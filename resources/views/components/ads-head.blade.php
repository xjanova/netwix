@props(['content' => null])

{{--
    AdSense loader + site-verification meta, injected once per public page.

    @props is load-bearing, not decoration: without it `:content` would land in $attributes instead of
    binding a variable, `$content ?? null` would quietly be null on every page, and the loader would
    run on adult pages too — where AdSense Auto Ads could then inject units we never placed. That's
    the exact policy breach the slot-level guard exists to prevent.

    Renders NOTHING when ads are off, when no valid publisher id is configured, or for a viewer who
    shouldn't see ads at all (Pro member / adult page) — loading the script for someone who will never
    be shown a unit is both wasted bandwidth and a tracker they didn't agree to.

    The publisher id is regex-validated in [App\Support\Ads::clientId] before it reaches here, so it
    can only ever be `ca-pub-<digits>`.
--}}
{{-- Block form — see the note in ad-slot.blade.php. This sits in <head> on every page. --}}
@php
    $nxAdsClient = \App\Support\Ads::allowedFor(auth()->user(), $content ?? null)
        ? \App\Support\Ads::clientId()
        : null;
@endphp

@if ($nxAdsClient)
    <meta name="google-adsense-account" content="{{ $nxAdsClient }}">
    <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client={{ $nxAdsClient }}"
            crossorigin="anonymous"></script>
@endif
