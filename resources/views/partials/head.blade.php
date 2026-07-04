@php
    // SEO: every page inherits sensible defaults; any view can override a slot
    // with @section('meta_description', '…') / @section('meta_image', '…') /
    // @section('meta_robots', 'noindex,nofollow') / @section('meta_canonical', '…').
    $seoTitle = trim($__env->yieldContent('title')) ?: 'สตรีมมิ่งไม่มีสะดุด';
    $seoDesc = trim($__env->yieldContent('meta_description'))
        ?: 'NetWix — ดูหนัง ซีรีส์ และซีรีส์แนวตั้งออนไลน์ สตรีมไม่จำกัด ดูได้ทุกอุปกรณ์ ทั้งมือถือ แท็บเล็ต และทีวี';
    $seoImage = trim($__env->yieldContent('meta_image')) ?: asset('assets/netwix-logo-full.png');
    $seoRobots = trim($__env->yieldContent('meta_robots')) ?: 'index,follow,max-image-preview:large,max-snippet:-1';
    $seoCanonical = trim($__env->yieldContent('meta_canonical')) ?: url()->current();
    $seoFullTitle = $seoTitle.' · NetWix';
    // Keyword set grounded in real Thai streaming search demand (both ซีรี่ย์/ซีรีส์ spellings
    // are searched heavily; include วาย, พากย์ไทย/ซับไทย, แนวตั้ง/โรงหยก, อนิเมะ). Google no longer
    // ranks on this tag, but Bing and Thai SEO tools still read it and it is harmless. A page can
    // override with @section('meta_keywords', '…') to lead with its own title/genre terms.
    $seoKeywords = trim($__env->yieldContent('meta_keywords')) ?: implode(', ', [
        'ดูหนังออนไลน์', 'ดูหนังออนไลน์ฟรี', 'ดูซีรี่ย์ออนไลน์', 'ดูซีรี่ย์ออนไลน์ฟรี', 'ดูซีรีส์ออนไลน์',
        'ซีรี่ย์เกาหลีซับไทย', 'ซีรี่ย์เกาหลีพากย์ไทย', 'ซีรี่ย์จีนซับไทย', 'ซีรี่ย์จีนพากย์ไทย',
        'ซีรี่ย์ไทย', 'ซีรี่ย์ฝรั่ง', 'ซีรี่ย์วาย', 'ซีรีส์แนวตั้ง', 'หนังสั้นจีน', 'ละครสั้น', 'โรงหยก',
        'มินิซีรีส์จีน', 'อนิเมะซับไทย', 'ดูอนิเมะ', 'ดูหนังฟรี', 'ดูซีรี่ย์ 2026', 'พากย์ไทย HD',
        'สตรีมมิ่ง', 'ดูหนังทุกอุปกรณ์', 'NetWix', 'เน็ตวิกซ์',
    ]);
@endphp
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">

<title>{{ $seoFullTitle }}</title>
<meta name="description" content="{{ $seoDesc }}">
<meta name="keywords" content="{{ $seoKeywords }}">
<meta name="robots" content="{{ $seoRobots }}">
<meta name="theme-color" content="#07050c">
<link rel="canonical" href="{{ $seoCanonical }}">

{{-- Open Graph (Facebook, LINE, Messenger link previews) --}}
<meta property="og:type" content="website">
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
            'description' => 'บริการสตรีมมิ่งภาพยนตร์ ซีรีส์ และซีรีส์แนวตั้งของไทย',
        ],
        [
            '@type' => 'WebSite',
            '@id' => url('/').'#website',
            'name' => 'NetWix',
            'alternateName' => 'เน็ตวิกซ์',
            'url' => url('/'),
            'inLanguage' => 'th-TH',
            'publisher' => ['@id' => url('/').'#organization'],
        ],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
</script>
@stack('head')
