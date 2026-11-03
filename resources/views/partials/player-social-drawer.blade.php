{{--
    Comments drawer + share sheet for BOTH web players, and the nxSocial() state they run on.
    Include it once at the player root (not inside the 9:16 box — that one clips), and spread the
    state into the player's Alpine object:  ...nxSocial(cfg.social)   (seed: App\Support\PlayerSocial).

    The drawer is the only comment composer on a player page, so the page carries exactly one
    Turnstile widget and getResponse() needs no widget id. It re-reads the app's comment endpoint on
    open, so comments posted from the mobile app appear here without a reload (same table).

    z-[75]: above the guest sign-up pill (fixed, z-70) so it never covers the composer.

    Wheel / touch events stop here: the vertical player swipes episodes on its root, and scrolling the
    comment list must not change the episode.
--}}
<div x-show="soOpen" x-cloak x-transition.opacity
     @click.self="soOpen = false" @wheel.stop @touchstart.stop @touchend.stop @mousemove.stop
     @keydown.escape.window="soOpen = false"
     class="absolute inset-0 z-[75] flex items-end justify-center bg-black/55 sm:items-stretch sm:justify-end">
    <div class="flex h-[72%] w-full select-text flex-col rounded-t-2xl bg-[#140d20] ring-1 ring-white/10 sm:h-full sm:w-[380px] sm:rounded-none"
         role="dialog" aria-label="ความคิดเห็น">
        <div class="flex shrink-0 items-center justify-between border-b border-white/10 px-4 py-3">
            <div class="text-base font-bold">ความคิดเห็น <span class="text-cream/45">(<span x-text="soCount"></span>)</span></div>
            <button type="button" @click="soOpen = false" aria-label="ปิด"
                    class="flex h-9 w-9 items-center justify-center rounded-full bg-white/10 hover:bg-white/20">✕</button>
        </div>

        <div class="flex-1 overflow-y-auto overscroll-contain px-4 py-3">
            <div class="flex flex-col gap-3.5">
                <template x-for="(c, i) in soList" :key="i">
                    <div class="flex gap-3">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[12px] font-bold text-black/60"
                              :style="'background:' + (c.color || '#8b2ff0')" x-text="c.initial || (c.author || '?').charAt(0)"></span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 text-[13px]"><span class="font-semibold" x-text="c.author"></span><span class="text-cream/40" x-text="c.ago"></span></div>
                            <p class="mt-0.5 whitespace-pre-line break-words text-sm text-cream/80" x-text="c.text"></p>
                        </div>
                    </div>
                </template>
                <p x-show="soList.length === 0" class="py-10 text-center text-sm text-cream/40">ยังไม่มีความคิดเห็น — เป็นคนแรกเลย!</p>
            </div>
        </div>

        <div class="shrink-0 border-t border-white/10 px-4 pb-4 pt-3">
            @auth
                <form @submit.prevent="soPost()">
                    <div class="flex items-end gap-2">
                        <textarea x-model="soBody" maxlength="500" rows="1" placeholder="ร่วมพูดคุยเกี่ยวกับเรื่องนี้…"
                                  @keydown.enter="if (!$event.shiftKey && !$event.isComposing) { $event.preventDefault(); soPost(); }"
                                  class="max-h-28 min-h-[42px] flex-1 resize-none rounded-xl border border-white/10 bg-white/5 px-3.5 py-2.5 text-sm outline-none focus:border-brand"></textarea>
                        <button type="submit" :disabled="soBusy || !soBody.trim()" class="btn-brand h-[42px] px-5 text-sm disabled:opacity-40">
                            <span x-show="!soBusy">ส่ง</span><span x-show="soBusy" x-cloak>…</span>
                        </button>
                    </div>
                    <p x-show="soErr" x-cloak x-text="soErr" role="alert" class="mt-2 text-[12px] text-[#ff6b85]"></p>
                    @include('partials.turnstile')
                </form>
            @else
                <a :href="soLoginUrl" class="flex items-center justify-center gap-2 rounded-xl border border-white/10 bg-white/5 px-3.5 py-3 text-sm text-cream/75 transition hover:border-brand hover:text-cream">
                    เข้าสู่ระบบเพื่อร่วมแสดงความคิดเห็น
                </a>
            @endauth
        </div>
    </div>
