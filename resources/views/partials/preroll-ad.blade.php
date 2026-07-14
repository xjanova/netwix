{{-- Pre-roll ad overlay. Rendered inside a player root; sits on top (z-70) until the viewer skips or the
     creative ends, then dispatches `nx-preroll-done` — the host player gates its FIRST playback on that
     event (see watchPlayer/verticalPlayer init). Self-contained: works for the horizontal watch player
     and the vertical shorts player. Nothing renders when $ad is null. --}}
@if (! empty($ad))
<script>
    // Runs at parse time (before Alpine inits the player) so watchPlayer/verticalPlayer.init() can read
    // whether a pre-roll is pending. Frequency gate lives here: `session`/`daily` suppress repeats.
    (function () {
        var a = @json($ad);
        var pending = true;
        try {
            var key = 'nx_ad_' + a.id;
            if (a.frequency === 'session') pending = ! sessionStorage.getItem(key);
            else if (a.frequency === 'daily') {
                var last = parseInt(localStorage.getItem(key) || '0', 10);
                pending = ! last || (Date.now() - last) > 86400000;
            }
        } catch (e) {}
        window.nxPreroll = { ad: a, pending: pending, done: ! pending };
    })();
</script>

<div x-data="prerollAd()" x-init="init()" x-show="show" x-cloak
     @touchstart.stop @touchend.stop @wheel.stop.prevent
     class="absolute inset-0 z-[70] flex items-center justify-center overflow-hidden bg-black">

    {{-- still image --}}
    <template x-if="a.media_type === 'image' && a.src">
        <img :src="a.src" alt="โฆษณา" @click.stop="clickThrough()"
             :class="a.link_url ? 'cursor-pointer' : ''"
             class="max-h-full max-w-full object-contain">
    </template>

    {{-- uploaded / direct video (mp4·webm·m3u8-on-Safari) --}}
    <template x-if="a.media_type === 'video' && ! a.youtube && a.src">
        <video x-ref="adVideo" :src="a.src" playsinline autoplay muted
               @ended="onEnded()" @click.stop="unmute()" x-on:error="finish()"
               class="h-full w-full bg-black object-contain"></video>
    </template>

    {{-- YouTube creative (iframe; pointer-events off so our overlay controls stay clickable) --}}
    <template x-if="a.media_type === 'video' && a.youtube">
        <iframe :src="'https://www.youtube.com/embed/' + a.youtube + '?autoplay=1&rel=0&modestbranding=1&playsinline=1&controls=0'"
                class="pointer-events-none h-full w-full border-0" allow="autoplay; encrypted-media"></iframe>
    </template>

    {{-- "AD" badge --}}
    <span class="pointer-events-none absolute left-4 top-4 z-10 rounded bg-black/60 px-2 py-1 text-[11px] font-bold tracking-wider text-cream/90 backdrop-blur">โฆษณา</span>

    {{-- tap-to-unmute (direct video, while muted) --}}
    <button x-show="a.media_type === 'video' && ! a.youtube && muted" x-cloak @click.stop="unmute()"
            class="absolute left-1/2 top-4 z-10 flex -translate-x-1/2 items-center gap-2 rounded-full bg-black/60 px-3.5 py-2 text-sm font-semibold backdrop-blur hover:bg-black/80">
        <span class="text-base">🔇</span> แตะเพื่อเปิดเสียง
    </button>

    {{-- caption + click-through --}}
    <div x-show="a.caption || a.link_url" x-cloak class="absolute inset-x-0 bottom-0 z-10 bg-gradient-to-t from-black/85 to-transparent px-5 pb-6 pt-10">
        <p x-show="a.caption" class="mx-auto max-w-2xl text-center text-sm font-medium text-cream/90 sm:text-base" x-text="a.caption"></p>
        <div x-show="a.link_url" x-cloak class="mt-2 text-center">
            <button @click.stop="clickThrough()" class="btn-brand px-4 py-1.5 text-xs">ดูเพิ่มเติม →</button>
        </div>
    </div>

    {{-- skip button / countdown --}}
    <div class="absolute bottom-4 right-4 z-20" @click.stop>
        <button x-show="canSkip" x-cloak @click="skip()"
                class="rounded-lg bg-white/90 px-4 py-2.5 text-sm font-bold text-black shadow-lg transition hover:bg-white">ข้ามโฆษณา ⏭</button>
        <div x-show="! canSkip && canSkipEver" x-cloak class="rounded-lg bg-black/60 px-4 py-2.5 text-sm text-cream/80 backdrop-blur">
            ข้ามได้ใน <span x-text="remaining"></span> วิ
        </div>
        <div x-show="! canSkipEver" x-cloak class="rounded-lg bg-black/50 px-4 py-2 text-[11px] text-cream/55 backdrop-blur">โฆษณา · ข้ามไม่ได้</div>
    </div>
