@extends('layouts.app')
@section('title', 'ซีรีส์แนวตั้ง')

@section('content')
<div class="px-[4vw] pt-24 pb-12">
    <h1 class="mb-1 text-2xl font-bold sm:text-3xl">ซีรีส์แนวตั้ง</h1>
    <p class="mb-7 text-cream/50">ดูจบไวในไม่กี่นาที · ปัดขึ้น–ลงเพื่อดูตอนถัดไป</p>

    @if ($items->isEmpty())
        <div class="py-20 text-center text-cream/50">ยังไม่มีซีรีส์แนวตั้ง</div>
    @else
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6">
            @foreach ($items as $content)
                <a href="{{ route('watch', $content) }}" class="group block">
                    <div class="relative aspect-[9/16] overflow-hidden rounded-xl ring-1 ring-white/5 transition group-hover:ring-2 group-hover:ring-white/25"
                         style="background:{{ $content->gradient }}">
                        @if ($content->poster_url)
                            <img src="{{ $content->poster_url }}" alt="{{ $content->title }}" loading="lazy"
                                 referrerpolicy="no-referrer" onerror="this.style.display='none'"
                                 class="absolute inset-0 h-full w-full object-cover">
                        @else
                            <div class="absolute inset-0 flex items-center justify-center">
                                <img src="{{ asset('assets/netwix-icon.png') }}" alt="" class="h-14 w-14 opacity-30">
                            </div>
                        @endif
                        <div class="absolute inset-0 flex items-center justify-center opacity-0 transition group-hover:opacity-100" style="background:rgba(0,0,0,0.35)">
                            <span class="flex h-12 w-12 items-center justify-center rounded-full bg-cream/90 text-ink">
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
    @endif
</div>
@endsection
