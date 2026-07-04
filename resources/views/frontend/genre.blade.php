@extends('layouts.app')
@section('title', $heading)

@php
    $sortOpts = [
        'random' => ['th' => 'สุ่ม', 'en' => 'Random'],
        'views'  => ['th' => 'ยอดวิว', 'en' => 'Views'],
        'rating' => ['th' => 'คะแนน', 'en' => 'Rating'],
        'likes'  => ['th' => 'ถูกใจ', 'en' => 'Likes'],
        'latest' => ['th' => 'ใหม่ล่าสุด', 'en' => 'Newest'],
    ];
    $sortable = in_array($sort, ['views', 'rating', 'likes', 'latest'], true);
@endphp

@section('content')
<div class="px-[4vw] pt-24">
    <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('browse') }}"
       class="mb-4 inline-flex items-center gap-1 rounded-full bg-white/10 px-4 py-2 text-sm backdrop-blur transition hover:bg-white/15">‹ ย้อนกลับ <span class="text-cream/50">Back</span></a>

    <h1 class="flex flex-wrap items-center gap-3 text-2xl font-bold sm:text-3xl">
        <span class="nx-gradient h-7 w-1.5 shrink-0 rounded-full" aria-hidden="true"></span>
        <span>{{ $heading }}</span>
        @if ($headingEn)<span class="text-lg font-normal text-cream/45">{{ $headingEn }}</span>@endif
        <span class="text-base font-normal text-cream/40">{{ $items->total() }} เรื่อง</span>
    </h1>
</div>

{{-- Continue watching in this genre --}}
@if ($continue->isNotEmpty())
    <x-content-row title="เคยดูในหมวดนี้" en="Continue Watching" :items="$continue" :my-list-ids="$myListIds" />
@endif

{{-- Ranking banner: top hits in this genre --}}
@if ($top->isNotEmpty())
    <section class="mt-8 px-[4vw]">
        <h2 class="mb-2 flex items-center gap-2 text-lg font-semibold sm:text-xl">
            <span class="nx-gradient h-5 w-1 shrink-0 rounded-full sm:h-6" aria-hidden="true"></span>
            <span>🔥 ยอดฮิตในหมวดนี้</span><span class="text-sm font-normal text-cream/40">Top Hits</span>
        </h2>
        <div class="flex flex-wrap gap-x-4 gap-y-6">
            @foreach ($top as $i => $c)
                <x-content-card :content="$c" :ranked="$i + 1" :in-list="in_array($c->id, $myListIds)" />
            @endforeach
        </div>
    </section>
@endif

<div class="mt-9 px-[4vw] pb-12">
    {{-- Sort / filter bar --}}
    <div class="mb-5 flex items-center gap-2 overflow-x-auto pb-1">
        <span class="shrink-0 pr-1 text-sm text-cream/40">เรียง:</span>
        @foreach ($sortOpts as $key => $o)
            <a href="{{ route('browse.genre', ['genre' => $genre, 'sort' => $key, 'dir' => $dir]) }}"
               class="shrink-0 whitespace-nowrap rounded-full px-4 py-1.5 text-sm transition {{ $sort === $key ? 'nx-gradient font-semibold' : 'bg-white/5 text-cream/60 hover:text-cream' }}">
                {{ $o['th'] }} <span class="opacity-50">{{ $o['en'] }}</span>
            </a>
        @endforeach
        @if ($sortable)
            <a href="{{ route('browse.genre', ['genre' => $genre, 'sort' => $sort, 'dir' => $dir === 'desc' ? 'asc' : 'desc']) }}"
               class="shrink-0 whitespace-nowrap rounded-full bg-white/10 px-4 py-1.5 text-sm font-medium text-cream/85 transition hover:bg-white/15">
                {{ $dir === 'desc' ? '↓ มากสุด High→Low' : '↑ น้อยสุด Low→High' }}
            </a>
        @endif
    </div>

    {{-- Full grid --}}
    @if ($items->isEmpty())
        <div class="rounded-xl border border-white/5 bg-white/[0.02] py-20 text-center text-cream/50">ยังไม่มีเรื่องในหมวดนี้</div>
    @else
        <div class="flex flex-wrap gap-4">
            @foreach ($items as $content)
                <x-content-card :content="$content" :in-list="in_array($content->id, $myListIds)" />
            @endforeach
        </div>
    @endif

    {{-- Pager: the grid is paginated (60/page) so a big genre isn't a multi-MB page. --}}
    @if ($items->hasPages())
        <div class="mt-8 flex items-center justify-center gap-3 text-sm">
            @if ($items->onFirstPage())
                <span class="rounded-full bg-white/5 px-5 py-2 text-cream/30">‹ ก่อนหน้า</span>
            @else
                <a href="{{ $items->previousPageUrl() }}" class="rounded-full bg-white/10 px-5 py-2 transition hover:bg-white/15">‹ ก่อนหน้า</a>
            @endif
            <span class="text-cream/50">หน้า {{ $items->currentPage() }} / {{ $items->lastPage() }}</span>
            @if ($items->hasMorePages())
                <a href="{{ $items->nextPageUrl() }}" class="rounded-full bg-white/10 px-5 py-2 transition hover:bg-white/15">ถัดไป ›</a>
            @else
                <span class="rounded-full bg-white/5 px-5 py-2 text-cream/30">ถัดไป ›</span>
            @endif
        </div>
    @endif
</div>
@endsection
