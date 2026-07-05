@extends('layouts.player')
@section('title', $content->title)
@section('meta_keywords', $content->seo_keywords)

@php
    $eps = $episodes->map(fn ($e) => [
        'n' => $e->number,
        'id' => $e->id,
        'title' => $e->title,
        'url' => $e->video_url,
        'resolve' => ($e->source && ! $e->video_url) ? route('episode.source', $e) : null,
        // per-episode cover: captured frame if we have one, else the title's main poster
        'thumb' => $e->thumbnail_path ? $e->thumbnail_url : $content->poster_url,
        'has' => (bool) $e->thumbnail_path,
        'post' => route('episode.thumb', $e),
    ])->values();
@endphp

@section('content')
<div
    x-data="verticalPlayer({ episodes: @js($eps), start: {{ $start }}, progressUrl: '{{ route('content.progress', $content) }}' })"
    x-init="init()"
    @wheel.prevent="onWheel($event)"
    @touchstart.passive="onTouchStart($event)"
    @touchend.passive="onTouchEnd($event)"
    @mousemove="poke()"
    class="relative flex h-[100dvh] select-none items-center justify-center overflow-hidden bg-black"
>
    {{-- Edge glow (dark mode): a small blurred copy of the current frame sits just BEHIND the 9:16
         video, only a little larger than it — so its own colours bleed out as a faint halo around the
         video's edges, and nothing else. No full-screen "Ambilight" wash: the rest of the frame stays
         dark (not glary), while the soft same-colour glow keeps the video's edges from reading as a
         hard cut. Still painted from the live frame (see startAmbi) so the colour always matches. --}}
    <canvas x-ref="ambi" width="54" height="96" aria-hidden="true"
            class="pointer-events-none absolute left-1/2 top-1/2 z-0 h-full w-auto opacity-70"
            style="aspect-ratio:9/16;filter:blur(56px) saturate(1.45);transform:translate(-50%,-50%) scale(1.09)"></canvas>

    <a href="{{ route('browse.vertical') }}" x-show="ui || !playing" x-transition.opacity class="absolute left-4 top-4 z-40 flex h-10 w-10 items-center justify-center rounded-full bg-white/10 text-lg backdrop-blur hover:bg-white/20">✕</a>

    {{-- open the episode grid --}}
    <button @click.stop="epMenu = true" x-show="ui || !playing" x-transition.opacity
            class="absolute left-16 top-4 z-40 flex h-10 items-center gap-1.5 rounded-full bg-white/10 px-4 text-sm font-semibold backdrop-blur hover:bg-white/20">
        ▦ ตอน <span x-text="index + 1"></span>/{{ $eps->count() }}
    </button>

    {{-- episode picker: a FULL-WIDTH grid of covers (captured frame, else the main poster) — tap to
         jump. Fills the whole screen width on PC (not the narrow 9:16 player box) and scrolls
         vertically when a title has many episodes. --}}
    <div x-show="epMenu" x-cloak @click.self="epMenu = false"
         class="absolute inset-0 z-50 flex flex-col bg-black/90 backdrop-blur">
        <div class="flex items-center justify-between px-5 py-4">
            <span class="truncate text-lg font-bold">เลือกตอน · {{ $content->title }}</span>
            <button @click="epMenu = false" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white/10 text-lg hover:bg-white/20">✕</button>
        </div>
        <div class="grid min-h-0 flex-1 content-start gap-4 overflow-y-auto overscroll-contain px-4 pb-8 sm:px-6"
             style="grid-template-columns:repeat(auto-fill,minmax(150px,1fr))">
            <template x-for="(ep, i) in episodes" :key="i">
                {{-- one self-contained card per episode: image + its own footer label + a
                     visible border/shadow, so cards never blur into each other even when
                     several episodes share the same (fallback) cover image. --}}
                <button @click="go(i)"
                        class="group flex flex-col overflow-hidden rounded-xl border border-white/12 bg-[#181022] shadow-md shadow-black/50 transition hover:-translate-y-0.5 hover:border-brand/60 hover:shadow-lg"
                        :class="i === index ? '!border-brand ring-1 ring-brand' : ''">
                    <div class="relative w-full overflow-hidden" style="aspect-ratio:2/3">
                        <div class="absolute inset-0" style="background:linear-gradient(160deg,#241a33,#130f1c)"></div>
                        <img :src="ep.thumb" x-show="ep.thumb" loading="lazy" referrerpolicy="no-referrer"
                             class="absolute inset-0 h-full w-full object-cover transition duration-300 group-hover:scale-105" onerror="this.style.display='none'">
                        <div class="absolute inset-x-0 bottom-0 h-1/3 bg-gradient-to-t from-black/80 to-transparent"></div>
                        <span x-show="i === index" x-cloak class="nx-gradient absolute left-1.5 top-1.5 rounded px-1.5 py-0.5 text-[10px] font-bold">● กำลังดู</span>
                    </div>
                    <div class="flex items-center justify-center border-t border-white/10 bg-black/35 py-2 leading-none">
                        <span class="text-[13px] font-bold text-cream/90" x-text="'ตอน ' + ep.n"></span>
                    </div>
                </button>
            </template>
        </div>
    </div>

    <div class="relative z-[1] h-full max-h-[100dvh] w-auto overflow-hidden rounded-2xl ring-1 ring-white/10"
         style="aspect-ratio:9/16;box-shadow:0 24px 70px -12px rgba(0,0,0,0.75)">
        <video x-ref="video" playsinline autoplay
               :muted="muted"
               @click="togglePlay()"
               @timeupdate="onTime()"
               @ended="next()"
               @play="playing = true" @pause="playing = false"
               @waiting="stall()" @stalled="stall()"
               @playing="resume(); maybeCapture()" @canplay="resume()" @loadeddata="resume()" x-on:error="resume()"
               class="h-full w-full bg-black object-contain"></video>

        {{-- prominent tap-to-unmute (only while muted) — the whole reason people say "no sound" --}}
        <button x-show="muted && !preparing" x-cloak @click.stop="unmute()"
                class="absolute right-4 top-4 z-40 flex items-center gap-2 rounded-full bg-black/60 px-3.5 py-2 text-sm font-semibold backdrop-blur hover:bg-black/80">
            <span class="text-base">🔇</span> แตะเพื่อเปิดเสียง
        </button>

        {{-- center play indicator (shows when paused) --}}
        <button x-show="!playing && !preparing" x-cloak @click.stop="togglePlay()"
                class="absolute left-1/2 top-1/2 z-30 flex h-16 w-16 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-black/45 text-3xl backdrop-blur">▶</button>

        {{-- preparing overlay (episode not resolvable yet) --}}
        <div x-show="preparing" x-cloak class="absolute inset-0 z-30 flex flex-col items-center justify-center gap-4 bg-black/85 px-8 text-center">
            <div class="h-10 w-10 animate-spin rounded-full border-2 border-white/20 border-t-brand"></div>
            <div class="text-lg font-semibold">กำลังเตรียมไฟล์ไว้…</div>
            <div class="max-w-xs text-sm text-cream/60">ตอนนี้กำลังเตรียมพร้อมให้รับชม อีกสักครู่ — เมื่อพร้อมจะเล่นอัตโนมัติ</div>
        </div>

        {{-- branded "connecting to server" loader (buffering, when not preparing) --}}
        <div x-show="buffering && !preparing" x-cloak class="pointer-events-none absolute inset-0 z-20">
            @include('partials.player-loading')
        </div>

        @include('partials.player-watermark')

        {{-- caption --}}
        <div x-show="ui || !playing" x-transition.opacity class="pointer-events-none absolute inset-x-0 bottom-0 z-10 bg-gradient-to-t from-black/85 to-transparent px-5 pb-24 pt-5">
            <div class="text-lg font-bold">{{ $content->title }}</div>
            <div class="text-sm text-cream/70">ตอนที่ <span x-text="episodes[index]?.n"></span> / {{ $eps->count() }}</div>
        </div>

        {{-- up / down episode nav --}}
        <div x-show="ui || !playing" x-transition.opacity class="absolute right-3 top-1/2 z-30 flex -translate-y-1/2 flex-col gap-3">
            <button @click.stop="prev()" :disabled="index === 0" class="flex h-11 w-11 items-center justify-center rounded-full bg-white/10 text-xl backdrop-blur disabled:opacity-30 hover:bg-white/20">↑</button>
            <button @click.stop="next()" :disabled="index === episodes.length - 1" class="flex h-11 w-11 items-center justify-center rounded-full bg-white/10 text-xl backdrop-blur disabled:opacity-30 hover:bg-white/20">↓</button>
        </div>

        {{-- transport controls (fade in on hover / when paused) --}}
        <div x-show="ui || !playing" x-transition.opacity
             class="absolute inset-x-0 bottom-0 z-30 px-4 pb-4" @click.stop>
            {{-- seek --}}
            <input type="range" min="0" max="100" step="0.1" x-model="progress"
                   @input="seek($event.target.value)"
                   class="nx-range w-full">
            <div class="mt-2 flex items-center gap-3 text-sm">
                <button @click="togglePlay()" class="text-lg leading-none" x-text="playing ? '⏸' : '▶'"></button>
                <span class="tabular-nums text-cream/80"><span x-text="fmt(cur)"></span> / <span x-text="fmt(dur)"></span></span>
                <div class="ml-auto flex items-center gap-2">
                    <button @click="toggleMute()" class="text-lg leading-none" x-text="muted ? '🔇' : '🔊'"></button>
                    <input type="range" min="0" max="1" step="0.05" x-model="volume"
                           @input="setVol($event.target.value)"
                           class="nx-range w-20 sm:w-24">
                    <button @click="toggleFs()" class="leading-none" title="เต็มจอ" aria-label="เต็มจอ">
                        <svg x-show="!fs" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3M3 16v3a2 2 0 0 0 2 2h3m8 0h3a2 2 0 0 0 2-2v-3"/></svg>
                        <svg x-show="fs" x-cloak class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3v3a2 2 0 0 1-2 2H3m18 0h-3a2 2 0 0 1-2-2V3M3 16h3a2 2 0 0 1 2 2v3m8 0v-3a2 2 0 0 1 2-2h3"/></svg>
                    </button>
                </div>
            </div>
        </div>

        <div x-show="episodes.length === 0" class="absolute inset-0 flex items-center justify-center text-cream/60">ยังไม่มีตอน</div>
    </div>
