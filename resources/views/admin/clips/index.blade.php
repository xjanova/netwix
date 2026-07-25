@extends('layouts.admin')
@section('page-title', 'ตัดคลิป → เฟซบุ๊ก')
@section('page-subtitle', 'ทำโพสต์เฟซบุ๊กจากหนังในเว็บ — คลิปสั้น (แนวตั้ง/จตุรัส/แนวนอน), รูปปกของเรื่อง หรือภาพนิ่งช็อตเดียวจากในหนัง')

@section('content')
<div class="nx-card p-5" x-data="clipCutter()" x-init="init()">

    <div class="mb-5 grid grid-cols-2 gap-3 sm:max-w-md">
        <div class="rounded-xl bg-white/[0.03] p-4">
            <div class="text-[12px] text-cream/50">คลิปพร้อมโพสต์</div>
            <div class="text-2xl font-bold text-[#4ade80]">{{ number_format($ready) }}</div>
        </div>
        <div class="rounded-xl bg-white/[0.03] p-4">
            <div class="text-[12px] text-cream/50">โพสต์ไปแล้ว</div>
            <div class="text-2xl font-bold">{{ number_format($posted) }}</div>
        </div>
    </div>

    {{-- ── What to make ────────────────────────────────────────────── --}}
    <div class="mb-4">
        <label class="mb-1.5 block text-[12px] text-cream/50">โพสต์เป็นอะไร</label>
        <div class="grid gap-2 sm:grid-cols-3">
            @foreach ([
                'clip' => ['🎬', 'คลิปวีดีโอ', 'ตัดฉากในหนังเป็นวิดีโอสั้น'],
                'poster' => ['🖼️', 'รูปปกหนัง', 'ใช้ภาพปกของเรื่องนั้น ไม่ต้องตัดวิดีโอ'],
                'frame' => ['📸', 'ภาพนิ่งจากในหนัง', 'จับภาพช็อตเดียวจากในตอน'],
            ] as $val => [$icon, $lbl, $hint])
                <label class="cursor-pointer rounded-lg border border-white/10 bg-white/5 p-3 has-[:checked]:border-brand/50 has-[:checked]:bg-brand/10">
                    <input type="radio" name="media_mode" value="{{ $val }}" x-model="media" class="sr-only">
                    <div class="text-sm">{{ $icon }} {{ $lbl }}</div>
                    <div class="mt-0.5 text-[11px] leading-relaxed text-cream/40">{{ $hint }}</div>
                </label>
            @endforeach
        </div>
    </div>

    {{-- ── Create form ─────────────────────────────────────────────── --}}
    <div class="grid gap-4 lg:grid-cols-2">
        <div class="space-y-3 text-sm">
            {{-- Title search --}}
            <div class="relative">
                <label class="mb-1 block text-[12px] text-cream/50">เรื่อง</label>
                <input x-model="titleQ" @input.debounce.300ms="searchTitle()" @focus="searchTitle()"
                       placeholder="พิมพ์ชื่อเรื่องเพื่อค้นหา…"
                       class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-cream placeholder:text-cream/30">
                <div x-show="titleResults.length && !contentId" x-cloak
                     class="absolute z-20 mt-1 w-full overflow-hidden rounded-lg border border-white/10 bg-[#141019] shadow-xl">
                    <template x-for="r in titleResults" :key="r.id">
                        <button type="button" @click="pickTitle(r)"
                                class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-white/5">
                            <span class="truncate" x-text="r.title"></span>
                            <span class="shrink-0 text-[11px] text-cream/40" x-text="r.episodes + ' ตอน'"></span>
                        </button>
                    </template>
                </div>
                <div x-show="contentId" x-cloak class="mt-2 flex items-center gap-2 text-[13px]">
                    <span class="rounded-full bg-brand/20 px-3 py-1 text-brand-2" x-text="'เลือก: ' + contentLabel"></span>
                    <button type="button" @click="clearTitle()" class="text-cream/40 hover:text-cream">✕ ล้าง</button>
                </div>
            </div>

            {{-- Episode (cover art doesn't come from an episode at all) --}}
            <div x-show="episodes.length > 1 && media !== 'poster'" x-cloak>
                <label class="mb-1 block text-[12px] text-cream/50">ตอน</label>
                <select x-model="episodeId" class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-cream">
                    <template x-for="e in episodes" :key="e.id">
                        <option :value="e.id" x-text="'ตอน ' + e.number + (e.minutes ? ' · ' + e.minutes + ' นาที' : '')"></option>
                    </template>
                </select>
            </div>
        </div>

        <div class="space-y-3 text-sm">
            <div class="grid grid-cols-2 gap-3">
                <div x-show="media === 'clip'" x-cloak>
                    <label class="mb-1 block text-[12px] text-cream/50">ความยาว (วินาที)</label>
                    <input type="number" x-model.number="duration" min="5" max="180"
                           class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-cream">
                </div>
                <div x-show="media !== 'poster'" x-cloak>
                    <label class="mb-1 block text-[12px] text-cream/50">สัดส่วน</label>
                    <select x-model="aspect" class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-cream">
                        <option value="9:16">9:16 แนวตั้ง (Reels/TikTok)</option>
                        <option value="1:1">1:1 จตุรัส (ฟีด)</option>
                        <option value="16:9">16:9 แนวนอน</option>
                    </select>
                </div>
                <div x-show="media !== 'poster'" x-cloak>
                    <label class="mb-1 block text-[12px] text-cream/50" x-text="media === 'frame' ? 'จำนวนภาพ' : 'จำนวนคลิป'">จำนวนคลิป</label>
                    <select x-model.number="count" :disabled="startSec !== ''"
                            class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-cream disabled:opacity-40">
                        <template x-for="n in 5" :key="n"><option :value="n" x-text="n"></option></template>
                    </select>
                </div>
                <div x-show="media !== 'poster'" x-cloak>
                    <label class="mb-1 block text-[12px] text-cream/50"
                           x-text="media === 'frame' ? 'จับที่วินาที (ว่าง=อัตโนมัติ)' : 'เริ่มที่ วิ. (ว่าง=อัตโนมัติ)'">เริ่มที่ วิ.</label>
                    <input type="number" x-model="startSec" min="0" placeholder="อัตโนมัติ"
                           class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-cream placeholder:text-cream/30">
                </div>
            </div>
            <p x-show="media !== 'poster'" x-cloak class="text-[11px] leading-relaxed text-cream/40">
                เว้นช่องวินาทีไว้ = ระบบเลือกช่วงกลางเรื่องอัตโนมัติ (เลี่ยงอินโทร/เครดิต) และกระจายตามจำนวนที่สั่ง ·
                ใส่ตัวเลข = เอาจุดเดียวตรงวินาทีนั้น
            </p>
            <p x-show="media === 'poster'" x-cloak class="text-[11px] leading-relaxed text-cream/40">
                ใช้ภาพปกของเรื่องที่เลือก โพสต์เป็นรูปลงฟีด (ไม่ครอป ไม่ต้องตัดวิดีโอ) — ได้ 1 โพสต์ต่อเรื่อง
            </p>
            <button type="button" @click="create()" :disabled="!contentId || busy"
                    class="nx-gradient w-full rounded-lg px-4 py-2.5 text-sm font-semibold disabled:opacity-40"
                    style="box-shadow:0 8px 22px rgba(176,38,255,0.32)">
                <span x-show="!busy" x-text="createLabel()">✂️ สร้างคลิป</span>
                <span x-show="busy" x-cloak>⏳ กำลังส่งเข้าคิว…</span>
            </button>
        </div>
    </div>

    {{-- ── Live agents ────────────────────────────────────────────── --}}
    <div x-show="agents.length" x-cloak class="mt-5">
        <div class="mb-2 flex items-center gap-2 text-[13px] text-cream/70">
            <span class="inline-block h-2 w-2 animate-ping rounded-full bg-brand-2"></span>
            <span x-text="'กำลังตัด ' + agents.length + ' คลิปพร้อมกัน'"></span>
            <span x-show="batchTotal" class="text-cream/40" x-text="'· ' + batchDone + '/' + batchTotal"></span>
        </div>
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
            <template x-for="(a,i) in agents" :key="i">
                <div class="flex items-center gap-2 rounded-lg border border-brand/20 bg-brand/5 px-3 py-2 text-[12px]">
                    <span class="inline-block h-3 w-3 animate-spin rounded-full border-2 border-brand-2 border-t-transparent"></span>
                    <span class="truncate text-cream/80" x-text="a.label"></span>
                    <span class="ml-auto shrink-0 text-cream/40" x-text="'✓' + a.done"></span>
                </div>
            </template>
        </div>
    </div>

    {{-- ── Clip gallery ───────────────────────────────────────────── --}}
    <div class="mt-6">
        <div class="mb-3 text-sm font-semibold text-cream/80">โพสต์ล่าสุด</div>
        <div x-show="!clips.length" x-cloak class="rounded-xl border border-dashed border-white/10 py-10 text-center text-[13px] text-cream/40">
            ยังไม่มีโพสต์ — เลือกว่าจะทำคลิป/รูปปก/ภาพนิ่ง แล้วเลือกเรื่องด้านบน
        </div>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <template x-for="c in clips" :key="c.id">
                <div class="overflow-hidden rounded-xl border border-white/10 bg-white/[0.02]">
                    {{-- preview --}}
                    <div class="relative aspect-video bg-black/40">
                        <template x-if="c.status === 'ready' && c.file_url && c.media_type === 'clip'">
                            <video :src="c.file_url" :poster="c.poster_url" controls preload="none"
                                   class="h-full w-full object-contain"></video>
                        </template>
                        <template x-if="c.status === 'ready' && c.file_url && c.media_type !== 'clip'">
                            <a :href="c.file_url" target="_blank" rel="noopener" class="block h-full w-full">
                                <img :src="c.file_url" :alt="c.title" loading="lazy" class="h-full w-full object-contain">
                            </a>
                        </template>
                        <template x-if="c.status !== 'ready'">
                            <div class="flex h-full items-center justify-center text-center text-[12px]">
                                <span x-show="c.status === 'pending' || c.status === 'processing'" class="text-cream/60">
                                    <span class="mr-1 inline-block h-3 w-3 animate-spin rounded-full border-2 border-brand-2 border-t-transparent align-middle"></span>
                                    <span x-text="c.status === 'processing' ? 'กำลังตัด…' : 'รอคิว…'"></span>
                                </span>
                                <span x-show="c.status === 'failed'" class="text-[#ff6b81]" x-text="'✗ ' + reasonText(c.error)"></span>
                            </div>
                        </template>
                        <span class="absolute left-2 top-2 rounded bg-black/60 px-1.5 py-0.5 text-[10px] text-cream/70" x-text="mediaBadge(c)"></span>
                    </div>
                    {{-- meta --}}
                    <div class="space-y-2 p-3">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="truncate text-[13px] font-medium text-cream" x-text="c.title"></div>
                                <div class="text-[11px] text-cream/40" x-text="metaText(c)"></div>
                            </div>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px]"
                                  :class="{'bg-[#4ade80]/15 text-[#4ade80]': c.status==='ready', 'bg-white/10 text-cream/50': c.status==='pending'||c.status==='processing', 'bg-[#ff6b81]/15 text-[#ff6b81]': c.status==='failed', 'bg-brand/15 text-brand-2': c.posted_at}"
                                  x-text="c.posted_at ? 'โพสต์แล้ว' : statusText(c.status)"></span>
                        </div>

                        <template x-if="c.status === 'ready'">
                            <div class="space-y-2">
                                <textarea rows="2" placeholder="แคปชัน (Phase 2 จะให้ AI ช่วยเขียน)…"
                                          class="w-full rounded-lg border border-white/10 bg-white/5 px-2.5 py-1.5 text-[12px] text-cream placeholder:text-cream/25"
                                          x-model="c.caption"></textarea>
                                <div class="flex flex-wrap items-center gap-2 text-[11px]">
                                    <button @click="writeCaption(c)" :disabled="c._cap"
                                            class="rounded-md bg-brand/20 px-2.5 py-1 text-brand-2 hover:bg-brand/30 disabled:opacity-40">
                                        <span x-show="!c._cap">✨ AI เขียนให้</span><span x-show="c._cap" x-cloak>⏳…</span>
                                    </button>
                                    <button @click="saveCaption(c)" class="rounded-md bg-white/10 px-2.5 py-1 hover:bg-white/15">บันทึก</button>
                                    {{-- Publish / re-publish. Hidden once the mp4 is purged (15 วัน) — there'd be
                                         nothing for Facebook to fetch; the hint below says so instead. --}}
                                    <template x-if="c.file_url">
                                        <button @click="repost(c)" :disabled="c._posting"
                                                class="rounded-md bg-[#4ade80]/15 px-2.5 py-1 text-[#4ade80] hover:bg-[#4ade80]/25 disabled:opacity-40">
                                            <span x-show="!c._posting" x-text="c.posted_at ? '↗ รีโพส' : '↗ โพสต์'"></span>
                                            <span x-show="c._posting" x-cloak>⏳ กำลังโพสต์…</span>
                                        </button>
                                    </template>
                                    <template x-if="c.file_url">
                                        <a :href="c.file_url" download class="rounded-md bg-white/10 px-2.5 py-1 hover:bg-white/15">⬇ โหลด</a>
                                    </template>
                                    <button @click="retry(c)" class="rounded-md bg-white/10 px-2.5 py-1 hover:bg-white/15"
                                            x-text="c.media_type === 'clip' ? '↻ ตัดใหม่' : '↻ สร้างใหม่'">↻ ตัดใหม่</button>
                                    <button @click="del(c)" class="ml-auto text-[#ff6b81]/70 hover:text-[#ff6b81]">ลบ</button>
                                </div>
                                <div x-show="c.purged && !c.file_url" x-cloak class="text-[11px] text-cream/40"
                                     x-text="'ไฟล์ถูกลบแล้ว (เก็บ 15 วัน) — กด “' + (c.media_type === 'clip' ? '↻ ตัดใหม่' : '↻ สร้างใหม่') + '” ถ้าจะโพสต์อีก'"></div>
                                <div x-show="c.repost_count" x-cloak class="text-[11px] text-cream/40"
                                     x-text="'รีโพสไปแล้ว ' + c.repost_count + ' ครั้ง'"></div>
                                <div x-show="c.post_error" x-cloak class="text-[11px]"
                                     :class="c.post_partial ? 'text-[#fbbf24]' : 'text-[#ff6b81]'"
                                     x-text="(c.post_partial ? '⚠ โพสต์ขึ้นบางช่องทาง: ' : '✗ โพสต์ไม่สำเร็จ: ') + postErrText(c.post_error)"></div>
                            </div>
                        </template>
                        <template x-if="c.status === 'failed'">
                            <div class="flex items-center gap-2 text-[11px]">
                                <button @click="retry(c)" class="rounded-md bg-white/10 px-2.5 py-1 hover:bg-white/15">↻ ลองใหม่</button>
                                <button @click="del(c)" class="ml-auto text-[#ff6b81]/70 hover:text-[#ff6b81]">ลบ</button>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>

<script>
function clipCutter() {
    const csrf = '{{ csrf_token() }}';
    const sleep = (ms) => new Promise(r => setTimeout(r, ms));
    return {
        titleQ: '', titleResults: [], contentId: null, contentLabel: '',
        episodes: [], episodeId: '',
        media: 'clip', duration: 45, aspect: '9:16', count: 3, startSec: '',
        busy: false, clips: [],
        agents: [], batch: null, batchTotal: 0, batchDone: 0, watching: false,

        async req(url, opts) { const r = await fetch(url, opts); return r.json(); },
        post(url, body) {
            return this.req(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(body || {}) });
        },
        send(url, method, body) {
            return this.req(url, { method, headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(body || {}) });
        },

        fmt(s) { s = +s || 0; const m = Math.floor(s / 60); return m + ':' + String(s % 60).padStart(2, '0'); },
        statusText(s) { return { pending: 'รอคิว', processing: 'กำลังตัด', ready: 'พร้อม', failed: 'ล้มเหลว' }[s] || s; },
        reasonText(r) {
            return { no_source: 'ไม่พบแหล่งวิดีโอ', download_failed: 'ดาวน์โหลดไม่ได้', too_large: 'ไฟล์ใหญ่เกิน',
                     ffmpeg_failed: 'ตัด/แปลงไม่ได้', no_poster: 'เรื่องนี้ไม่มีรูปปก', bad_image: 'รูปปกเสีย/อ่านไม่ได้',
                     error: 'ผิดพลาด' }[r] || (r || 'ผิดพลาด');
        },
        // What each card IS. Anything unrecognised falls back to the original clip wording — the
        // safe default for a row that predates media_type.
        noun(c) { return { poster: 'โพสต์รูปปก', frame: 'ภาพนิ่ง' }[c.media_type] || 'คลิป'; },
        createLabel() { return { poster: '🖼️ สร้างโพสต์รูปปก', frame: '📸 จับภาพนิ่ง' }[this.media] || '✂️ สร้างคลิป'; },
        mediaBadge(c) {
            if (c.media_type === 'poster') return 'รูปปก';
            if (c.media_type === 'frame') return 'ภาพนิ่ง · ' + c.aspect;
            return c.aspect;
        },
        metaText(c) {
            const ep = c.episode ? 'ตอน ' + c.episode + ' · ' : '';
            if (c.media_type === 'poster') return 'ภาพปกของเรื่อง';
            // start=0 on a still means the position was resolved at cut time (ท้ายตอน/สุ่ม) —
            // don't claim "ที่ 0:00", which would be flatly wrong.
            if (c.media_type === 'frame') return ep + (+c.start ? 'ภาพนิ่งที่ ' + this.fmt(c.start) : 'ภาพนิ่งจากในตอน');
            return ep + 'เริ่ม ' + this.fmt(c.start) + ' · ยาว ' + c.duration + ' วิ';
        },
        postErrText(r) {
            if (!r) return 'ผิดพลาด';
            const exact = { fb_not_connected: 'เพจหลุดการเชื่อมต่อ', clip_not_ready: 'คลิปไม่พร้อม',
                            no_file_url: 'ไม่พบไฟล์คลิป', no_local_file: 'ไม่พบไฟล์คลิปบนเซิร์ฟเวอร์',
                            post_failed: 'เพจปฏิเสธ' }[r];
            if (exact) return exact;
            // Campaign failures arrive as raw Graph text ("facebook post failed: reels:HTTP 422",
            // "cut_failed:download_failed") — translate the ones we actually see.
            // The fallback note must be tested FIRST: it rides along with the reels error it
            // recovered from, so a looser reels test would report a failure we already fixed.
            if (/reels_failed_posted_to_feed/.test(r)) return 'Reels ไม่รับคลิปนี้ — โพสต์ลงฟีดแทนให้แล้ว';
            if (/feed_fallback:/.test(r)) return 'Reels ไม่ผ่าน และลงฟีดสำรองก็ไม่ผ่าน: ' + r.replace(/.*feed_fallback:/, '');
            if (/reels:HTTP 422|FileUrlProcessingError|robots/i.test(r)) return 'อัปโหลด Reels ไม่ผ่าน (แก้แล้ว — กดโพสต์ใหม่ได้)';
            if (/cut_failed/i.test(r)) return 'ตัดคลิปไม่สำเร็จ — กด “↻ ตัดใหม่”';
            if (/reels:/i.test(r)) return 'Reels ไม่ผ่าน: ' + r.replace(/.*reels:/i, '');
            if (/feed:/i.test(r)) return 'ฟีดไม่ผ่าน: ' + r.replace(/.*feed:/i, '');
            return r;
        },

        async searchTitle() {
            const q = this.titleQ.trim();
            this.contentId = null; this.episodes = []; this.episodeId = '';
            if (!q) { this.titleResults = []; return; }
            try { const res = await this.req('{{ route('admin.clips.search') }}?q=' + encodeURIComponent(q), {}); this.titleResults = res.items || []; }
            catch (e) { this.titleResults = []; }
        },
        async pickTitle(r) {
            this.contentId = r.id; this.contentLabel = r.title + ' (' + r.episodes + ' ตอน)'; this.titleResults = [];
            try {
                const res = await this.req('{{ url('admin/clips/content') }}/' + r.id + '/episodes', {});
                this.episodes = res.items || [];
                this.episodeId = this.episodes.length ? this.episodes[0].id : '';
            } catch (e) { this.episodes = []; }
        },
        clearTitle() { this.contentId = null; this.contentLabel = ''; this.titleQ = ''; this.episodes = []; this.episodeId = ''; },

        async create() {
            if (!this.contentId || this.busy) return;
            this.busy = true;
            const body = {
                content_id: this.contentId,
                episode_id: this.episodeId || null,
                media_type: this.media,
                // A blank/NaN box would 422 and leave the button doing nothing — and the field is
                // hidden for photos anyway, where the value is ignored server-side.
                duration: +this.duration || 45, aspect: this.aspect,
                count: this.startSec !== '' ? 1 : this.count,
            };
            if (this.startSec !== '' && this.media !== 'poster') body.start = +this.startSec;
            let res;
            try { res = await this.post('{{ route('admin.clips.store') }}', body); }
            catch (e) { this.busy = false; return; }
            this.busy = false;
            this.batch = res.batch; this.batchTotal = res.count || 0; this.batchDone = 0;
            await this.refresh();
            if (!this.watching) this.watch();
        },

        async refresh() {
            try {
                const res = await this.req('{{ route('admin.clips.list') }}', {});
                // Carry local-only state across the poll: the caption being typed right now (a poll
                // must never clobber an in-progress edit with the stale server value), and the
                // in-flight flags — a rebuilt row would otherwise re-enable a busy button.
                const local = {};
                this.clips.forEach(c => { local[c.id] = { caption: c.caption, _cap: c._cap, _posting: c._posting }; });
                this.clips = (res.clips || []).map(c => ({
                    ...c,
                    caption: local[c.id]?.caption ?? c.caption,
                    _cap: local[c.id]?._cap ?? false,
                    _posting: local[c.id]?._posting ?? false,
                }));
            } catch (e) {}
        },

        // Poll while any clip is still cooking (or a batch is live), then stop to save cycles.
        async watch() {
            this.watching = true;
            while (true) {
                await sleep(2500);
                await this.refresh();
                if (this.batch) {
                    try {
                        const p = await this.req('{{ route('admin.clips.progress') }}?batch=' + this.batch, {});
                        this.agents = p.agents || [];
                        this.batchDone = p.processed || 0; this.batchTotal = p.total || this.batchTotal;
                        if (p.done) this.batch = null;
                    } catch (e) {}
                } else {
                    this.agents = [];
                }
                const busyClip = this.clips.some(c => c.status === 'pending' || c.status === 'processing');
                if (!this.batch && !busyClip) break;
            }
            this.watching = false; this.agents = [];
        },

        async saveCaption(c) {
            try { await this.send('{{ url('admin/clips') }}/' + c.id, 'PUT', { caption: c.caption || '' }); } catch (e) {}
        },
        // Flags are re-found by id, never held on `c`: a poll landing mid-request rebuilds the row
        // objects, and clearing the flag on the detached one would leave the button spinning forever.
        async writeCaption(c) {
            this.flag(c.id, '_cap', true);
            try {
                const res = await this.post('{{ url('admin/clips') }}/' + c.id + '/caption', {});
                if (res.caption) this.flag(c.id, 'caption', res.caption);
            }
            catch (e) {} finally { this.flag(c.id, '_cap', false); }
        },
        async retry(c) {
            try { const res = await this.post('{{ url('admin/clips') }}/' + c.id + '/retry', {}); this.batch = res.batch; }
            catch (e) { return; }
            c.status = 'pending';
            await this.refresh();
            if (!this.watching) this.watch();
        },
        // Publish / re-publish to the page. Posting is public and can't be undone from here, so it
        // always asks first — and says out loud when it's the second time.
        async repost(c) {
            if (c._posting) return;
            const noun = this.noun(c);
            let msg = c.posted_at
                ? noun + 'นี้โพสต์ไปแล้วเมื่อ ' + c.posted_at + '\n\nโพสต์ซ้ำขึ้นเพจอีกครั้ง?'
                : 'โพสต์' + noun + 'นี้ขึ้นเพจ Facebook?';
            if (!(c.caption || '').trim()) msg += '\n\n⚠ ยังไม่มีแคปชัน — โพสต์จะไม่มีข้อความ';
            if (!confirm(msg)) return;

            const before = c.posted_at;
            this.flag(c.id, '_posting', true);
            let res;
            try { res = await this.post('{{ url('admin/clips') }}/' + c.id + '/repost', { known_posted_at: before || null }); }
            catch (e) { this.flag(c.id, '_posting', false); alert('สั่งโพสต์ไม่สำเร็จ ลองใหม่อีกครั้ง'); return; }
            if (!res || res.error) {
                this.flag(c.id, '_posting', false);
                alert(res?.error || 'สั่งโพสต์ไม่สำเร็จ');
                if (res?.stale) await this.refresh();   // re-label the button to the truth it just told us
                return;
            }
            await this.awaitPost(c.id, before);
            this.flag(c.id, '_posting', false);
        },
        flag(id, k, v) { const c = this.clips.find(x => x.id === id); if (c) c[k] = v; },
        /**
         * A repost leaves status at "ready", so the batch watcher can't see it — the only tells are
         * posted_at moving or an error landing. The wait covers the worst realistic case: the
         * clips-post worker runs on a one-minute tick, and a Reel is a 3-phase upload. Giving up
         * only stops watching — the job itself runs on, and the next refresh shows the result.
         */
        async awaitPost(id, before) {
            for (let i = 0; i < 80; i++) {          // ~4 นาที
                await sleep(3000);
                await this.refresh();
                const c = this.clips.find(x => x.id === id);
                if (!c) return;
                // posted_at first: a partial success (Reel ok, feed rejected) sets BOTH, and it did
                // go live — the error line on the card says the rest without crying failure.
                if (c.posted_at !== before) return;
                if (c.post_error) { alert('โพสต์ไม่สำเร็จ: ' + this.postErrText(c.post_error)); return; }
            }
        },
        async del(c) {
            if (!confirm('ลบคลิปนี้?')) return;
            try { await this.send('{{ url('admin/clips') }}/' + c.id, 'DELETE', {}); this.clips = this.clips.filter(x => x.id !== c.id); } catch (e) {}
        },

        async init() {
            await this.refresh();
            if (this.clips.some(c => c.status === 'pending' || c.status === 'processing')) this.watch();
        },
    };
}
</script>
@endsection
