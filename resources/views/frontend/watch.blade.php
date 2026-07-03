@extends('layouts.player')
@section('title', $content->title)

@php
    $eps = $episodes->map(fn ($e) => [
        'n' => $e->number,
        'title' => $e->title,
        'id' => $e->id,
        'url' => $e->video_url,
        'resolve' => ($e->source && ! $e->video_url) ? route('episode.source', $e) : null,
        'thumb' => $e->thumbnail_path ? $e->thumbnail_url : $content->poster_url,
        'has' => (bool) $e->thumbnail_path,
        'post' => route('episode.thumb', $e),
    ])->values();
    // Manual movie with a URL on the content itself and no episode rows → one synthetic entry.
    if ($eps->isEmpty() && $content->video_url) {
        $eps = collect([[
            'n' => 1, 'title' => $content->title, 'id' => null,
            'url' => $content->video_url, 'resolve' => null,
            'thumb' => $content->poster_url, 'has' => true, 'post' => null,
        ]]);
    }
@endphp

@section('content')
<div class="relative h-[100dvh] w-full bg-black"
     x-data="watchPlayer({
        progressUrl: '{{ route('content.progress', $content) }}',
        episodes: @js($eps),
        start: {{ $startIndex }},
        youtube: @js($youtubeId),
        hasMedia: @js((bool) $youtubeId || $eps->isNotEmpty()),
     })"
     x-init="init()">

    {{-- top bar --}}
    <div class="absolute inset-x-0 top-0 z-30 flex items-center gap-3 bg-gradient-to-b from-black/80 to-transparent p-4">
        <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('browse') }}"
           class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-white/10 text-xl backdrop-blur hover:bg-white/20">‹</a>
        <div class="min-w-0">
            <div class="truncate text-base font-semibold">{{ $content->title }}</div>
            <div x-show="episodes.length > 1" x-cloak x-text="'ตอนที่ ' + (episodes[index]?.n || '')" class="text-xs text-cream/60"></div>
        </div>

        @unless ($youtubeId)
            <button type="button" x-show="episodes.length > 1" x-cloak @click="epMenu = true"
                    class="ml-auto flex h-10 items-center gap-1.5 rounded-full bg-white/10 px-4 text-sm font-semibold backdrop-blur hover:bg-white/20">
                ▦ เลือกตอน
            </button>
            <button type="button" @click="toggleFs()" title="เต็มจอ" aria-label="เต็มจอ"
                    :class="episodes.length > 1 ? '' : 'ml-auto'"
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-white/10 backdrop-blur hover:bg-white/20">
                <svg x-show="!fs" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3M3 16v3a2 2 0 0 0 2 2h3m8 0h3a2 2 0 0 0 2-2v-3"/></svg>
                <svg x-show="fs" x-cloak class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3v3a2 2 0 0 1-2 2H3m18 0h-3a2 2 0 0 1-2-2V3M3 16h3a2 2 0 0 1 2 2v3m8 0v-3a2 2 0 0 1 2-2h3"/></svg>
            </button>
        @endunless
    </div>

    @if ($youtubeId)
        <iframe src="https://www.youtube.com/embed/{{ $youtubeId }}?autoplay=1&rel=0&modestbranding=1&playsinline=1"
                @load="loading = false"
                class="h-full w-full border-0" allow="autoplay; encrypted-media; fullscreen" allowfullscreen></iframe>
    @elseif ($eps->isNotEmpty())
        <video x-ref="video" controls autoplay playsinline
               @timeupdate.throttle.10000ms="saveProgress()"
               @timeupdate="resume()"
               @ended="onEnded()"
               @waiting="stall()" @stalled="stall()"
               @playing="resume(); maybeCapture()" @canplay="resume()" @loadeddata="resume()" x-on:error="resume()"
               class="h-full w-full bg-black object-contain"></video>
        <div x-show="err" x-cloak class="pointer-events-none absolute inset-0 flex items-center justify-center px-6 text-center text-cream/70">
            <span x-text="err"></span>
        </div>

        {{-- episode picker (covers = captured frame, else main poster) — tap to jump --}}
        <div x-show="epMenu" x-cloak @click.self="epMenu = false"
             class="absolute inset-0 z-50 flex flex-col bg-black/90 backdrop-blur">
            <div class="flex items-center justify-between px-5 py-4">
                <span class="truncate text-lg font-bold">เลือกตอน · {{ $content->title }}</span>
                <button @click="epMenu = false" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white/10 text-lg hover:bg-white/20">✕</button>
            </div>
            <div class="grid flex-1 content-start gap-2.5 overflow-y-auto px-5 pb-8" style="grid-template-columns:repeat(auto-fill,minmax(148px,1fr))">
                <template x-for="(ep, i) in episodes" :key="i">
                    <button @click="go(i)" style="aspect-ratio:16/9"
                            class="group relative overflow-hidden rounded-lg ring-1 ring-white/10 transition hover:ring-2 hover:ring-white/40"
                            :class="i === index ? '!ring-2 !ring-brand' : ''">
                        <div class="absolute inset-0" style="background:linear-gradient(160deg,#241a33,#130f1c)"></div>
                        <img :src="ep.thumb" x-show="ep.thumb" loading="lazy" referrerpolicy="no-referrer"
                             class="absolute inset-0 h-full w-full object-cover" onerror="this.style.display='none'">
                        <div class="absolute inset-0 bg-gradient-to-t from-black/85 via-transparent to-transparent"></div>
                        <div class="absolute bottom-1.5 left-2 flex items-baseline gap-1 leading-none drop-shadow-[0_1px_3px_rgba(0,0,0,0.9)]">
                            <span class="text-[19px] font-extrabold" x-text="ep.n"></span>
                            <span class="text-[11px] font-medium text-cream/60">ตอน</span>
                        </div>
                        <span x-show="i === index" x-cloak class="nx-gradient absolute right-1.5 top-1.5 rounded px-1.5 py-0.5 text-[10px] font-bold">● กำลังดู</span>
                    </button>
                </template>
            </div>
        </div>
    @else
        <div class="flex h-full items-center justify-center px-6 text-center text-cream/60">
            ยังไม่มีไฟล์วิดีโอสำหรับเรื่องนี้ — เพิ่มลิงก์วิดีโอได้ในแผงผู้ดูแล
        </div>
    @endif

    {{-- branded "connecting to server" loader (only for a real stall) --}}
    <div x-show="loading && ! err" x-cloak class="pointer-events-none absolute inset-0 z-40">
        @include('partials.player-loading')
    </div>
