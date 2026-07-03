@extends('layouts.app')
@section('title', 'ซีรีส์แนวตั้ง')

@section('content')
<div class="pt-24 pb-12">
    <div class="px-[4vw]">
        <h1 class="text-2xl font-bold sm:text-3xl">ซีรีส์แนวตั้ง</h1>
        <p class="mt-1 text-cream/50">ดูจบไวในไม่กี่นาที · ปัดขึ้น–ลงเพื่อดูตอนถัดไป</p>
    </div>

    @forelse ($rows as $row)
        <section class="mt-7" x-data="nxRail()">
            <h2 class="mb-2 flex items-center gap-2 px-[4vw] text-lg font-semibold sm:text-xl">
                <span class="nx-gradient h-5 w-1 shrink-0 rounded-full sm:h-6" aria-hidden="true"></span>
                <span>{{ $row['title'] }}</span>
                @if ($row['genre'])
                    <a href="{{ route('browse.genre', $row['genre']) }}" class="ml-1 whitespace-nowrap rounded-full bg-white/10 px-2.5 py-0.5 text-[12px] font-semibold text-cream/85 transition hover:bg-brand hover:text-white">ดูทั้งหมด ›</a>
                @endif
            </h2>
            <div class="group/row relative">
                <button type="button" @click="scroll(-1)"
                        class="absolute left-0 top-0 z-20 hidden h-full w-[4vw] items-center justify-center bg-gradient-to-r from-ink/80 to-transparent text-2xl opacity-0 transition group-hover/row:opacity-100 lg:flex">‹</button>
                <div x-ref="rail" class="nx-rail px-[4vw] pb-2" @mousemove="edgeMove($event)" @mouseleave="edgeLeave()">
                    @foreach ($row['items'] as $content)
                        <a href="{{ route('watch', $content) }}" class="group block w-[132px] shrink-0 sm:w-[150px] md:w-[168px]">
                            <div class="relative aspect-[9/16] overflow-hidden rounded-xl ring-1 ring-white/5 transition group-hover:ring-2 group-hover:ring-white/25"
                                 style="background:{{ $content->gradient }}">
                                @if ($content->poster_url)
                                    <img src="{{ $content->poster_url }}" alt="{{ $content->title }}" loading="lazy"
                                         referrerpolicy="no-referrer" onerror="this.style.display='none'"
                                         class="absolute inset-0 h-full w-full object-cover">
                                @else
                                    <div class="absolute inset-0 flex items-center justify-center">
                                        <img src="{{ asset('assets/netwix-icon.png') }}" alt="" class="h-12 w-12 opacity-30">
                                    </div>
                                @endif
                                <div class="absolute inset-0 flex items-center justify-center opacity-0 transition group-hover:opacity-100" style="background:rgba(0,0,0,0.35)">
                                    <span class="flex h-11 w-11 items-center justify-center rounded-full bg-cream/90 text-ink">
                                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                                    </span>
                                </div>
                                @if ($content->is_original)
                                    <span class="nx-gradient absolute left-2 top-2 rounded px-1.5 py-0.5 text-[9px] font-bold tracking-widest">NETWIX</span>
                                @endif
                                <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/85 to-transparent p-2.5">
                                    <div class="truncate text-[13px] font-semibold">{{ $content->title }}</div>
                                    <div class="text-[11px] text-cream/60">{{ $content->episodes_count ?? $content->episodes->count() }} ตอน</div>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
                <button type="button" @click="scroll(1)"
                        class="absolute right-0 top-0 z-20 hidden h-full w-[4vw] items-center justify-center bg-gradient-to-l from-ink/80 to-transparent text-2xl opacity-0 transition group-hover/row:opacity-100 lg:flex">›</button>
            </div>
        </section>
    @empty
        <div class="px-[4vw] py-20 text-center text-cream/50">ยังไม่มีซีรีส์แนวตั้ง</div>
    @endforelse
</div>
@endsection
