@php
    // SEO: every page inherits sensible defaults; any view can override a slot
    // with @section('meta_description', '…') / @section('meta_image', '…') /
    // @section('meta_robots', 'noindex,nofollow') / @section('meta_canonical', '…').
    //
    // Every slot below is read back through $slot(), which un-escapes ONCE. Blade's value form
    // — @section('name', $value) — runs the value through e() before storing it, and then the
    // {{ }} that prints it here escapes it a second time. A synopsis containing a plain " came
    // out of that as &amp;quot;, and Google printed the entity, literally, in the snippet:
    //     content="เธอคือ&amp;quot;สะใภ้เลี้ยง&amp;quot;ผู้ถูกชะตาทอดทิ้ง…"
    // 1,669 published titles (8.4%) had a quote, & or apostrophe somewhere in their title or
    // synopsis and so carried a mangled description into search results. Undoing Laravel's
    // escape here leaves exactly one — the {{ }} below, which is the one that belongs to the
    // attribute we are writing into. Every meta_* section in this app uses the value form, so
    // there is nothing here that was not escaped on the way in.
    $slot = fn (string $name) => html_entity_decode(trim($__env->yieldContent($name)), ENT_QUOTES, 'UTF-8');
    $seoTitle = $slot('title') ?: 'สตรีมมิ่งไม่มีสะดุด';
    $seoDesc = $slot('meta_description')
        ?: 'NetWix — ดูหนัง ซีรีส์ และซีรีส์แนวตั้งออนไลน์ สตรีมไม่จำกัด ดูได้ทุกอุปกรณ์ ทั้งมือถือ แท็บเล็ต และทีวี ปลอดโฆษณาเว็บพนัน ไม่มีป๊อปอัปกวนใจ ไม่ใช่เว็บพนัน';
    $seoImage = $slot('meta_image') ?: asset('assets/netwix-logo-full.png');
    // Search Console verification code, pasted by the owner at /admin/seo. Accepts either the bare
    // token or the whole <meta> tag Google shows — people copy the tag, not the token.
    $googleVerification = trim((string) \App\Models\Setting::get('seo_google_verification', ''));
    if (preg_match('/content=["\']([^"\']+)["\']/', $googleVerification, $gsc)) {
        $googleVerification = trim($gsc[1]);
    }
    $seoRobots = $slot('meta_robots') ?: 'index,follow,max-image-preview:large,max-snippet:-1';
    // Canonical = the current URL, but ?page=N is kept: page 2+ of a hub/genre listing must be its
    // own canonical. Pointing it back at page 1 (which url()->current() alone does, since it drops
    // the query string) tells Google the deeper pages are duplicates and wastes the crawl path into
    // the catalog. Only `page` survives — tracking params must never end up in a canonical.
    $seoCanonical = $slot('meta_canonical');
    if ($seoCanonical === '') {
        $seoCanonical = url()->current();
        $seoPage = (int) request()->query('page', 1);
        if ($seoPage > 1) {
            $seoCanonical .= '?page='.$seoPage;
        }
    }
    $ogType = $slot('og_type') ?: 'website';
    $seoFullTitle = $seoTitle.' · NetWix';
    // Keyword set grounded in real Thai streaming search demand (both ซีรี่ย์/ซีรีส์ spellings
    // are searched heavily; include วาย, พากย์ไทย/ซับไทย, แนวตั้ง/โรงหยก, อนิเมะ). Google no longer
    // ranks on this tag, but Bing and Thai SEO tools still read it and it is harmless. A page can
    // override with @section('meta_keywords', '…') to lead with its own title/genre terms.
    // Priority: per-page @section('meta_keywords') → admin-editable Setting('seo_keywords') → default.
    $seoKeywords = $slot('meta_keywords')
        ?: (\App\Models\Setting::get('seo_keywords') ?: implode(', ', [
        'ดูหนังออนไลน์', 'ดูหนังออนไลน์ฟรี', 'ดูซีรี่ย์ออนไลน์', 'ดูซีรี่ย์ออนไลน์ฟรี', 'ดูซีรีส์ออนไลน์',
        'ซีรี่ย์เกาหลีซับไทย', 'ซีรี่ย์เกาหลีพากย์ไทย', 'ซีรี่ย์จีนซับไทย', 'ซีรี่ย์จีนพากย์ไทย',
        'ซีรี่ย์ไทย', 'ซีรี่ย์ฝรั่ง', 'ซีรี่ย์วาย', 'ซีรีส์แนวตั้ง', 'หนังสั้นจีน', 'ละครสั้น', 'โรงหยก',
        'มินิซีรีส์จีน', 'อนิเมะซับไทย', 'ดูอนิเมะ', 'ดูหนังฟรี', 'ดูซีรี่ย์ 2026', 'พากย์ไทย HD',
        'สตรีมมิ่ง', 'ดูหนังทุกอุปกรณ์', 'NetWix', 'เน็ตวิกซ์',
        'ดูหนังไม่มีโฆษณา', 'ดูหนังปลอดโฆษณาพนัน', 'ไม่ใช่เว็บพนัน',
    ]));