</div>

@push('scripts')
<style>
    /* slim, brand-coloured range input for seek + volume */
    .nx-range { -webkit-appearance:none; appearance:none; height:4px; border-radius:9999px;
        background:rgba(255,255,255,0.25); outline:none; cursor:pointer; }
    .nx-range::-webkit-slider-thumb { -webkit-appearance:none; appearance:none; width:13px; height:13px;
        border-radius:50%; background:var(--color-brand, #b026ff); border:0; }
    .nx-range::-moz-range-thumb { width:13px; height:13px; border-radius:50%; background:var(--color-brand, #b026ff); border:0; }
</style>
<script>
    function verticalPlayer(cfg) {
        return {
            episodes: cfg.episodes,
            index: Math.min(cfg.start, Math.max(0, cfg.episodes.length - 1)),
            lock: false,
            touchY: 0,
            preparing: false,
            epMenu: false,
            _poll: null,
            _stallT: null,

            // transport state
            muted: true,
            volume: 1,
            playing: true,
            cur: 0,
            dur: 0,
            progress: 0,
            ui: true,
            fs: false,
            buffering: false,
            _hideT: null,
            _ambi: null,
            _lastSave: 0,

            init() {
                document.addEventListener('fullscreenchange', () => { this.fs = window.nxFullscreenActive(); });
                document.addEventListener('webkitfullscreenchange', () => { this.fs = window.nxFullscreenActive(); });
                // remember the viewer's sound choice across episodes / visits
                this.muted = localStorage.getItem('nx_unmuted') !== '1';
                const v = parseFloat(localStorage.getItem('nx_volume'));
                if (!isNaN(v)) this.volume = Math.min(1, Math.max(0, v));
                if (this.episodes.length) this.load();
                this.startAmbi();
                this.poke();
            },

            // Ambilight: repaint the ambient canvas from the current frame a few times a second. Uses
            // the already-playing <video> as the source (no extra download); a cross-origin frame just
            // taints the canvas, which is fine — we only ever display it, never read its pixels.
            startAmbi() {
                if (this._ambi) return;
                const c = this.$refs.ambi;
                const ctx = c ? c.getContext('2d') : null;
                if (!ctx) return;
                this._ambi = setInterval(() => {
                    const v = this.$refs.video;
                    if (!v || v.readyState < 2) return;
                    try { ctx.drawImage(v, 0, 0, c.width, c.height); } catch (e) {}
                }, 140);
            },

            // show controls on activity, auto-hide while playing
            poke() {
                this.ui = true;
                clearTimeout(this._hideT);
                this._hideT = setTimeout(() => { if (this.playing && !this.preparing) this.ui = false; }, 2600);
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

                // Not resolvable yet — show "preparing" and poll until it becomes available.
                this.preparing = true;
                const myIndex = this.index;
                this._poll = setInterval(async () => {
                    if (this.index !== myIndex) { this.stopPoll(); return; }
                    const d = await this.tryResolve(ep);
                    if (d && d.ready && d.url) { this.stopPoll(); this.preparing = false; this.attach(d.url); }
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
                this.preparing = false;
                this.stall();   // show the loader only if the stream takes >700ms to start
                const v = this.$refs.video;
                window.nxAttachVideo(v, url);
                v.muted = this.muted;
                v.volume = this.volume;
                const p = v.play?.();
                if (p && p.catch) {
                    p.catch(() => {
                        // autoplay-with-sound was blocked → mute so it still plays; user can unmute.
                        v.muted = true; this.muted = true;
                        v.play?.().catch(() => {});
                    });
                }
            },

            onTime() {
                const v = this.$refs.video;
                this.cur = v.currentTime || 0;
                this.dur = v.duration || 0;
                this.progress = this.dur ? (this.cur / this.dur) * 100 : 0;
                this.resume();   // frames are advancing → definitely not stalled, hide the loader
                this.saveProgress();
            },

            // Remember the short you're watching so it lands in "ดูต่อ" — the vertical player never
            // recorded progress before, so nothing you watched here was remembered. Throttled ~10s;
            // percent clamped to 1..94 so an episodic series always reads as in-progress (not 0/done).
            saveProgress() {
                const v = this.$refs.video;
                if (!v || !v.duration || !cfg.progressUrl || !window.nxPost) return;
                const now = performance.now();
                if (now - this._lastSave < 10000) return;
                this._lastSave = now;
                const ep = this.episodes[this.index];
                nxPost(cfg.progressUrl, {
                    percent: Math.min(94, Math.max(1, Math.round((v.currentTime / v.duration) * 100))),
                    position_seconds: Math.round(v.currentTime),
                    episode_id: ep && ep.id ? ep.id : null,
                }).catch(() => {});
            },

            // Show the "connecting" loader only for a stall that actually lasts (>700ms), and hide it
            // the instant playback resumes — so it never sticks over a video that's already playing.
            stall() {
                clearTimeout(this._stallT);
                this._stallT = setTimeout(() => { this.buffering = true; }, 700);
            },
            resume() {
                clearTimeout(this._stallT);
                this.buffering = false;
            },

            seek(pct) {
                const v = this.$refs.video;
                if (v.duration) v.currentTime = (pct / 100) * v.duration;
                this.poke();
            },

            togglePlay() {
                const v = this.$refs.video;
                v.paused ? v.play?.().catch(() => {}) : v.pause();
                this.poke();
            },

            toggleMute() {
                this.muted = !this.muted;
                this.$refs.video.muted = this.muted;
                localStorage.setItem('nx_unmuted', this.muted ? '0' : '1');
                if (!this.muted) {
                    if (this.volume === 0) this.setVol(1);
                    this.$refs.video.play?.().catch(() => {});
                }
                this.poke();
            },

            unmute() {
                if (this.muted) this.toggleMute();
            },

            toggleFs() {
                window.nxToggleFullscreen(this.$root, this.$refs.video, null);
                this.poke();
            },

            setVol(x) {
                x = parseFloat(x);
                this.volume = x;
                this.$refs.video.volume = x;
                localStorage.setItem('nx_volume', String(x));
                if (x > 0 && this.muted) { // moving the slider up implies "I want sound"
                    this.muted = false;
                    this.$refs.video.muted = false;
                    localStorage.setItem('nx_unmuted', '1');
                }
                this.poke();
            },

            fmt(s) {
                s = Math.floor(s || 0);
                return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
            },

            next() { if (this.index < this.episodes.length - 1) { this.index++; this.load(); } },
            prev() { if (this.index > 0) { this.index--; this.load(); } },
            go(i) {
                this.epMenu = false;
                if (i !== this.index) { this.index = i; this.load(); }
            },

            // Covers are generated in the admin panel now (Admin → สร้างปกตอน) —
            // no more on-watch capture.
            maybeCapture() {},
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