</div>

{{-- share sheet — the fallback when the browser has no native share (most desktops) --}}
<div x-show="soShare" x-cloak x-transition.opacity
     @click.self="soShare = false" @wheel.stop @touchstart.stop @touchend.stop @mousemove.stop
     @keydown.escape.window="soShare = false"
     class="absolute inset-0 z-[75] flex items-end justify-center bg-black/55 sm:items-center">
    <div class="w-full max-w-sm rounded-t-2xl bg-[#140d20] p-5 ring-1 ring-white/10 sm:rounded-2xl" role="dialog" aria-label="แชร์">
        <div class="mb-4 flex items-center justify-between">
            <div class="text-base font-bold">แชร์เรื่องนี้</div>
            <button type="button" @click="soShare = false" aria-label="ปิด" class="flex h-9 w-9 items-center justify-center rounded-full bg-white/10 hover:bg-white/20">✕</button>
        </div>
        <div class="grid grid-cols-3 gap-3 text-center text-[12px]">
            <a :href="'https://social-plugins.line.me/lineit/share?url=' + encodeURIComponent(soShareUrl)" target="_blank" rel="noopener" class="flex flex-col items-center gap-1.5">
                <span class="flex h-12 w-12 items-center justify-center rounded-full text-white" style="background:#06C755">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="currentColor"><path d="M12 3C6.48 3 2 6.63 2 11.02c0 3.93 3.32 7.22 7.8 7.85.3.06.72.2.82.46.09.24.06.6.03.85l-.13.79c-.04.24-.19.94.83.51 1.02-.43 5.5-3.24 7.5-5.55C20.5 14.42 22 12.86 22 11.02 22 6.63 17.52 3 12 3z"/></svg>
                </span>LINE
            </a>
            <a :href="'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(soShareUrl)" target="_blank" rel="noopener" class="flex flex-col items-center gap-1.5">
                <span class="flex h-12 w-12 items-center justify-center rounded-full text-white" style="background:#1877F2">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="currentColor"><path d="M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.49-3.89 3.78-3.89 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.44 2.89h-2.34v6.99A10 10 0 0 0 22 12z"/></svg>
                </span>Facebook
            </a>
            <button type="button" @click="soCopy()" class="flex flex-col items-center gap-1.5">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-white/10 text-white">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.07 0l3-3a5 5 0 0 0-7.07-7.07l-1.5 1.5"/><path d="M14 11a5 5 0 0 0-7.07 0l-3 3a5 5 0 0 0 7.07 7.07l1.5-1.5"/></svg>
                </span><span x-text="soCopied ? 'คัดลอกแล้ว ✓' : 'คัดลอกลิงก์'"></span>
            </button>
        </div>
    </div>
</div>

<script>
/**
 * State half of the player like / comment / share rail — spread into a player's Alpine object:
 *     ...nxSocial(cfg.social),
 * Methods, not getters (a spread copies a getter's value once and freezes it — see nxEpPicker).
 */
