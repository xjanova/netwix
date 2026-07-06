@extends('layouts.public')

@php
    $sortOpts = ['random' => 'สุ่ม', 'views' => 'ยอดวิว', 'rating' => 'คะแนน', 'latest' => 'ใหม่ล่าสุด'];
    $total = $items->total();
@endphp

@section('title', 'ดู'.$heading.'ออนไลน์ '.$headingEn)
@section('meta_description', $intro.' รวม'.number_format($total).'เรื่องบน NetWix')
@section('meta_canonical', route($routeName))
@if ($total === 0)
    @section('meta_robots', 'noindex,follow')
@endif

@push('head')
<script type="application/ld+json">
{!! json_encode([
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'หน้าแรก', 'item' => route('home')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $heading, 'item' => route($routeName)],
            ],
        ],
        [
            '@type' => 'CollectionPage',
            '@id' => route($routeName),
            'name' => 'ดู'.$heading.'ออนไลน์',
            'url' => route($routeName),
            'inLanguage' => 'th-TH',
            'isPartOf' => ['@id' => url('/').'#website'],
            'mainEntity' => [
                '@type' => 'ItemList',
                'numberOfItems' => $total,
                'itemListElement' => $items->getCollection()->take(30)->values()
                    ->map(fn ($c, $i) => [
                        '@type' => 'ListItem',
                        'position' => $i + 1,
                        'url' => route('title.show', $c),
                        'name' => $c->title,
                    ])->all(),
            ],
        ],
    ],
], JSON_UNESCAPED_UNICODE) !!}
</script>
@endpush

@section('content')
@if (count($heroSlides ?? []))
    @include('partials.hero-billboard', [
        'heroSlides' => $heroSlides,
        'heroSeconds' => $heroSeconds ?? 8,
        'heroVideo' => $heroVideo ?? true,
        'heroPublic' => true,
    ])
@endif
<div class="relative z-10 px-[4vw] pb-12 {{ count($heroSlides ?? []) ? '-mt-16' : 'pt-24' }}">
    <h1 class="flex flex-wrap items-baseline gap-3 text-2xl font-bold sm:text-3xl">
        <span class="nx-gradient h-7 w-1.5 shrink-0 self-center rounded-full" aria-hidden="true"></span>
        <span>ดู{{ $heading }}ออนไลน์</span>
        <span class="text-lg font-normal text-cream/45">{{ $headingEn }}</span>
        <span class="text-base font-normal text-cream/40">{{ number_format($total) }} เรื่อง</span>
    </h1>
    <p class="mt-2 max-w-3xl text-sm leading-relaxed text-cream/55">{{ $intro }}</p>

    {{-- Top hits --}}
    @if ($top->isNotEmpty())
        <section class="mt-8">
            <h2 class="mb-3 flex items-center gap-2 text-lg font-semibold sm:text-xl">
                <span class="nx-gradient h-5 w-1 shrink-0 rounded-full sm:h-6" aria-hidden="true"></span>
                <span>🔥 มาแรงตอนนี้</span>
            </h2>
            <div class="flex flex-wrap gap-4">
                @foreach ($top as $c)
                    <x-public-card :content="$c" />
                @endforeach
            </div>
        </section>
    @endif

    {{-- Sort bar --}}
    <div class="mt-9 mb-5 flex items-center gap-2 overflow-x-auto pb-1">
        <span class="shrink-0 pr-1 text-sm text-cream/40">เรียง:</span>
        @foreach ($sortOpts as $key => $label)
            <a href="{{ route($routeName, ['sort' => $key]) }}" rel="nofollow"
               class="shrink-0 whitespace-nowrap rounded-full px-4 py-1.5 text-sm transition {{ $sort === $key ? 'nx-gradient font-semibold' : 'bg-white/5 text-cream/60 hover:text-cream' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    {{-- Grid --}}
    @if ($items->isEmpty())
        <div class="rounded-xl border border-white/5 bg-white/[0.02] py-20 text-center text-cream/50">ยังไม่มีเรื่องในหมวดนี้ — เร็ว ๆ นี้</div>
    @else
        <div class="flex flex-wrap gap-4">
            @foreach ($items as $c)
                <x-public-card :content="$c" />
            @endforeach
        </div>
    @endif

    {{-- Pager --}}
    @if ($items->hasPages())
        <div class="mt-8 flex items-center justify-center gap-3 text-sm">
            @if ($items->onFirstPage())
                <span class="rounded-full bg-white/5 px-5 py-2 text-cream/30">‹ ก่อนหน้า</span>
            @else
                <a href="{{ $items->previousPageUrl() }}" rel="nofollow" class="rounded-full bg-white/10 px-5 py-2 transition hover:bg-white/15">‹ ก่อนหน้า</a>
            @endif
            <span class="text-cream/50">หน้า {{ $items->currentPage() }} / {{ $items->lastPage() }}</span>
            @if ($items->hasMorePages())
                <a href="{{ $items->nextPageUrl() }}" rel="nofollow" class="rounded-full bg-white/10 px-5 py-2 transition hover:bg-white/15">ถัดไป ›</a>
            @else
                <span class="rounded-full bg-white/5 px-5 py-2 text-cream/30">ถัดไป ›</span>
            @endif
        </div>
    @endif
</div>
@endsection
