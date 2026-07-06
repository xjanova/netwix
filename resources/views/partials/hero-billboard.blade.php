@php
    /** @var array $heroSlides  Slide payloads from App\Support\HeroBillboard::slides() */
    $heroSlides ??= [];
    $heroSeconds ??= 8;      // rotation interval (admin-set); 0 = no auto-rotate
    $heroPublic ??= false;   // public/guest surface → link to the crawlable title page, no member modal
    $heroVideo ??= true;     // admin kill-switch: false → rotating backdrops only, never resolve a stream
@endphp
@if (count($heroSlides))
@php $s0 = $heroSlides[0]; @endphp
{{-- Rotating "หนังตัวอย่าง" billboard. Slide 0 is server-rendered (no flash, and crawlers/no-JS get real
     content); the rotator cross-fades the rest and plays each slide's preview over its backdrop. It picks
     a RANDOM episode each rotation, seeks to a RANDOM scene, starts at a random slide (so concurrent
     visitors sharing the 2-min cached pool still see different titles), and only streams while on-screen. --}}
<section class="relative h-[62vh] min-h-[440px] w-full overflow-hidden bg-black md:h-[82vh]"
         x-data="heroRotator({ slides: @js($heroSlides), seconds: {{ (int) $heroSeconds }}, video: {{ $heroVideo ? 'true' : 'false' }} })" x-init="init()">
    <div class="absolute inset-0" style="background:{{ $s0['gradient'] }}"></div>

    {{-- cross-fading backdrop layer per slide --}}
    <template x-for="(s, i) in slides" :key="i">
        <div class="absolute inset-0 transition-opacity duration-700 ease-out" :class="i === idx ? 'opacity-100' : 'opacity-0'">
            <div class="absolute inset-0" :style="'background:' + s.gradient"></div>
            <img :src="s.backdrop" x-show="s.backdrop" referrerpolicy="no-referrer" onerror="this.style.display='none'"
                 class="absolute inset-0 h-full w-full object-cover object-top" alt="" aria-hidden="true">
        </div>
    </template>

    {{-- current slide's preview plays over its backdrop (muted); backdrop shows when none/off --}}
    <video x-ref="bg" muted loop playsinline preload="none" x-show="videoReady" x-cloak x-transition.opacity.duration.500ms
           class="pointer-events-none absolute inset-0 h-full w-full object-cover object-top"></video>

    <div class="absolute inset-0" style="background:linear-gradient(90deg, rgba(7,5,12,0.85) 0%, rgba(7,5,12,0.25) 45%, rgba(7,5,12,0.1) 65%, rgba(7,5,12,0.6) 100%)"></div>
    <div class="absolute inset-0" style="background:linear-gradient(180deg, rgba(7,5,12,0.15) 0%, transparent 26%, transparent 55%, rgba(7,5,12,0.95) 96%, #07050c 100%)"></div>

    <div class="absolute bottom-[11%] left-[4vw] right-[4vw] max-w-[640px]">
        <div class="nx-gradient mb-4 inline-flex items-center gap-1.5 rounded px-2.5 py-1 text-[11.5px] font-bold tracking-widest" x-show="cur.original" x-cloak>NETWIX ORIGINAL</div>
        <h1 class="mb-4 text-[clamp(30px,5.2vw,64px)] font-extrabold leading-[1.05] drop-shadow-lg" x-text="cur.title">{{ $s0['title'] }}</h1>
        <div class="mb-4 flex flex-wrap items-center gap-3 text-[14.5px] text-cream/75">
            <span class="font-bold text-success" x-text="cur.match + '% ตรงใจ'">{{ $s0['match'] }}% ตรงใจ</span>
            <span x-text="cur.year">{{ $s0['year'] }}</span>
            <span class="rounded border border-cream/40 px-1.5 py-px text-[12.5px]" x-text="cur.maturity">{{ $s0['maturity'] }}</span>
            <span x-text="cur.meta">{{ $s0['meta'] }}</span>
            <template x-if="cur.dub"><span class="rounded px-1.5 py-px text-[12.5px] font-bold" :class="cur.dub === 'พากย์ไทย' ? 'bg-emerald-500/90 text-black' : 'bg-sky-500/90 text-black'" x-text="cur.dub"></span></template>
        </div>
        <p class="mb-6 max-w-xl text-[15.5px] leading-relaxed text-cream/85 drop-shadow line-clamp-3" x-text="cur.synopsis">{{ $s0['synopsis'] }}</p>
        <div class="flex items-center gap-3.5">
            @if ($heroPublic)
                <a :href="cur.show" href="{{ $s0['show'] }}"
                   class="flex items-center gap-2.5 rounded-md bg-cream px-7 py-3 text-base font-bold text-ink transition hover:brightness-90">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg> ดูเลย
                </a>
            @else
                <a :href="cur.watch" href="{{ $s0['watch'] }}"
                   class="flex items-center gap-2.5 rounded-md bg-cream px-7 py-3 text-base font-bold text-ink transition hover:brightness-90">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg> เล่น
                </a>
                <button type="button" @click="$dispatch('open-title', cur.modal)"
                        class="flex items-center gap-2.5 rounded-md bg-[rgba(120,120,130,0.35)] px-6 py-3 text-base font-bold backdrop-blur transition hover:bg-[rgba(120,120,130,0.5)]">
                    ⓘ ข้อมูลเพิ่มเติม
                </button>
            @endif
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

@once
@push('scripts')
<script>
    function heroRotator(cfg) {
        const slides = (cfg.slides || []).filter(Boolean);
        return {
            slides: slides,
            seconds: cfg.seconds || 0,
            video: cfg.video !== false,
            idx: 0,
            cur: slides[0] || {},        // plain reactive prop (a getter isn't tracked by x-text)
            videoReady: false,
            inView: true,
            reduced: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
            _t: null,
            _seq: 0,
            _io: null,
            init() {
                if (!this.slides.length) return;
                // Start on a RANDOM slide so visitors sharing the 2-min cached pool don't all open on
                // the same title. Rotation then cycles the rest (สุ่มเรื่อง ไปเรื่อยๆ).
                this.idx = Math.floor(Math.random() * this.slides.length);
                this.cur = this.slides[this.idx];
                // Only stream while the billboard is actually on screen — scroll past / another tab
                // frees the stream immediately (bandwidth valve for busy public pages).
                this._io = new IntersectionObserver((e) => {
                    const vis = e[0].isIntersecting && e[0].intersectionRatio >= 0.35;
                    if (vis === this.inView) return;
                    this.inView = vis;
                    vis ? this.playClip() : this.stopClip();
                }, { threshold: [0, 0.35] });
                this._io.observe(this.$root);
                // Freeze while a title modal is open — the billboard sits behind it but keeps rotating +
                // streaming otherwise, which both wastes the media budget and is the "วิดีโอเปลี่ยนไปเรื่อยๆ"
                // the viewer sees around the popup.
                window.addEventListener('nx-previews-suspend', () => { this._suspended = true; this.stopClip(); });
                window.addEventListener('nx-previews-resume', () => { this._suspended = false; if (this.inView) this.playClip(); });
                this.arm();
            },
            arm() {
                if (this._t) clearInterval(this._t);
                if (this.seconds > 0 && this.slides.length > 1) {
                    this._t = setInterval(() => this.show((this.idx + 1) % this.slides.length), this.seconds * 1000);
                }
            },
            go(i) { this.show(i); this.arm(); },   // a manual click restarts the countdown
            show(i) {
                if (!this.slides[i]) return;
                this.idx = i;
                this.cur = this.slides[i];
                this.playClip();
            },
            stopClip() {
                const v = this.$refs.bg;
                this.videoReady = false;
                if (!v) return;
                try {
                    if (v._nxLoopOff) v._nxLoopOff();
                    if (v._nxHls) { v._nxHls.destroy(); v._nxHls = null; }
                    v.pause(); v.removeAttribute('src'); v.load();
                } catch (e) {}
            },
            playClip() {
                if (!this.video || this.reduced || !this.inView || this._suspended) return;
                const token = ++this._seq;       // any in-flight resolve for the previous slide is now stale
                this.stopClip();
                this.attach(this.cur, token);
            },
            pickUrl(slide) {
                // สุ่มตอน: a fresh random episode EVERY rotation (URLs are public + ready — no resolve
                // round-trip, so this works for guests too); fall back to the stored ep1 clip.
                const eps = slide.eps || [];
                if (eps.length) return eps[Math.floor(Math.random() * eps.length)];
                return slide.clip || null;
            },
            async attach(slide, token) {
                const url = this.pickUrl(slide);
                const v = this.$refs.bg;
                if (!v || !url || token !== this._seq || !this.inView) return;
                v.muted = true;
                await window.nxAttachVideo(v, url);      // HLS (lazy hls.js) + progressive mp4
                // Slide rotated (or scrolled off) while hls.js loaded → a newer attach owns the element
                // now; just bail (don't stopClip — that would tear down the newer slide's video).
                if (token !== this._seq || !this.inView) return;
                window.nxRandomSeek(v);                  // สุ่มฉาก — random scene (respects the admin toggle)
                window.nxPreviewLoop(v);                 // loop a short window for long dwells (HLS)
                v.play?.().then(() => { if (token === this._seq) this.videoReady = true; }).catch(() => {});
            },
        };
    }
</script>
@endpush
@endonce
@endif
