{{--
    Shared "เลือกตอน" overlay for BOTH players (watch.blade.php 16:9 · vertical-player.blade.php 2:3).

    Owner, 2026-08-20: "ปกหนังตอนที่เลือกตอน ควรมีขนาดที่เหมาะสม เพื่อเห็นชัดเจน คงที่ แล้วใช้สกรอเลือกแทน
    เพราะตอนนี้มันพยายามแสดงตอนทั้งหมดในหน้าเดียว ทำให้บีบอัดดูไม่รู้เรื่อง หากมีตอนเยอะ" — so:

    1. FIXED tile width. The old grids were `repeat(auto-fill,minmax(150px,1fr))`, and the `1fr` is the
       problem: the tile size is whatever is left over after the browser divides the container, so the
       same picker showed a comfortable cover on one screen and a stamp on another. Here the column IS
       the tile — a plain px width per breakpoint — and `justify-content:center` absorbs the slack
       instead of the covers doing it.
    2. RANGE CHUNKS. 500 episodes (Naruto Shippuden) in one grid is a wall nobody can aim at, and it
       also means 500 <img> in the DOM. Only one chunk (50) is rendered at a time; the rest are one tap
       away on the range pills, and opening the picker lands on the chunk you are actually watching.
    3. The grid scrolls; the header and the pills do not.

    Expects, from the surrounding Alpine component (see nxEpPicker() below for the state half):
      episodes[] ({n, thumb}) · index · go(i) · epMenu · epChunk · epRanges() · epVisible()

    param $ratio —  cover aspect — '16/9' for captured frames, '2/3' for portrait posters
--}}
@php $wide = ($ratio ?? '16/9') === '16/9'; @endphp

<div x-show="epMenu" x-cloak @click.self="epMenu = false"
     class="absolute inset-0 z-50 flex flex-col bg-black/90 backdrop-blur">

    <div class="flex shrink-0 items-center justify-between gap-3 px-5 py-4">
        <div class="min-w-0">
            <div class="truncate text-lg font-bold">เลือกตอน · {{ $content->title }}</div>
            <div class="text-xs text-cream/50"><span x-text="episodes.length"></span> ตอน</div>
        </div>
        <button type="button" @click="epMenu = false" aria-label="ปิด"
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white/10 text-lg hover:bg-white/20">✕</button>
    </div>

    {{-- Range pills — only when the title actually has more episodes than one chunk. Horizontally
         scrollable so 500 episodes (10 pills) never wrap the header into three lines. --}}
    <div x-show="epRanges().length > 1" x-cloak
         class="no-scrollbar flex shrink-0 gap-2 overflow-x-auto px-5 pb-3">
        <template x-for="(r, ri) in epRanges()" :key="ri">
            <button type="button" @click="epChunk = ri; $refs.epGrid.scrollTop = 0"
                    class="shrink-0 rounded-full px-3.5 py-1.5 text-[13px] font-bold transition"
                    :class="ri === epChunk ? 'nx-gradient text-white' : 'bg-white/10 text-cream/70 hover:bg-white/20'"
                    x-text="'ตอน ' + (episodes[r.from]?.n ?? r.from + 1) + '–' + (episodes[r.to]?.n ?? r.to + 1)"></button>
        </template>
    </div>

    <div x-ref="epGrid" class="nx-ep-grid {{ $wide ? 'nx-ep-grid--wide' : '' }} min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 pb-8">
        <template x-for="i in epVisible()" :key="i">
            {{-- One self-contained card: cover + its own footer label, so tiles never blur into each
                 other even when several episodes share the same (fallback) cover image. --}}
            <button type="button" @click="go(i)"
                    class="group flex flex-col overflow-hidden rounded-xl border border-white/12 bg-[#181022] shadow-md shadow-black/50 transition hover:-translate-y-0.5 hover:border-brand/60 hover:shadow-lg"
                    :class="i === index ? '!border-brand ring-1 ring-brand' : ''">
                <div class="relative w-full overflow-hidden" style="aspect-ratio:{{ $ratio }}">
                    <div class="absolute inset-0" style="background:linear-gradient(160deg,#241a33,#130f1c)"></div>
                    <img :src="episodes[i].thumb" x-show="episodes[i].thumb" loading="lazy" referrerpolicy="no-referrer"
                         class="absolute inset-0 h-full w-full object-cover transition duration-300 group-hover:scale-105"
                         onerror="this.style.display='none'" alt="">
                    <div class="absolute inset-x-0 bottom-0 h-1/3 bg-gradient-to-t from-black/80 to-transparent"></div>
                    <span x-show="i === index" x-cloak
                          class="nx-gradient absolute left-1.5 top-1.5 rounded px-1.5 py-0.5 text-[10px] font-bold">● กำลังดู</span>
                </div>
                <div class="flex items-center justify-center border-t border-white/10 bg-black/35 py-2 leading-none">
                    <span class="text-[13px] font-bold text-cream/90" x-text="'ตอน ' + episodes[i].n"></span>
                </div>
            </button>
        </template>
    </div>
</div>

@include('partials.episode-grid-css')

<script>
/**
 * State half of the shared episode picker — spread into a player's Alpine object:
 *     ...nxEpPicker(),
 *
 * Deliberately METHODS, not getters: `{...obj}` resolves a getter once and copies the VALUE, so a
 * getter would silently freeze at whatever it returned when the component was built.
 */
window.nxEpPicker = window.nxEpPicker || function (size = 50) {
    return {
        epMenu: false,
        epChunk: 0,
        epChunkSize: size,

        /** [{from,to}] absolute-index ranges, or [] when the whole title fits in one chunk. */
        epRanges() {
            const total = this.episodes.length;
            if (total <= this.epChunkSize) return [];
            const out = [];
            for (let s = 0; s < total; s += this.epChunkSize) {
                out.push({ from: s, to: Math.min(s + this.epChunkSize, total) - 1 });
            }
            return out;
        },

        /** Absolute indices to render right now — x-for iterates these, so `i` stays the real
         *  episode index and go(i) / the "กำลังดู" highlight need no offset arithmetic. */
        epVisible() {
            const r = this.epRanges()[this.epChunk];
            const from = r ? r.from : 0;
            const to = r ? r.to : this.episodes.length - 1;
            const out = [];
            for (let i = from; i <= to; i++) out.push(i);
            return out;
        },

        /** Open on the chunk holding the episode being watched — otherwise episode 300 of 500 opens
         *  the picker on "ตอน 1–50" and the viewer has to hunt for where they already are. */
        openEpMenu() {
            const n = this.epRanges().length;
            this.epChunk = n ? Math.min(n - 1, Math.floor(this.index / this.epChunkSize)) : 0;
            this.epMenu = true;
        },
    };
};
</script>
