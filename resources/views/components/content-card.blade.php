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
            this.playT = setTimeout(() => {
                if (! this.playing) return;
                if (! v.src) v.src = v.dataset.src;                 // lazy: fetch only once shown
                window.nxRandomSeek(v);                              // start from a random point, not the top
                v.play().then(() => { if (this.playing) this.hv = true; })  // crossfade once a frame paints
                        .catch(() => {});
            }, 180);
        },
        release() {
            this.playing = false;
            clearTimeout(this.playT);
            this.hv = false;
            const v = this.$refs.clip;
            if (v) { v.pause(); v.removeAttribute('src'); v.load(); } // free the buffer off-screen
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
            // The real hover preview is the stored ep1 clip. When a title has no
            // clip yet, fall back to the animated logo — but only if there's no
            // cover art (a logo looping over a real poster looks off).
            $logoClip = asset('assets/'.($content->id % 2 ? 'logomedia2.mp4' : 'logomedia1.mp4'));
            $hoverClip = $preview ?: (! $content->poster_url ? $logoClip : null);
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
            @if ($hoverClip)
                <video x-ref="clip" aria-hidden="true" data-src="{{ $hoverClip }}"
                       muted loop playsinline preload="none"
                       class="absolute inset-0 h-full w-full object-cover object-top transition-opacity duration-300 {{ $preview ? '' : 'mix-blend-screen' }}"
                       :class="hv ? '{{ $preview ? 'opacity-100' : 'opacity-90' }}' : 'opacity-0'"></video>
            @endif

            <div class="absolute left-2 top-2 z-10 flex flex-col items-start gap-1">
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
    </div>
</div>
