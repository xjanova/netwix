@extends('layouts.player')
@section('title', $content->title)

@php
    $eps = $episodes->map(fn ($e) => [
        'n' => $e->number,
        'title' => $e->title,
        'url' => $e->video_url,
    ])->values();
@endphp

@section('content')
<div
    x-data="verticalPlayer({ episodes: @js($eps), start: {{ $start }} })"
    x-init="init()"
    @wheel.prevent="onWheel($event)"
    @touchstart.passive="onTouchStart($event)"
    @touchend.passive="onTouchEnd($event)"
    class="relative flex h-[100dvh] select-none items-center justify-center overflow-hidden bg-black"
>
    <a href="{{ route('browse.vertical') }}" class="absolute left-4 top-4 z-30 flex h-10 w-10 items-center justify-center rounded-full bg-white/10 text-lg backdrop-blur hover:bg-white/20">✕</a>

    <div class="relative h-full max-h-[100dvh] w-auto" style="aspect-ratio:9/16">
        <video x-ref="video" playsinline autoplay muted loop
               @click="togglePlay()"
               class="h-full w-full bg-black object-contain"></video>

        {{-- gradient + caption --}}
        <div class="pointer-events-none absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/85 to-transparent p-5">
            <div class="text-lg font-bold">{{ $content->title }}</div>
            <div class="text-sm text-cream/70">ตอนที่ <span x-text="episodes[index].n"></span> / {{ $eps->count() }}</div>
        </div>

        {{-- nav --}}
        <div class="absolute right-3 top-1/2 flex -translate-y-1/2 flex-col gap-3">
            <button @click="prev()" :disabled="index === 0" class="flex h-11 w-11 items-center justify-center rounded-full bg-white/10 text-xl backdrop-blur disabled:opacity-30 hover:bg-white/20">↑</button>
            <button @click="next()" :disabled="index === episodes.length - 1" class="flex h-11 w-11 items-center justify-center rounded-full bg-white/10 text-xl backdrop-blur disabled:opacity-30 hover:bg-white/20">↓</button>
        </div>

        <div x-show="episodes.length === 0" class="absolute inset-0 flex items-center justify-center text-cream/60">ยังไม่มีตอน</div>
    </div>
</div>

@push('scripts')
<script>
    function verticalPlayer(cfg) {
        return {
            episodes: cfg.episodes,
            index: Math.min(cfg.start, Math.max(0, cfg.episodes.length - 1)),
            lock: false,
            touchY: 0,
            init() {
                if (this.episodes.length) this.load();
            },
            load() {
                const url = this.episodes[this.index]?.url;
                if (url) window.nxAttachVideo(this.$refs.video, url);
                this.$refs.video.play?.().catch(() => {});
            },
            next() {
                if (this.index < this.episodes.length - 1) { this.index++; this.load(); }
            },
            prev() {
                if (this.index > 0) { this.index--; this.load(); }
            },
            togglePlay() {
                const v = this.$refs.video;
                v.paused ? v.play() : v.pause();
            },
            onWheel(e) {
                if (this.lock) return;
                if (e.deltaY > 25) this.next();
                else if (e.deltaY < -25) this.prev();
                else return;
                this.lock = true;
                setTimeout(() => (this.lock = false), 550);
            },
            onTouchStart(e) { this.touchY = e.touches[0].clientY; },
            onTouchEnd(e) {
                const dy = this.touchY - e.changedTouches[0].clientY;
                if (dy > 50) this.next();
                else if (dy < -50) this.prev();
            },
        };
    }
</script>
@endpush
@endsection
