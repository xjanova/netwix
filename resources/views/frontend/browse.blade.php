@extends('layouts.app')
@section('title', $heading ?? 'หน้าแรก')

@section('content')
{{-- Running promo banner — active campaigns (สมัครฟรี 1 ปี / ชวนเพื่อน 1 เดือน + admin promos). --}}
@include('partials.promo-marquee')

{{-- Rotating "หนังตัวอย่าง" billboard — whole-site pool on the home, cached + shared across visitors.
     See partials/hero-billboard.blade.php + App\Support\HeroBillboard. --}}
@if (count($heroSlides ?? []))
    @include('partials.hero-billboard', [
        'heroSlides' => $heroSlides,
        'heroSeconds' => $heroSeconds ?? 8,
        'heroVideo' => $heroVideo ?? true,
        'heroPublic' => false,
    ])
@else
    <div class="h-24"></div>
@endif

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
