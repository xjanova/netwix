@extends('layouts.player')
@section('title', $content->title)

@php
    $eps = $episodes->map(fn ($e) => [
        'n' => $e->number,
        'title' => $e->title,
        'url' => $e->video_url,
        'resolve' => ($e->source && ! $e->video_url) ? route('episode.source', $e) : null,
        'request' => ($e->source === 'rongyok' && ! $e->video_url) ? route('mirror.request', $e) : null,
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

        {{-- preparing overlay (rongyok episode not mirrored yet) --}}
        <div x-show="preparing" x-cloak class="absolute inset-0 flex flex-col items-center justify-center gap-4 bg-black/85 px-8 text-center">
            <div class="h-10 w-10 animate-spin rounded-full border-2 border-white/20 border-t-brand"></div>
            <div class="text-lg font-semibold">กำลังเตรียมวิดีโอ…</div>
            <div class="max-w-xs text-sm text-cream/60">ตอนนี้ยังไม่ได้ดาวน์โหลดมาเก็บ ระบบได้เพิ่มเข้าคิวโหลดให้แล้ว (โหลดโดยลูกค้า) — เมื่อพร้อมจะเล่นอัตโนมัติ</div>
            <div x-show="requests > 1" class="text-xs text-cream/40">มีผู้ขอดูตอนนี้แล้ว <span x-text="requests"></span> ครั้ง</div>
        </div>

        {{-- gradient + caption --}}
        <div class="pointer-events-none absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/85 to-transparent p-5">
            <div class="text-lg font-bold">{{ $content->title }}</div>
            <div class="text-sm text-cream/70">ตอนที่ <span x-text="episodes[index]?.n"></span> / {{ $eps->count() }}</div>
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
            preparing: false,
            requests: 0,
            _poll: null,
            _requested: new Set(),

            init() {
                if (this.episodes.length) this.load();
            },

            stopPoll() {
                if (this._poll) { clearInterval(this._poll); this._poll = null; }
            },

            async load() {
                this.stopPoll();
                this.preparing = false;
                const ep = this.episodes[this.index];
                if (!ep) return;

                if (ep.url) { this.attach(ep.url); return; }

                const data = await this.tryResolve(ep);
                if (data && data.ready && data.url) { this.attach(data.url); return; }

                // Not available — queue a customer request and poll until it's mirrored.
                this.requests = (data && data.requests) || 0;
                this.preparing = true;
                if (ep.request && !this._requested.has(ep.n)) {
                    this._requested.add(ep.n);
                    try { const r = await window.nxPost(ep.request); if (r && r.requests) this.requests = r.requests; } catch (e) {}
                }
                const myIndex = this.index;
                this._poll = setInterval(async () => {
                    if (this.index !== myIndex) { this.stopPoll(); return; }
                    const d = await this.tryResolve(ep);
                    if (d && d.ready && d.url) { this.stopPoll(); this.preparing = false; this.attach(d.url); }
                }, 8000);
            },

            async tryResolve(ep) {
                if (!ep.resolve) return null;
                try {
                    const r = await fetch(ep.resolve, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    return await r.json();
                } catch (e) { return null; }
            },

            attach(url) {
                this.preparing = false;
                window.nxAttachVideo(this.$refs.video, url);
                this.$refs.video.play?.().catch(() => {});
            },

            next() { if (this.index < this.episodes.length - 1) { this.index++; this.load(); } },
            prev() { if (this.index > 0) { this.index--; this.load(); } },
            togglePlay() { const v = this.$refs.video; v.paused ? v.play() : v.pause(); },
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
