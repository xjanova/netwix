@extends('layouts.app')
@section('title', $heading ?? 'หน้าแรก')

@section('content')
@if ($hero)
    {{-- Configurable rotating billboard: the pool + interval come from admin Settings (home_hero_source /
         home_hero_seconds). Slide 0 is server-rendered (no flash / SEO); the rotator cross-fades the rest
         and plays each slide's stored clip over its backdrop. seconds = 0 → no auto-rotate. --}}
    <section class="relative h-[62vh] min-h-[440px] w-full overflow-hidden bg-black md:h-[82vh]"
             x-data="heroRotator({ slides: @js($heroSlides), seconds: {{ (int) ($heroSeconds ?? 8) }} })" x-init="init()">
        {{-- base gradient — never a black void before the backdrops paint --}}
        <div class="absolute inset-0" style="background:{{ $hero->gradient }}"></div>

        {{-- cross-fading backdrop layer per slide --}}
        <template x-for="(s, i) in slides" :key="i">
            <div class="absolute inset-0 transition-opacity duration-700 ease-out" :class="i === idx ? 'opacity-100' : 'opacity-0'">
                <div class="absolute inset-0" :style="'background:' + s.gradient"></div>
                <img :src="s.backdrop" x-show="s.backdrop" referrerpolicy="no-referrer" onerror="this.style.display='none'"
                     class="absolute inset-0 h-full w-full object-cover object-top" alt="" aria-hidden="true">
            </div>
        </template>

        {{-- current slide's stored clip plays over its backdrop (muted loop); backdrop shows when none --}}
        <video x-ref="bg" muted loop playsinline preload="none" x-show="videoReady" x-cloak x-transition.opacity.duration.500ms
               class="pointer-events-none absolute inset-0 h-full w-full object-cover object-top"></video>

        <div class="absolute inset-0" style="background:linear-gradient(90deg, rgba(7,5,12,0.85) 0%, rgba(7,5,12,0.25) 45%, rgba(7,5,12,0.1) 65%, rgba(7,5,12,0.6) 100%)"></div>
        <div class="absolute inset-0" style="background:linear-gradient(180deg, rgba(7,5,12,0.15) 0%, transparent 26%, transparent 55%, rgba(7,5,12,0.95) 96%, #07050c 100%)"></div>

        <div class="absolute bottom-[11%] left-[4vw] right-[4vw] max-w-[640px]">
            <div class="nx-gradient mb-4 inline-flex items-center gap-1.5 rounded px-2.5 py-1 text-[11.5px] font-bold tracking-widest" x-show="cur.original" x-cloak>NETWIX ORIGINAL</div>
            <h1 class="mb-4 text-[clamp(30px,5.2vw,64px)] font-extrabold leading-[1.05] drop-shadow-lg" x-text="cur.title">{{ $hero->title }}</h1>
            <div class="mb-4 flex flex-wrap items-center gap-3 text-[14.5px] text-cream/75">
                <span class="font-bold text-success" x-text="cur.match + '% ตรงใจ'">{{ $hero->match_score }}% ตรงใจ</span>
                <span x-text="cur.year">{{ $hero->year }}</span>
                <span class="rounded border border-cream/40 px-1.5 py-px text-[12.5px]" x-text="cur.maturity">{{ $hero->maturity }}</span>
                <span x-text="cur.meta">{{ $hero->type === 'movie' ? ($hero->duration_minutes.' นาที') : ($hero->seasons->count() ?: 1).' ซีซั่น' }}</span>
                <template x-if="cur.dub"><span class="rounded px-1.5 py-px text-[12.5px] font-bold" :class="cur.dub === 'พากย์ไทย' ? 'bg-emerald-500/90 text-black' : 'bg-sky-500/90 text-black'" x-text="cur.dub"></span></template>
            </div>
            <p class="mb-6 max-w-xl text-[15.5px] leading-relaxed text-cream/85 drop-shadow line-clamp-3" x-text="cur.synopsis">{{ $hero->synopsis }}</p>
            <div class="flex items-center gap-3.5">
                <a :href="cur.watch" href="{{ route('watch', $hero) }}"
                   class="flex items-center gap-2.5 rounded-md bg-cream px-7 py-3 text-base font-bold text-ink transition hover:brightness-90">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg> เล่น
                </a>
                <button type="button" @click="$dispatch('open-title', cur.modal)"
                        class="flex items-center gap-2.5 rounded-md bg-[rgba(120,120,130,0.35)] px-6 py-3 text-base font-bold backdrop-blur transition hover:bg-[rgba(120,120,130,0.5)]">
                    ⓘ ข้อมูลเพิ่มเติม
                </button>
            </div>
        </div>

        {{-- slide dots (only when the pool has more than one) --}}
        <template x-if="slides.length > 1">
            <div class="absolute bottom-5 right-[4vw] z-10 flex items-center gap-1.5">
                <template x-for="(s, i) in slides" :key="i">
                    <button type="button" @click="go(i)" :aria-label="'สไลด์ ' + (i + 1)"
                            class="h-1.5 rounded-full transition-all duration-300" :class="i === idx ? 'w-6 bg-cream' : 'w-1.5 bg-cream/40 hover:bg-cream/70'"></button>
                </template>
            </div>
        </template>
    </section>
@else
    <div class="h-24"></div>
@endif

@push('scripts')
<script>
    function heroRotator(cfg) {
        return {
            slides: (cfg.slides && cfg.slides.length) ? cfg.slides : [],
            seconds: cfg.seconds || 0,
            idx: 0,
            videoReady: false,
            _t: null,
            get cur() { return this.slides[this.idx] || {}; },
            init() {
                if (!this.slides.length) return;
                this.playClip();
                this.arm();
            },
            arm() {
                if (this._t) clearInterval(this._t);
                // seconds = 0 → don't auto-rotate (the pool still gave a fresh random one this page load)
                if (this.seconds > 0 && this.slides.length > 1) {
                    this._t = setInterval(() => this.show((this.idx + 1) % this.slides.length), this.seconds * 1000);
                }
            },
            go(i) { this.show(i); this.arm(); },   // a manual click restarts the countdown
            show(i) {
                if (i === this.idx || !this.slides[i]) return;
                this.idx = i;
                this.playClip();
            },
            playClip() {
                const v = this.$refs.bg;
                if (!v) return;
                this.videoReady = false;
                try { v.pause(); v.removeAttribute('src'); v.load(); } catch (e) {}
                const clip = this.cur.clip;
                if (!clip) return;                 // no stored clip → just show the backdrop image
                v.muted = true;
                v.src = clip;
                v.play?.().then(() => { this.videoReady = true; }).catch(() => {});
            },
        };
    }
</script>
@endpush

<div class="relative z-10 -mt-16 pb-10">
    @isset($heading)
        <h1 class="flex items-center gap-3 px-[4vw] pt-4 text-2xl font-bold">
            <span class="nx-gradient h-7 w-1.5 shrink-0 rounded-full" aria-hidden="true"></span>
            <span>{{ $heading }}</span>
        </h1>
    @endisset

    {{-- Personalised infinite-scroll feed (learns your genres, mixes in the rest,
         positions shuffled per session). Rails below stay as they were. --}}
    @isset($feedSeed)
        <section class="pt-6" x-data="recFeed({ seed: {{ $feedSeed }}, url: '{{ route('browse.feed') }}', ver: '{{ @filemtime(public_path('build/manifest.json')) ?: 'x' }}' })" x-init="start()">
            <h2 class="mb-2 flex items-center gap-2 px-[4vw] text-lg font-semibold sm:text-xl">
                <span class="nx-gradient h-5 w-1 shrink-0 rounded-full sm:h-6" aria-hidden="true"></span>
                <span>แนะนำสำหรับคุณ <span class="text-[14px] font-normal text-cream/40">For You</span></span>
            </h2>
            <div class="nx-rail mb-3 px-[4vw] pb-1">
                <button type="button" @click="pick(null)" :class="genre === null ? 'nx-gradient font-semibold' : 'bg-white/5 text-cream/60 hover:text-cream'" class="shrink-0 rounded-full px-4 py-1.5 text-sm transition">ทั้งหมด <span class="opacity-50">All</span></button>
                @foreach ($feedGenres as $g)
                    <button type="button" @click="pick({{ $g->id }})" :class="genre === {{ $g->id }} ? 'nx-gradient font-semibold' : 'bg-white/5 text-cream/60 hover:text-cream'" class="shrink-0 whitespace-nowrap rounded-full px-4 py-1.5 text-sm transition">{{ $g->name }}@if ($g->name_en)<span class="opacity-50"> {{ $g->name_en }}</span>@endif</button>
                @endforeach
            </div>

            {{-- single-row rail like every other row: slide to browse, arrows on hover, and
                 hovering near an edge glides the row the opposite way (faster the closer to the edge) --}}
            <div class="group/row relative" @mousemove="edgeMove($event)" @mouseleave="edgeLeave()">
                <button type="button" @click="scroll(-1)" @mouseenter="edgeStart(-1)" @mouseleave="edgeStop()"
                        class="absolute left-0 top-0 z-20 hidden h-full w-[4vw] items-center justify-center bg-gradient-to-r from-ink/80 to-transparent text-2xl opacity-0 transition group-hover/row:opacity-100 lg:flex">‹</button>
                <div x-ref="rail" class="nx-rail px-[4vw] pb-2" @scroll.passive="onScroll()"></div>
                <button type="button" @click="scroll(1)" @mouseenter="edgeStart(1)" @mouseleave="edgeStop()"
                        class="absolute right-0 top-0 z-20 hidden h-full w-[4vw] items-center justify-center bg-gradient-to-l from-ink/80 to-transparent text-2xl opacity-0 transition group-hover/row:opacity-100 lg:flex">›</button>
            </div>
            <div x-show="loading" x-cloak class="px-[4vw] py-2 text-sm text-cream/50">กำลังโหลด…</div>
            <div x-show="done && !loading" x-cloak class="px-[4vw] py-2 text-sm text-cream/35">— ครบแล้ว —</div>
        </section>

        @push('scripts')
        <script>
            function recFeed(cfg) {
                return {
                    seed: cfg.seed, url: cfg.url, ver: cfg.ver, page: 1, genre: null, loading: false, done: false,
                    _vel: 0, _raf: null,
                    // Cache what's loaded in this tab session so coming back to browse is instant
                    // (survives clicking a title and pressing Back) — keyed per genre filter.
                    ckey() { return 'nxfeed:' + this.ver + ':' + (this.genre ?? 'all'); },   // ver = build mtime → cache auto-busts every deploy
                    cache() {
                        try {
                            const html = this.$refs.rail.innerHTML;
                            if (html.length < 2000000) sessionStorage.setItem(this.ckey(), JSON.stringify({ seed: this.seed, page: this.page, done: this.done, html }));
                        } catch (e) {}
                    },
                    restore() {
                        try {
                            const c = JSON.parse(sessionStorage.getItem(this.ckey()) || 'null');
                            if (c && c.html) { this.seed = c.seed; this.page = c.page; this.done = c.done; this.$refs.rail.innerHTML = c.html; return true; }
                        } catch (e) {}
                        return false;
                    },
                    start() {
                        if (!this.restore()) this.load();
                        else this.$nextTick(() => this.fill());
                    },
                    pick(g) {
                        if (this.genre === g) return;
                        this.genre = g; this.page = 1; this.done = false;
                        this.$refs.rail.scrollLeft = 0;
                        this.$refs.rail.innerHTML = '';
                        if (!this.restore()) this.load();
                        else this.$nextTick(() => this.fill());
                    },
                    async load() {
                        if (this.loading || this.done) return;
                        this.loading = true;
                        try {
                            const u = new URL(this.url, location.origin);
                            u.searchParams.set('seed', this.seed);
                            u.searchParams.set('page', this.page);
                            if (this.genre) u.searchParams.set('genre', this.genre);
                            const r = await fetch(u, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                            const d = await r.json();
                            this.$refs.rail.insertAdjacentHTML('beforeend', d.html);
                            this.page = d.next; this.done = d.done;
                            this.cache();
                        } catch (e) { /* keep the feed alive on a transient error */ } finally {
                            this.loading = false;
                            this.$nextTick(() => this.fill());
                        }
                    },
                    // Keep pulling pages until the row actually overflows (so a single row is never a
                    // near-empty stub), then rely on horizontal scroll to fetch the rest.
                    fill() {
                        const r = this.$refs.rail;
                        if (!r || this.loading || this.done) return;
                        if (r.scrollWidth <= r.clientWidth + 240) this.load();
                    },
                    // Load the next page as the viewer nears the right end (via drag, wheel, or edge-glide).
                    onScroll() {
                        const r = this.$refs.rail;
                        if (r && r.scrollLeft + r.clientWidth >= r.scrollWidth - 800) this.load();
                    },

                    // --- rail controls (same behaviour as the shared nxRail component) ---
                    scroll(dir) {
                        const r = this.$refs.rail;
                        if (!r) return;
                        this._vel = 0;
                        const start = r.scrollLeft, dist = dir * r.clientWidth * 0.85, t0 = performance.now(), dur = 340;
                        const step = (now) => {
                            const p = Math.min(1, (now - t0) / dur);
                            r.scrollLeft = start + dist * (1 - Math.pow(1 - p, 3));
                            if (p < 1) requestAnimationFrame(step);
                        };
                        requestAnimationFrame(step);
                    },
                    edgeMove(e) {
                        if (!window.matchMedia('(any-hover: hover) and (any-pointer: fine)').matches) return; // needs a mouse (true on touch-capable PCs too, false on phones)
                        const r = this.$refs.rail.getBoundingClientRect();
                        const frac = (e.clientX - r.left) / r.width;
                        const zone = 0.16;
                        let v = 0;
                        if (frac < zone) v = -(1 - frac / zone);
                        else if (frac > 1 - zone) v = (frac - (1 - zone)) / zone;
                        this._vel = Math.max(-1, Math.min(1, v));
                        if (this._vel && !this._raf) this._loop();
                    },
                    edgeLeave() { this._vel = 0; },
                    edgeStart(dir) { this._vel = dir; if (!this._raf) this._loop(); },
                    edgeStop() { this._vel = 0; },
                    _loop() {
                        this._raf = requestAnimationFrame(() => {
                            if (this._vel && this.$refs.rail) { this.$refs.rail.scrollLeft += this._vel * 26; this.onScroll(); this._loop(); }
                            else { this._raf = null; }
                        });
                    },
                };
            }
        </script>
        @endpush
    @endisset

    @forelse ($rows as $row)
        <x-content-row :title="$row['title']" :en="$row['en'] ?? null" :items="$row['items']" :ranked="$row['ranked'] ?? false" :link="$row['link'] ?? null" :lazy="$row['lazy'] ?? null" :my-list-ids="$myListIds" />
    @empty
        <div class="px-[4vw] py-20 text-center text-cream/50">ยังไม่มีคอนเทนต์ในหมวดนี้</div>
    @endforelse
</div>
@endsection