window.nxSocial = window.nxSocial || function (s) {
    s = s || {};
    return {
        soLiked: !!s.liked, soLikes: s.likes || 0, soLikeBusy: false,
        soOpen: false, soList: s.comments || [], soCount: s.commentCount || 0,
        soBody: '', soBusy: false, soErr: '', soSeq: 0,
        soShare: false, soCopied: false,
        soLoginUrl: s.loginUrl || '/login', soShareUrl: s.shareUrl || location.href,

        soFmt(n) {
            n = Number(n) || 0;
            if (n <= 0) return '';
            if (n < 1000) return String(n);
            return (n / 1000).toFixed(n < 10000 ? 1 : 0).replace(/\.0$/, '') + 'K';
        },

        // Optimistic: flip at once, then take the server's answer (or roll back if it failed).
        async soToggleLike() {
            if (!s.member) { location.href = this.soLoginUrl; return; }
            if (this.soLikeBusy) return;
            this.soLikeBusy = true;
            const was = this.soLiked;
            this.soLiked = !was;
            this.soLikes = Math.max(0, this.soLikes + (was ? -1 : 1));
            try {
                const d = await nxPost(s.likeUrl);
                this.soLiked = !!d.liked;
                if (typeof d.count === 'number') this.soLikes = d.count;
            } catch (e) {
                this.soLiked = was;
                this.soLikes = Math.max(0, this.soLikes + (was ? 1 : -1));
            } finally { this.soLikeBusy = false; }
        },

        async soOpenComments() {
            this.soShare = false; this.soErr = ''; this.soOpen = true;
            // The Turnstile widget rendered while the drawer was display:none — nudge it now it shows.
            this.$nextTick(() => { try { window.turnstile && window.turnstile.reset(); } catch (e) {} });
            const seq = ++this.soSeq;
            try {
                const r = await fetch(s.listUrl, { headers: { Accept: 'application/json' } });
                const j = r.ok ? await r.json() : null;
                // A post that landed while this was in flight bumps soSeq — don't wipe it with an older list.
                if (seq !== this.soSeq || !j || !j.success || !j.data) return;
                this.soList = (j.data.items || []).map(c => ({ author: c.author, color: c.avatar_color, initial: c.initial, text: c.text, ago: c.ago }));
                if (typeof j.data.count === 'number') this.soCount = j.data.count;
            } catch (e) {}   // offline: keep the server-rendered seed
        },

        async soPost() {
            const body = this.soBody.trim();
            if (this.soBusy || !body) return;
            if (!s.member) { location.href = this.soLoginUrl; return; }
            this.soBusy = true; this.soErr = '';
            const ts = window.turnstile ? window.turnstile.getResponse() : '';
            try {
                const d = await nxPost(s.commentUrl, { body, 'cf-turnstile-response': ts });
                this.soSeq++;
                this.soList.unshift({ author: d.comment.author, color: d.comment.avatar_color, initial: d.comment.initial, text: d.comment.text, ago: d.comment.ago });
                this.soCount = d.count;
                this.soBody = '';
            } catch (e) {
                const code = Number((String(e && e.message).match(/\d{3}/) || [])[0]);
                this.soErr = code === 429 ? 'ส่งถี่เกินไป รอสักครู่แล้วลองใหม่'
                    : code === 422 ? 'ยืนยันว่าไม่ใช่บอทก่อน แล้วกดส่งอีกครั้ง'
                    : code === 419 ? 'หน้านี้เปิดไว้นานเกินไป รีเฟรชแล้วลองใหม่'
                    : code === 401 || code === 302 ? 'กรุณาเข้าสู่ระบบอีกครั้ง'
                    : 'ส่งไม่สำเร็จ ตรวจสอบอินเทอร์เน็ตแล้วลองใหม่';
            } finally {
                this.soBusy = false;
                try { window.turnstile && window.turnstile.reset(); } catch (e) {}
            }
        },

        // Native share sheet where the browser has one (phones); otherwise our LINE / Facebook / copy sheet.
        async soShareTitle() {
            if (navigator.share) {
                try {
                    await navigator.share({ title: s.title, text: 'ดู "' + s.title + '" ฟรีบน NetWix 🎬', url: this.soShareUrl });
                    return;
                } catch (e) {
                    if (e && e.name === 'AbortError') return;   // the viewer closed the sheet
                }
            }
            this.soOpen = false;
            this.soShare = true;
        },

        soCopy() {
            const done = () => { this.soCopied = true; setTimeout(() => (this.soCopied = false), 1500); };
            if (navigator.clipboard) navigator.clipboard.writeText(this.soShareUrl).then(done).catch(() => {});
        },
    };
};
</script>
