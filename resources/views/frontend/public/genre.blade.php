@extends('layouts.public')

@php
    $sortOpts = [
        'random' => 'สุ่ม',
        'views'  => 'ยอดวิว',
        'rating' => 'คะแนน',
        'latest' => 'ใหม่ล่าสุด',
    ];
    $total = $items->total();
@endphp

@section('title', 'ดู'.$genre->name.'ออนไลน์'.($genre->name_en ? ' '.$genre->name_en : ''))
@section('meta_description', 'ดู'.$genre->name.'ออนไลน์ที่ NetWix รวม'.number_format($total).'เรื่อง — ซีรีส์ หนัง อนิเมะ '.$genre->name.' พากย์ไทย/ซับไทย ดูฟรีชัด HD ทุกอุปกรณ์')
@section('meta_keywords', $genre->seo_keywords)
@section('meta_canonical', route('browse.genre', $genre))

@push('head')
<script type="application/ld+json">
{!! json_encode([
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'หน้าแรก', 'item' => route('home')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $genre->name, 'item' => route('browse.genre', $genre)],
            ],
        ],
        [
            '@type' => 'CollectionPage',
            '@id' => route('browse.genre', $genre),
            'name' => 'ดู'.$genre->name.'ออนไลน์',
            'url' => route('browse.genre', $genre),
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
<div class="px-[4vw] pt-24 pb-12">
    <h1 class="flex flex-wrap items-baseline gap-3 text-2xl font-bold sm:text-3xl">
        <span class="nx-gradient h-7 w-1.5 shrink-0 self-center rounded-full" aria-hidden="true"></span>
        <span>ดู{{ $genre->name }}ออนไลน์</span>
        @if ($genre->name_en)<span class="text-lg font-normal text-cream/45">{{ $genre->name_en }}</span>@endif
        <span class="text-base font-normal text-cream/40">{{ number_format($total) }} เรื่อง</span>
    </h1>
    <p class="mt-2 max-w-3xl text-sm leading-relaxed text-cream/55">
        รวม{{ $genre->name }}ทั้งหมดบน NetWix — ทั้งซีรีส์ ภาพยนตร์ และอนิเมะ พากย์ไทยและซับไทย ดูออนไลน์ฟรี ชัดระดับ HD เล่นได้ทุกอุปกรณ์
    </p>

    {{-- Top hits --}}
    @if ($top->isNotEmpty())
        <section class="mt-8">
            <h2 class="mb-3 flex items-center gap-2 text-lg font-semibold sm:text-xl">
                <span class="nx-gradient h-5 w-1 shrink-0 rounded-full sm:h-6" aria-hidden="true"></span>
                <span>🔥 ยอดฮิตในหมวดนี้</span>
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
            <a href="{{ route('browse.genre', ['genre' => $genre, 'sort' => $key]) }}"
               rel="nofollow"
               class="shrink-0 whitespace-nowrap rounded-full px-4 py-1.5 text-sm transition {{ $sort === $key ? 'nx-gradient font-semibold' : 'bg-white/5 text-cream/60 hover:text-cream' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    {{-- Grid --}}
    @if ($items->isEmpty())
        <div class="rounded-xl border border-white/5 bg-white/[0.02] py-20 text-center text-cream/50">ยังไม่มีเรื่องในหมวดนี้</div>
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
