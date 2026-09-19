@extends('layouts.public')

@php
    use App\Support\TitleIndex;

    $total = $items->total();
    $page = $items->currentPage();
    // Page 2+ is its own canonical, exactly as the hubs are — pointing it back at page 1 would tell
    // Google the deeper pages are duplicates and throw away the crawl path they exist to provide.
    $canonicalUrl = route('browse.all.group', $group).($page > 1 ? '?page='.$page : '');
    $typeLabel = ['movie' => 'ภาพยนตร์', 'series' => 'ซีรี่ส์', 'vertical' => 'ซีรีส์แนวตั้ง'];
@endphp

@section('title', 'รายชื่อหนังและซีรีส์ ขึ้นต้นด้วย "'.$label.'"'.($page > 1 ? ' หน้า '.$page : ''))
@section('meta_description', 'รวมหนัง ซีรีส์ และอนิเมะที่ชื่อขึ้นต้นด้วย "'.$label.'" ทั้งหมด '.number_format($total).' เรื่อง ดูออนไลน์ฟรีที่ NetWix พากย์ไทยและซับไทย')
@section('meta_canonical', $canonicalUrl)

@push('head')
<script type="application/ld+json">
{!! json_encode([
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'หน้าแรก', 'item' => route('home')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'รายชื่อทั้งหมด', 'item' => route('browse.all')],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $label, 'item' => route('browse.all.group', $group)],
            ],
        ],
        [
            '@type' => 'CollectionPage',
            '@id' => $canonicalUrl,
            'name' => 'รายชื่อเรื่องที่ขึ้นต้นด้วย '.$label,
            'url' => $canonicalUrl,
            'inLanguage' => 'th-TH',
            'isPartOf' => ['@id' => url('/').'#website'],
            'mainEntity' => [
                '@type' => 'ItemList',
                'numberOfItems' => $total,
                'itemListElement' => $items->getCollection()->take(50)->values()
                    ->map(fn ($c, $i) => [
                        '@type' => 'ListItem',
                        'position' => (($page - 1) * $items->perPage()) + $i + 1,
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
<div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
    <nav class="text-[13px] text-cream/40" aria-label="เส้นทาง">
        <a href="{{ route('home') }}" class="hover:text-cream">หน้าแรก</a>
        <span class="px-1.5">›</span>
        <a href="{{ route('browse.all') }}" class="hover:text-cream">รายชื่อทั้งหมด</a>
        <span class="px-1.5">›</span>
        <span class="text-cream/70">{{ $label }}</span>
    </nav>

    <h1 class="mt-3 text-2xl font-bold sm:text-3xl">
        เรื่องที่ขึ้นต้นด้วย “{{ $label }}”
        <span class="ml-1 align-middle text-base font-normal text-cream/40">{{ number_format($total) }} เรื่อง</span>
    </h1>

    {{-- Every other letter, from every page of every letter: the whole directory is one hop wide,
         so a crawler that lands anywhere in it can reach all of it. --}}
    <div class="mt-5 flex flex-wrap gap-1.5">
        @foreach ($groups as $slug => $count)
            @if ($slug === $group)
                <span class="nx-gradient rounded-lg px-2.5 py-1 text-[13px] font-semibold text-white">{{ TitleIndex::label($slug) }}</span>
            @else
                <a href="{{ route('browse.all.group', $slug) }}"
                   class="rounded-lg bg-white/[0.06] px-2.5 py-1 text-[13px] text-cream/60 transition hover:bg-white/15 hover:text-cream">{{ TitleIndex::label($slug) }}</a>
            @endif
        @endforeach
    </div>

    {{-- A plain list, on purpose. The anchor text is the film's name, which is the thing a person
         types into Google — a grid of posters carries none of that. --}}
    <ul class="mt-7 grid gap-x-8 gap-y-1 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($items as $c)
            <li class="border-b border-white/5 py-2">
                <a href="{{ route('title.show', $c) }}" class="group flex items-baseline gap-2 text-[15px] leading-snug">
                    <span class="text-cream/85 transition group-hover:text-brand">{{ $c->title }}</span>
                    @if ($c->year)
                        <span class="shrink-0 text-[12px] text-cream/35">{{ $c->year }}</span>
                    @endif
                    <span class="shrink-0 text-[12px] text-cream/25">{{ $typeLabel[$c->type] ?? '' }}</span>
                </a>
            </li>
        @endforeach
    </ul>

    @include('partials.pager', ['p' => $items])
</div>
@endsection