</div>

@push('scripts')
<script>
    function prerollAd() {
        return {
            a: {},
            show: false,
            canSkip: false,       // skip button visible now
            canSkipEver: true,    // this ad allows skipping at all
            remaining: 0,         // seconds left on the skip countdown
            muted: true,
            _t: null,             // countdown ticker
            _end: null,           // auto-finish timer (image duration / YouTube safety cap)

            init() {
                // Decided synchronously by the inline script above; nothing to show if not pending.
                if (! window.nxPreroll || ! window.nxPreroll.pending) { this.show = false; return; }
                this.a = window.nxPreroll.ad || {};
                const yt = ! ! this.a.youtube;
                // A YouTube iframe has no reliable "ended" — never trap the viewer on it.
                this.canSkipEver = ! ! this.a.skippable || yt;
                this.show = true;
                this._recordFreq();

                this.remaining = this.canSkipEver ? Math.max(0, this.a.skip_after | 0) : 0;
                this.canSkip = this.canSkipEver && this.remaining <= 0;
                if (this.canSkipEver && this.remaining > 0) {
                    this._t = setInterval(() => {
                        if (--this.remaining <= 0) { this.canSkip = true; this._clearTick(); }
                    }, 1000);
                }

                if (this.a.media_type === 'image') {
                    this._end = setTimeout(() => this.finish(), Math.max(3, this.a.image_seconds | 0) * 1000);
                } else if (yt) {
                    this._end = setTimeout(() => this.finish(), 120000);   // hard cap so it always proceeds
                }
                this.$nextTick(() => this._playVideo());
            },

            _recordFreq() {
                try {
                    const key = 'nx_ad_' + this.a.id;
                    if (this.a.frequency === 'session') sessionStorage.setItem(key, '1');
                    else if (this.a.frequency === 'daily') localStorage.setItem(key, String(Date.now()));
                } catch (e) {}
            },

            // Autoplay must be muted; the tap-to-unmute pill restores sound on a real gesture.
            _playVideo() {
                const v = this.$refs.adVideo;
                if (! v) return;
                v.muted = true; this.muted = true;
                const p = v.play && v.play();
                if (p && p.catch) p.catch(() => {});
            },
            unmute() {
                const v = this.$refs.adVideo;
                if (! v) return;
                v.muted = false; this.muted = false;
                v.play && v.play().catch(() => {});
            },

            onEnded() { this.finish(); },
            skip() { if (this.canSkip) this.finish(); },
            clickThrough() {
                if (this.a.link_url) { try { window.open(this.a.link_url, '_blank', 'noopener'); } catch (e) {} }
            },

            _clearTick() { if (this._t) { clearInterval(this._t); this._t = null; } },
            // Remove the overlay and let the host player start its real video.
            finish() {
                if (! this.show) return;
                this.show = false;
                this._clearTick();
                if (this._end) { clearTimeout(this._end); this._end = null; }
                const v = this.$refs.adVideo;
                if (v) { try { v.pause(); } catch (e) {} }
                if (window.nxPreroll) window.nxPreroll.done = true;
                window.dispatchEvent(new CustomEvent('nx-preroll-done'));
            },
        };
    }
</script>
@endpush
@endif
