@extends('layouts.public')

@php
    $typeLabel = ['series' => 'ซีรี่ส์', 'movie' => 'ภาพยนตร์', 'vertical' => 'ซีรีส์แนวตั้ง'][$content->type] ?? 'ซีรี่ส์';
    $dub = $content->dub_label ? ' '.$content->dub_label : '';
    // A rich, keyword-bearing description grounded in the synopsis (Google shows ~155 chars).
    $desc = $content->synopsis
        ? \Illuminate\Support\Str::limit($content->synopsis, 155)
        : 'ดู'.$content->title.$dub.' '.$typeLabel.'ออนไลน์ '.($content->year ? $content->year.' ' : '').'เต็มเรื่องทุกตอน ชัด HD ที่ NetWix';
@endphp

@section('title', $content->title.($content->year ? ' ('.$content->year.')' : ''))
@section('meta_description', $desc)
@section('meta_keywords', $content->seo_keywords)
@section('meta_image', $content->poster_url ?: ($content->backdrop_url ?: asset('assets/netwix-logo-full.png')))
@section('meta_canonical', route('title.show', $content))
@section('og_type', $content->type === 'movie' ? 'video.movie' : 'video.tv_show')

@push('head')
<script type="application/ld+json">
{{-- UNESCAPED_UNICODE keeps Thai readable; slashes stay escaped so a synopsis containing
     "</script>" can't break out of this tag. --}}
{!! json_encode(\App\Support\StructuredData::forTitle($content), JSON_UNESCAPED_UNICODE) !!}
</script>
@endpush

@section('content')
<div class="pt-20 pb-10">
    <div class="mx-auto max-w-5xl px-4">
        {{-- Crawlable breadcrumb (mirrors the BreadcrumbList JSON-LD) --}}
        <nav aria-label="breadcrumb" class="mb-3 flex flex-wrap items-center gap-1.5 text-[13px] text-cream/50">
            <a href="{{ route('home') }}" class="hover:text-cream">หน้าแรก</a>
            @if ($primary = $content->primaryGenre())
                <span>›</span>
                <a href="{{ route('browse.genre', $primary) }}" class="hover:text-cream">{{ $primary->name }}</a>
            @endif
            <span>›</span>
            <span class="text-cream/80">{{ $content->title }}</span>
        </nav>

        {{-- The one true H1 for the page (the detail card renders the title visually as an h2). --}}
        <h1 class="sr-only">{{ $content->title }}{{ $dub }} ดู{{ $typeLabel }}ออนไลน์ {{ $content->year }}</h1>

        <div class="nx-card overflow-hidden">
            @include('frontend.partials.title-modal', [
                'content' => $content,
                'inMyList' => $inMyList,
                'liked' => $liked,
                'modal' => false,
            ])
        </div>
    </div>

    {{-- Related titles — crawlable <a> cards so Googlebot walks the catalog graph. --}}
    @if ($related->isNotEmpty())
        <section class="mx-auto mt-10 max-w-5xl px-4">
            <h2 class="mb-3 flex items-center gap-2 text-lg font-semibold sm:text-xl">
                <span class="nx-gradient h-5 w-1 shrink-0 rounded-full sm:h-6" aria-hidden="true"></span>
                <span>เรื่องที่คล้ายกัน</span>
            </h2>
            <div class="flex flex-wrap gap-4">
                @foreach ($related as $c)
                    <x-public-card :content="$c" />
                @endforeach
            </div>
        </section>
    @endif
</div>
@endsection
