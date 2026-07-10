@props(['content', 'inList' => false, 'ranked' => null])

@php $preview = $content->preview_url; @endphp

<div
    x-data="{
        inList: @js($inList),
        liked: false,
        busy: false,
        hv: false,
        playing: false,
        io: null,
        playT: null,
        reduced: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
        hoverCapable: window.matchMedia('(any-hover: hover) and (any-pointer: fine)').matches,
        // Desktop (mouse): the preview plays only while hovered (see @mouseenter/@mouseleave).
        // Touch/mobile: it plays for the card the viewer is focused on — i.e. well-centered on
        // screen — so only one clip plays at a time, not every card that's merely visible.
        initPreview() {
            const v = this.$refs.clip;
            if (! v || this.reduced || this.hoverCapable) return;
            this.io = new IntersectionObserver((e) => {
                (e[0].isIntersecting && e[0].intersectionRatio >= 0.75) ? this.arm() : this.release();
            }, { threshold: [0, 0.75] });
            this.io.observe(this.$root);
        },
        arm() {
            if (this.playing) return;
            this.playing = true;
            const v = this.$refs.clip;
            this.playT = setTimeout(async () => {
                if (! this.playing) return;
                if (v.dataset.src) {
                    if (! v.src) v.src = v.dataset.src;             // stored clip — lazy fetch once shown
                } else if (v.dataset.resolve && ! v._nxResolved) {
                    // Movies have no stored clip → resolve + play their LIVE stream. Desktop hover ONLY
                    // (never the mobile scroll-autoplay), so it never resolves a stream per centred card.
                    if (! this.hoverCapable) { this.playing = false; return; }
                    v._nxResolved = true;
                    try {
                        const d = await fetch(v.dataset.resolve, { headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(r => r.json());
                        if (! this.playing || ! d || ! d.ready || ! d.url) return;
                        await window.nxAttachVideo(v, d.url);      // handles HLS via hls.js
                        // Left the card while hls.js was loading → don't leave a stream buffering unwatched.
                        if (! this.playing) { if (v._nxHls) { try { v._nxHls.destroy(); } catch (e) {} v._nxHls = null; } return; }
                    } catch (e) { return; }
                }
                window.nxRandomSeek(v);                              // start from a random point, not the top
                window.nxPreviewLoop(v);                             // movies (HLS) loop a short window, like the series clips
                v.play().then(() => { if (this.playing) this.hv = true; })  // crossfade once a frame paints
                        .catch(() => {});
            }, 180);
        },
        release() {
            this.playing = false;
            clearTimeout(this.playT);
            this.hv = false;
            const v = this.$refs.clip;
            if (v) {
                if (v._nxLoopOff) v._nxLoopOff();          // stop the loop watcher
                if (v._nxHls) { try { v._nxHls.destroy(); } catch (e) {} v._nxHls = null; }
                v.pause(); v.removeAttribute('src'); v.load(); v._nxResolved = false; // free + allow re-resolve
            }
        },
        async toggleList() {
            if (this.busy) return; this.busy = true;
            try { this.inList = (await nxPost('{{ route('content.list', $content) }}')).in_list; }
            finally { this.busy = false; }
        },
        async toggleLike() {
            if (this.busy) return; this.busy = true;
            try { this.liked = (await nxPost('{{ route('content.like', $content) }}')).liked; }
            finally { this.busy = false; }
        },
    }"
    x-init="$nextTick(() => initPreview())"
    @mouseenter="hoverCapable && arm()" @mouseleave="hoverCapable && release()"
    class="group relative w-[146px] shrink-0 sm:w-[164px] md:w-[182px]"
>
    @if ($ranked)
        <div class="pointer-events-none absolute -left-2 top-1/2 z-10 -translate-y-1/2 text-[70px] font-extrabold leading-none text-transparent"
             style="-webkit-text-stroke:2px rgba(255,255,255,0.35)">{{ $ranked }}</div>
    @endif

    {{-- NOTE: must be a <div>, not <button> — the hover bar nests <a>/<button>
         inside, and interactive-in-button is invalid HTML that explodes the DOM --}}
    <div role="button" tabindex="0" @click="$dispatch('open-title', '{{ route('title.modal', $content) }}')"
         @keydown.enter="$dispatch('open-title', '{{ route('title.modal', $content) }}')"
         class="block w-full cursor-pointer text-left {{ $ranked ? 'ml-6' : '' }}">
        @php
            // Hover preview: a stored ep1 clip when we have one; movies/HLS have none, so we resolve +
            // play their LIVE stream on hover instead (desktop only — see arm()). When a title has
            // neither, fall back to the animated logo, but only if there's no cover art.
            $resolveUrl = (! $preview && ($pe = $content->previewEpisode) && $pe->source) ? route('episode.source', $pe) : null;
            $hasRealPreview = (bool) ($preview || $resolveUrl);
            $logoClip = asset('assets/'.($content->id % 2 ? 'logomedia2.mp4' : 'logomedia1.mp4'));
            $hoverClip = $preview ?: ((! $hasRealPreview && ! $content->poster_url) ? $logoClip : null);
        @endphp
        <div class="relative aspect-[9/16] overflow-hidden rounded-xl ring-1 ring-white/5 transition duration-200 group-hover:ring-2 group-hover:ring-white/25"
             style="background:{{ $content->poster_url ? '#0e0a17' : $content->gradient }}">
            @if ($content->poster_url)
                <img src="{{ $content->poster_url }}" alt="{{ $content->title }}" loading="lazy"
                     referrerpolicy="no-referrer" onerror="this.style.display='none'"
                     class="absolute inset-0 h-full w-full object-cover object-top">
            @else
                {{-- no cover art → branded placeholder (fades out while the clip plays) --}}
                <div class="absolute inset-0 flex flex-col items-center justify-center gap-1.5 px-3 text-center transition-opacity duration-300"
                     :class="hv ? 'opacity-0' : 'opacity-100'">
                    <img src="{{ asset('assets/netwix-icon.png') }}" alt="" class="h-9 w-9 opacity-40">
                    <span class="line-clamp-2 text-[13px] font-semibold text-cream/80">{{ $content->title }}</span>
                </div>
            @endif

            {{-- silent auto-preview: the ep1 clip plays over the cover (or logo)
                 whenever the card is on screen — muted, looping, no controls.
                 Lazy — src is only fetched once visible (see initPreview/arm), so
                 nothing downloads for cards that never scroll into view. --}}
            @if ($hoverClip || $resolveUrl)
                <video x-ref="clip" aria-hidden="true"
                       @if ($hoverClip) data-src="{{ $hoverClip }}" @endif
                       @if ($resolveUrl) data-resolve="{{ $resolveUrl }}" @endif
                       muted loop playsinline preload="none"
                       class="absolute inset-0 h-full w-full object-cover object-top transition-opacity duration-300 {{ $hasRealPreview ? '' : 'mix-blend-screen' }}"
                       :class="hv ? '{{ $hasRealPreview ? 'opacity-100' : 'opacity-90' }}' : 'opacity-0'"></video>
            @endif

            <div class="absolute left-2 top-2 z-10 flex flex-col items-start gap-1">
                @if ($content->is_new)
                    <span class="rounded bg-gradient-to-r from-brand to-[#ff5a7a] px-1.5 py-0.5 text-[9px] font-extrabold tracking-wide text-white shadow" title="เพิ่งนำเข้าใหม่ภายใน 7 วัน">🆕 มาใหม่</span>
                @endif
                @if ($content->link_under_review)
                    <span class="rounded bg-gold/90 px-1.5 py-0.5 text-[9px] font-bold text-black shadow" title="กำลังตรวจสอบลิงก์ อาจดูไม่ได้ชั่วคราว">🚧 ตรวจสอบลิงก์</span>
                @endif
                @if ($content->is_original)
                    <span class="nx-gradient rounded px-1.5 py-0.5 text-[9px] font-bold tracking-widest">NETWIX</span>
                @endif
                @if ($content->requires_pro)
                    <span class="flex items-center gap-0.5 rounded bg-gradient-to-r from-gold to-[#ffcf5a] px-1.5 py-0.5 text-[9px] font-extrabold tracking-wide text-black shadow" title="ต้องเป็นสมาชิก Pro">👑 PRO</span>
                @endif
                @if ($content->dub_label)
                    <span class="rounded px-1.5 py-0.5 text-[9px] font-bold {{ $content->dub_type === 'thai_dub' ? 'bg-emerald-500/90 text-black' : 'bg-sky-500/90 text-black' }}">{{ $content->dub_label }}</span>
                @endif
            </div>
            <span class="absolute right-2 top-2 z-10 rounded px-1.5 py-0.5 text-[10px] font-semibold {{ $content->is_adult ? 'bg-gold text-black' : 'bg-black/55' }}">{{ $content->maturity }}</span>

            {{-- hover action bar --}}
            <div class="absolute inset-x-0 bottom-0 flex translate-y-1 items-center justify-between bg-gradient-to-t from-black/85 to-transparent p-2 opacity-0 transition duration-200 group-hover:translate-y-0 group-hover:opacity-100">
                <div class="flex items-center gap-1.5">
                    <a href="{{ route('watch', $content) }}" @click.stop
                       class="flex h-7 w-7 items-center justify-center rounded-full bg-cream text-black" title="เล่น">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                    </a>
                    <button type="button" @click.stop="toggleList"
                            class="flex h-7 w-7 items-center justify-center rounded-full border border-cream/60 text-sm" title="รายการของฉัน">
                        <span x-text="inList ? '✓' : '+'"></span>
                    </button>
                    <button type="button" @click.stop="toggleLike"
                            class="flex h-7 w-7 items-center justify-center rounded-full border border-cream/60 text-[13px]"
                            :style="liked ? 'color:#ff2d55' : ''" title="ถูกใจ">♥</button>
                </div>
                <span class="text-[11px] font-bold text-success">{{ $content->match_score }}% ตรงใจ</span>
            </div>
        </div>
    </div>

    <div class="mt-2 {{ $ranked ? 'ml-6' : '' }}">
        <div class="truncate text-[13px] font-medium">{{ $content->title }}</div>
        <div class="truncate text-[11px] text-cream/45">
            {{ $content->primaryGenre()?->name }} · {{ $content->year }}
        </div>
        {{-- view count (👁) — "ยังไม่มีคนดู" when nobody has watched yet --}}
        <div class="mt-0.5 flex items-center gap-1 truncate text-[11px] {{ $content->views > 0 ? 'text-cream/40' : 'text-cream/30' }}">
            <svg class="h-3 w-3 shrink-0 opacity-70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>
            </svg>
            <span class="truncate">{{ $content->views_label }}</span>
        </div>
    </div>
</div>