</div>

@push('scripts')
<script>
    function watchPlayer(cfg) {
        return {
            episodes: cfg.episodes || [],
            index: Math.min(cfg.start || 0, Math.max(0, (cfg.episodes || []).length - 1)),
            epMenu: false,
            err: '',
            fs: false,
            loading: cfg.hasMedia,
            _stallT: null,
            _poll: null,

            // loader shows only for a stall that lasts (>700ms) and hides the moment playback resumes
            stall() { clearTimeout(this._stallT); this._stallT = setTimeout(() => { this.loading = true; }, 700); },
            resume() { clearTimeout(this._stallT); this.loading = false; },

            init() {
                document.addEventListener('fullscreenchange', () => { this.fs = window.nxFullscreenActive(); });
                document.addEventListener('webkitfullscreenchange', () => { this.fs = window.nxFullscreenActive(); });
                if (cfg.youtube || !this.$refs.video) return;
                if (this.episodes.length) this.load();
            },

            stopPoll() { if (this._poll) { clearInterval(this._poll); this._poll = null; } },

            async load() {
                this.stopPoll();
                this.err = '';
                const ep = this.episodes[this.index];
                if (!ep) return;
                if (ep.url) { this.attach(ep.url); return; }

                const d = await this.tryResolve(ep);
                if (d && d.ready && d.url) { this.attach(d.url); return; }

                // Not resolvable yet — show the loader and poll until it becomes available.
                this.stall();
                const my = this.index;
                this._poll = setInterval(async () => {
                    if (this.index !== my) { this.stopPoll(); return; }
                    const r = await this.tryResolve(ep);
                    if (r && r.ready && r.url) { this.stopPoll(); this.attach(r.url); }
                }, 10000);
            },
            async tryResolve(ep) {
                if (!ep.resolve) return null;
                try {
                    const r = await fetch(ep.resolve, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    return await r.json();
                } catch (e) { return null; }
            },
            attach(url) {
                this.stall();
                const v = this.$refs.video;
                window.nxAttachVideo(v, url);
                v.play?.().catch(() => {});
            },

            go(i) { this.epMenu = false; if (i !== this.index) { this.index = i; this.load(); } },
            next() { if (this.index < this.episodes.length - 1) { this.index++; this.load(); } },
            onEnded() {
                this.saveProgress(100);
                this.next();   // auto-play the next episode
            },

            // grab a small frame as this episode's cover, once, if it has none yet (see vertical player)
            maybeCapture() {
                const ep = this.episodes[this.index];
                if (!ep || ep.has || ep._cap || !ep.post || !window.nxPost) return;
                ep._cap = true;
                const v = this.$refs.video;
                const delay = 5000 + Math.floor(Math.random() * 18000);
                setTimeout(() => {
                    if (this.episodes[this.index] !== ep) return;
                    if (!v.videoWidth || v.readyState < 2 || v.paused) return;
                    try {
                        const w = 320, h = Math.round(w * v.videoHeight / v.videoWidth) || 180;
                        const cv = document.createElement('canvas'); cv.width = w; cv.height = h;
                        cv.getContext('2d').drawImage(v, 0, 0, w, h);
                        const img = cv.toDataURL('image/jpeg', 0.62);
                        nxPost(ep.post, { image: img })
                            .then((r) => { if (r && r.ok) { ep.has = true; if (r.url) ep.thumb = r.url; } })
                            .catch(() => {});
                    } catch (e) { /* cross-origin without CORS → keep poster fallback */ }
                }, delay);
            },

            toggleFs() { window.nxToggleFullscreen(this.$root, this.$refs.video, 'landscape'); },

            saveProgress(force = null) {
                const v = this.$refs.video;
                if (!v || !v.duration) return;
                const ep = this.episodes[this.index];
                const percent = force ?? Math.round((v.currentTime / v.duration) * 100);
                nxPost(cfg.progressUrl, {
                    percent: Math.min(100, Math.max(0, percent)),
                    position_seconds: Math.round(v.currentTime),
                    episode_id: ep ? ep.id : null,
                }).catch(() => {});
            },
        };
    }
</script>
@endpush
@endsection
