@extends('layouts.app')
@section('title', $heading ?? 'หน้าแรก')

@section('content')
@if ($hero)
    @php $heroYt = $hero->youtube_id; @endphp
    <section class="relative h-[62vh] min-h-[440px] w-full overflow-hidden bg-black md:h-[82vh]"
             @if ($heroYt) x-data="{ muted: true }" @endif>
        @if ($heroYt)
            <iframe
                :src="`https://www.youtube.com/embed/{{ $heroYt }}?autoplay=1&mute=${muted ? 1 : 0}&loop=1&playlist={{ $heroYt }}&controls=0&modestbranding=1&rel=0&showinfo=0&playsinline=1`"
                class="pointer-events-none absolute left-1/2 top-1/2 h-full w-full -translate-x-1/2 -translate-y-1/2 border-0"
                style="min-width:178vh;min-height:75vw" allow="autoplay; encrypted-media"></iframe>
        @else
            <div class="absolute inset-0" style="background:{{ $hero->gradient }}"></div>
        @endif

        <div class="absolute inset-0" style="background:linear-gradient(90deg, rgba(7,5,12,0.85) 0%, rgba(7,5,12,0.25) 45%, rgba(7,5,12,0.1) 65%, rgba(7,5,12,0.6) 100%)"></div>
        <div class="absolute inset-0" style="background:linear-gradient(180deg, rgba(7,5,12,0.15) 0%, transparent 26%, transparent 55%, rgba(7,5,12,0.95) 96%, #07050c 100%)"></div>

        <div class="absolute bottom-[11%] left-[4vw] right-[4vw] max-w-[640px]">
            @if ($hero->is_original)
                <div class="nx-gradient mb-4 inline-flex items-center gap-1.5 rounded px-2.5 py-1 text-[11.5px] font-bold tracking-widest">NETWIX ORIGINAL</div>
            @endif
            <h1 class="mb-4 text-[clamp(30px,5.2vw,64px)] font-extrabold leading-[1.05] drop-shadow-lg">{{ $hero->title }}</h1>
            <div class="mb-4 flex flex-wrap items-center gap-3 text-[14.5px] text-cream/75">
                <span class="font-bold text-success">{{ $hero->match_score }}% ตรงใจ</span>
                <span>{{ $hero->year }}</span>
                <span class="rounded border border-cream/40 px-1.5 py-px text-[12.5px]">{{ $hero->maturity }}</span>
                <span>{{ $hero->type === 'movie' ? ($hero->duration_minutes.' นาที') : ($hero->seasons->count() ?: 1).' ซีซั่น' }}</span>
            </div>
            <p class="mb-6 max-w-xl text-[15.5px] leading-relaxed text-cream/85 drop-shadow line-clamp-3">{{ $hero->synopsis }}</p>
            <div class="flex items-center gap-3.5">
                <a href="{{ route('watch', $hero) }}"
                   class="flex items-center gap-2.5 rounded-md bg-cream px-7 py-3 text-base font-bold text-ink transition hover:brightness-90">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg> เล่น
                </a>
                <button type="button" @click="$dispatch('open-title', '{{ route('title.modal', $hero) }}')"
                        class="flex items-center gap-2.5 rounded-md bg-[rgba(120,120,130,0.35)] px-6 py-3 text-base font-bold backdrop-blur transition hover:bg-[rgba(120,120,130,0.5)]">
                    ⓘ ข้อมูลเพิ่มเติม
                </button>
            </div>
        </div>

        @if ($heroYt)
            <button type="button" @click="muted = !muted"
                    class="absolute bottom-[11%] right-[4vw] rounded-full border-[1.5px] border-cream/50 bg-ink/35 px-4 py-2 text-[13px] backdrop-blur">
                <span x-text="muted ? '🔇 ปิดเสียง' : '🔊 เปิดเสียง'"></span>
            </button>
        @endif
    </section>
@else
    <div class="h-24"></div>
@endif

<div class="relative z-10 -mt-16 pb-10">
    @isset($heading)
        <h1 class="px-[4vw] pt-4 text-2xl font-bold">{{ $heading }}</h1>
    @endisset

    @forelse ($rows as $row)
        <x-content-row :title="$row['title']" :items="$row['items']" :ranked="$row['ranked'] ?? false" :my-list-ids="$myListIds" />
    @empty
        <div class="px-[4vw] py-20 text-center text-cream/50">ยังไม่มีคอนเทนต์ในหมวดนี้</div>
    @endforelse
</div>
@endsection
