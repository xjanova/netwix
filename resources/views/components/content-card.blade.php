@props(['content', 'inList' => false, 'ranked' => null])

<div
    x-data="{
        inList: @js($inList),
        liked: false,
        busy: false,
        hv: false,
        hovering: false,
        hoverT: null,
        // only true-hover, fine-pointer, non-reduced-motion devices arm the clip
        canHover: window.matchMedia('(hover: hover) and (pointer: fine)').matches
                  && ! window.matchMedia('(prefers-reduced-motion: reduce)').matches,
        playClip() {
            if (! this.canHover) return;
            const v = this.$refs.clip;
            if (! v) return;
            this.hovering = true;
            // hover-intent delay: a fast sweep across a rail never fetches anything
            this.hoverT = setTimeout(() => {
                if (! this.hovering) return;
                if (! v.src) v.src = v.dataset.src;                 // lazy: fetch on real hover only
                v.play().then(() => { if (this.hovering) this.hv = true; })  // crossfade once a frame paints
                        .catch(() => {});
            }, 220);
        },
        stopClip() {
            this.hovering = false;
            clearTimeout(this.hoverT);
            this.hv = false;
            const v = this.$refs.clip;
            if (v) { v.pause(); v.removeAttribute('src'); v.load(); } // release the buffer, don't just pause
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
    class="group relative w-[210px] shrink-0 sm:w-[240px] md:w-[262px]"
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
        <div class="relative aspect-video overflow-hidden rounded-lg ring-1 ring-white/5 transition duration-200 group-hover:ring-2 group-hover:ring-white/25"
             style="background:{{ $content->backdrop_url ? '#0e0a17' : $content->gradient }}"
             @if (! $content->backdrop_url) @mouseenter="playClip()" @mouseleave="stopClip()" @endif>
            @if ($content->backdrop_url)
                <img src="{{ $content->backdrop_url }}" alt="{{ $content->title }}" loading="lazy"
                     referrerpolicy="no-referrer" onerror="this.style.display='none'"
                     class="absolute inset-0 h-full w-full object-cover">
            @else
                {{-- no image → branded placeholder; hovering plays an animated logo clip
                     (lazy: src is only set on first hover, so nothing downloads until then) --}}
                <video x-ref="clip" aria-hidden="true" data-src="{{ asset('assets/'.($content->id % 2 ? 'logomedia2.mp4' : 'logomedia1.mp4')) }}"
                       muted loop playsinline preload="none"
                       class="absolute inset-0 h-full w-full object-cover mix-blend-screen transition-opacity duration-300"
                       :class="hv ? 'opacity-90' : 'opacity-0'"></video>
                <div class="absolute inset-0 flex flex-col items-center justify-center gap-1.5 px-3 text-center transition-opacity duration-300"
                     :class="hv ? 'opacity-0' : 'opacity-100'">
                    <img src="{{ asset('assets/netwix-icon.png') }}" alt="" class="h-9 w-9 opacity-40">
                    <span class="line-clamp-2 text-[13px] font-semibold text-cream/80">{{ $content->title }}</span>
                </div>
            @endif

            @if ($content->is_original)
                <span class="nx-gradient absolute left-2 top-2 rounded px-1.5 py-0.5 text-[9px] font-bold tracking-widest">NETWIX</span>
            @endif
            <span class="absolute right-2 top-2 rounded bg-black/55 px-1.5 py-0.5 text-[10px] font-semibold">{{ $content->maturity }}</span>

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
