@extends('layouts.app')
@section('title', 'ซีรีส์แนวตั้ง')

@section('content')
{{-- Rotating "หนังตัวอย่าง" billboard — scoped to แนวตั้ง (cached + shared). Falls back to a spacer that
     clears the fixed nav when no vertical slides are configured. --}}
@php $hasHero = count($heroSlides ?? []); @endphp
@if ($hasHero)
    @include('partials.hero-billboard', [
        'heroSlides' => $heroSlides,
        'heroSeconds' => $heroSeconds ?? 8,
        'heroVideo' => $heroVideo ?? true,
        'heroPublic' => false,
    ])
@endif

<div class="relative z-10 pb-12 {{ $hasHero ? '-mt-16' : 'pt-24' }}">
    <div class="px-[4vw]">
        <h1 class="text-2xl font-bold sm:text-3xl">ซีรีส์แนวตั้ง</h1>
        <p class="mt-1 text-cream/50">ดูจบไวในไม่กี่นาที · ปัดขึ้น–ลงเพื่อดูตอนถัดไป</p>
    </div>

    @forelse ($rows as $row)
        <section class="mt-7" x-data="nxRail()">
            <h2 class="mb-2 flex items-center gap-2 px-[4vw] text-lg font-semibold sm:text-xl">
                <span class="nx-gradient h-5 w-1 shrink-0 rounded-full sm:h-6" aria-hidden="true"></span>
                <span>{{ $row['title'] }}</span>
                @php $rowEn = $row['en'] ?? $row['genre']?->name_en; @endphp
                @if ($rowEn)<span class="text-[14px] font-normal text-cream/40">{{ $rowEn }}</span>@endif
                @if ($row['genre'])
                    <a href="{{ route('browse.genre', $row['genre']) }}" class="ml-1 whitespace-nowrap rounded-full bg-white/10 px-2.5 py-0.5 text-[12px] font-semibold text-cream/85 transition hover:bg-brand hover:text-white">ดูทั้งหมด ›</a>
                @endif
            </h2>
            <div class="group/row relative" @mousemove="edgeMove($event)" @mouseleave="edgeLeave()">
                <button type="button" @click="scroll(-1)" @mouseenter="edgeStart(-1)" @mouseleave="edgeStop()"
                        class="absolute left-0 top-0 z-20 hidden h-full w-[4vw] items-center justify-center bg-gradient-to-r from-ink/80 to-transparent text-2xl opacity-0 transition group-hover/row:opacity-100 lg:flex">‹</button>
                <div x-ref="rail" class="nx-rail px-[4vw] pb-2" @scroll.passive="onScroll()"
                     @if (! empty($row['lazy']))
                         data-lazy-url="{{ route('browse.row') }}"
                         data-lazy-type="{{ $row['lazy']['type'] ?? '' }}"
                         data-lazy-genre="{{ $row['lazy']['genre'] ?? '' }}"
                         data-lazy-seed="{{ $row['lazy']['seed'] ?? '' }}"
                     @endif>
                    @include('frontend.partials.vertical-cards', ['items' => $row['items']])
                </div>
                <button type="button" @click="scroll(1)" @mouseenter="edgeStart(1)" @mouseleave="edgeStop()"
                        class="absolute right-0 top-0 z-20 hidden h-full w-[4vw] items-center justify-center bg-gradient-to-l from-ink/80 to-transparent text-2xl opacity-0 transition group-hover/row:opacity-100 lg:flex">›</button>
            </div>
        </section>
    @empty
        <div class="px-[4vw] py-20 text-center text-cream/50">ยังไม่มีซีรีส์แนวตั้ง</div>
    @endforelse
</div>
@endsection
