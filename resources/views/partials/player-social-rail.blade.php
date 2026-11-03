{{--
    Like · comment · share buttons for BOTH web players. Bare buttons, no wrapper: the caller places
    them (watch: a column at the right edge; vertical-player: above the ↑/↓ episode arrows).

    Expects the surrounding Alpine component to spread ...nxSocial(cfg) — see partials/player-social-drawer.
--}}
<button type="button" @click.stop="soToggleLike()" :aria-pressed="soLiked ? 'true' : 'false'" aria-label="ถูกใจ"
        class="group flex flex-col items-center gap-1">
    <span class="flex h-11 w-11 items-center justify-center rounded-full bg-black/45 ring-1 ring-white/10 backdrop-blur transition group-hover:bg-black/65"
          :class="soLiked ? 'text-[#ff2d55] scale-110' : 'text-white'">
        <svg x-show="soLiked" x-cloak class="h-6 w-6" viewBox="0 0 24 24" fill="currentColor"><path d="M12 21s-7.5-4.6-9.6-9.2C.9 8.5 2.9 4.5 6.7 4.5c2.1 0 3.6 1.1 4.4 2.4h1.8c.8-1.3 2.3-2.4 4.4-2.4 3.8 0 5.8 4 4.3 7.3C19.5 16.4 12 21 12 21z"/></svg>
        <svg x-show="!soLiked" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M12 20s-7-4.3-8.8-8.6C1.9 8.4 3.7 5 7 5c2 0 3.4 1.1 4.1 2.3h1.8C13.6 6.1 15 5 17 5c3.3 0 5.1 3.4 3.8 6.4C19 15.7 12 20 12 20z"/></svg>
    </span>
    <span class="text-[11px] font-semibold text-white drop-shadow" x-text="soFmt(soLikes) || 'ถูกใจ'">ถูกใจ</span>
</button>

<button type="button" @click.stop="soOpenComments()" aria-label="ความคิดเห็น" class="group flex flex-col items-center gap-1">
    <span class="flex h-11 w-11 items-center justify-center rounded-full bg-black/45 text-white ring-1 ring-white/10 backdrop-blur transition group-hover:bg-black/65">
        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 9 9 0 0 1-3.9-.9L3 20l1.1-4.1A8 8 0 0 1 3 11.5 8.5 8.5 0 0 1 12 3a8.5 8.5 0 0 1 9 8.5z"/></svg>
    </span>
    <span class="text-[11px] font-semibold text-white drop-shadow" x-text="soFmt(soCount) || 'เม้นต์'">เม้นต์</span>
</button>

<button type="button" @click.stop="soShareTitle()" aria-label="แชร์" class="group flex flex-col items-center gap-1">
    <span class="flex h-11 w-11 items-center justify-center rounded-full bg-black/45 text-white ring-1 ring-white/10 backdrop-blur transition group-hover:bg-black/65">
        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 5l7 7-7 7"/><path d="M21 12H10a7 7 0 0 0-7 7"/></svg>
    </span>
    <span class="text-[11px] font-semibold text-white drop-shadow">แชร์</span>
</button>