@endphp
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
{{-- On-demand cover heal: a card's poster <img> calls this from its onerror when the (hotlinked)
     cover fails to load. We ask the server to re-fetch + locally store it right then, and swap the
     healed URL in live; if there's no source cover, drop the <img> so the branded fallback shows.
     Self-contained (works for guests too) and defined in <head> so it exists before any img errors. --}}
<script>
window.nxHealCover = function (img, url) {
    if (!img) return;
    if (!url || img.dataset.nxHeal) { img.remove(); return; }   // no url / second failure → fallback
    img.dataset.nxHeal = '1';
    var token = document.querySelector('meta[name="csrf-token"]');
    fetch(url, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': token ? token.content : '', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) { if (d && d.url) { img.src = d.url; } else { img.remove(); } })
        .catch(function () { img.remove(); });
};
</script>

<title>{{ $seoFullTitle }}</title>
<meta name="description" content="{{ $seoDesc }}">
<meta name="keywords" content="{{ $seoKeywords }}">
<meta name="robots" content="{{ $seoRobots }}">
<meta name="theme-color" content="#07050c">
<link rel="canonical" href="{{ $seoCanonical }}">
@if ($googleVerification !== '')
{{-- Google Search Console ownership proof. Until this is set, Search Console cannot be opened for
     the site, which means the sitemap is never submitted — and the sitemap is the only way Google
     learns that 18,000+ title pages exist. Googlebot has never once fetched it on its own; bingbot
     reads it many times a day. Editable at /admin/seo so verifying needs no deploy. --}}
<meta name="google-site-verification" content="{{ $googleVerification }}">
@endif

{{-- Open Graph (Facebook, LINE, Messenger link previews) --}}
<meta property="og:type" content="{{ $ogType }}">
<meta property="og:site_name" content="NetWix">
<meta property="og:locale" content="th_TH">
<meta property="og:title" content="{{ $seoFullTitle }}">
<meta property="og:description" content="{{ $seoDesc }}">
<meta property="og:url" content="{{ $seoCanonical }}">
<meta property="og:image" content="{{ $seoImage }}">

{{-- Twitter / X card --}}
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $seoFullTitle }}">
<meta name="twitter:description" content="{{ $seoDesc }}">
<meta name="twitter:image" content="{{ $seoImage }}">

<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<link rel="icon" type="image/png" href="{{ asset('assets/favicon.png') }}">
<link rel="apple-touch-icon" href="{{ asset('assets/apple-touch-icon.png') }}">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
{{-- Admin toggle for the random start-time of hover/preview clips (นับหนังตัวอย่างในแต่ละหมวด).
     Off → every preview plays from the top; on/absent → random 10–70% seek (see nxRandomSeek). --}}
<script>window.nxRandomSeekEnabled = @js(\App\Models\Setting::flag('preview_random_seek', true));</script>
@vite(['resources/css/app.css', 'resources/js/app.js'])

{{-- Structured data — gives Google + AI assistants (SGE, ChatGPT, etc.) a clean,
     machine-readable description of the site and its search entry point. --}}
<script type="application/ld+json">
{!! json_encode([
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'Organization',
            '@id' => url('/').'#organization',
            'name' => 'NetWix',
            'url' => url('/'),
            'logo' => asset('assets/netwix-logo-full.png'),
            'description' => 'บริการสตรีมมิ่งภาพยนตร์ ซีรีส์ และซีรีส์แนวตั้งของไทย ปลอดโฆษณาเว็บพนัน ไม่ใช่เว็บพนัน',
        ],
        [
            '@type' => 'WebSite',
            '@id' => url('/').'#website',
            'name' => 'NetWix',
            'alternateName' => 'เน็ตวิกซ์',
            'url' => url('/'),
            'inLanguage' => 'th-TH',
            'publisher' => ['@id' => url('/').'#organization'],
            // Lets Google (and other engines) understand the site search entry point.
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => route('search').'?q={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
</script>
{{-- AdSense loader — self-suppressing for Pro members, adult pages, and when ads are off. --}}
<x-ads-head :content="$adContent ?? ($content ?? null)" />

@stack('head')
