@extends('layouts.admin')
@section('page-title', 'สร้างปกตอน')
@section('page-subtitle', 'สร้างภาพปกของแต่ละตอนด้วย ffmpeg (WebP) — เลือกทีละเรื่อง (แนะนำ) ทั้งหมด หรือตามหมวด')

@section('content')
<div class="nx-card p-5" x-data="thumbGen()">

    <div class="mb-5 grid grid-cols-2 gap-3 sm:max-w-md">
        <div class="rounded-xl bg-white/[0.03] p-4">
            <div class="text-[12px] text-cream/50">ตอนทั้งหมด</div>
            <div class="text-2xl font-bold">{{ number_format($total) }}</div>
        </div>
        <div class="rounded-xl bg-white/[0.03] p-4">
            <div class="text-[12px] text-cream/50">ยังไม่มีปก</div>
            <div class="text-2xl font-bold text-[#ffb454]">{{ number_format($missing) }}</div>
        </div>
    </div>

    {{-- Scope --}}
    <div class="space-y-3 text-sm">
        <label class="flex items-center gap-2.5">
            <input type="radio" value="title" x-model="scope" class="h-4 w-4 accent-brand"> ตามเรื่อง (แนะนำ)
        </label>
        <div x-show="scope==='title'" class="relative ml-6.5" style="margin-left:1.6rem">
            <input x-model="titleQ" @input.debounce.300ms="searchTitle()" @focus="searchTitle()"
                   placeholder="พิมพ์ชื่อเรื่องเพื่อค้นหา…"
                   class="w-full max-w-md rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-cream placeholder:text-cream/30">
            <div x-show="titleResults.length && !contentId" x-cloak
                 class="absolute z-20 mt-1 w-full max-w-md overflow-hidden rounded-lg border border-white/10 bg-[#141019] shadow-xl">
                <template x-for="r in titleResults" :key="r.id">
                    <button type="button" @click="pickTitle(r)"
                            class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-white/5">
                        <span class="truncate" x-text="r.title"></span>
                        <span class="shrink-0 text-[11px] text-cream/40" x-text="r.episodes + ' ตอน'"></span>
                    </button>
                </template>
            </div>
            <div x-show="contentId" class="mt-2 flex items-center gap-2 text-[13px]">
                <span class="rounded-full bg-brand/20 px-3 py-1 text-brand-2" x-text="'เลือก: ' + contentLabel"></span>
                <button type="button" @click="clearTitle()" class="text-cream/40 hover:text-cream">✕ ล้าง</button>
            </div>
        </div>

        <label class="flex flex-wrap items-center gap-2.5">
            <input type="radio" value="genre" x-model="scope" class="h-4 w-4 accent-brand"> ตามหมวด
            <select x-model="genreId" x-show="scope==='genre'"
                    class="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-sm text-cream">
                @foreach ($genres as $g)
                    <option value="{{ $g->id }}">{{ $g->name }}</option>
                @endforeach
            </select>
        </label>

        <label class="flex items-center gap-2.5">
            <input type="radio" value="all" x-model="scope" class="h-4 w-4 accent-brand"> ทั้งเว็บ
            <span class="text-[12px] text-cream/40">(ตอนเยอะมาก ใช้เวลานาน — หยุดได้)</span>
        </label>

        <label class="flex items-center gap-2.5 pt-1">
            <input type="checkbox" x-model="skipExisting" class="h-4 w-4 accent-brand">
            ข้ามตอนที่มีปกแล้ว <span class="text-cream/40">(ปิดเพื่อทำใหม่ทับของเดิมทั้งหมด)</span>
        </label>
    </div>

    {{-- How it works (queue) --}}
    <p class="mt-4 rounded-lg border border-white/5 bg-white/[0.02] px-3.5 py-2.5 text-[12px] leading-relaxed text-cream/45">
        ระบบจะส่งงานเข้าคิวแล้วให้เซิร์ฟเวอร์ทยอยสร้างปกในเบื้องหลัง (ffmpeg ทำงานฝั่ง CLI) —
        ปกจะค่อย ๆ ขึ้นตามแถบด้านล่าง ปิดหน้านี้แล้วงานก็ยังทำต่อได้
    </p>

    {{-- Controls --}}
    <div class="mt-5 flex flex-wrap items-center gap-2.5">
        <button @click="start()" :disabled="running || (scope==='title' && !contentId)"
                class="nx-gradient rounded-lg px-5 py-2.5 text-sm font-semibold disabled:opacity-40"
                style="box-shadow:0 8px 22px rgba(176,38,255,0.32)">เริ่มสร้างปก</button>
        <button x-show="running" @click="stop()" x-cloak
                class="rounded-lg bg-[#e5484d]/15 px-4 py-2.5 text-sm text-[#ff6b81] hover:bg-[#e5484d]/25">■ หยุด</button>
    </div>

    {{-- Progress --}}
    <div x-show="phase!=='idle'" x-cloak class="mt-6">
        <div class="h-3 w-full overflow-hidden rounded-full bg-white/10">
            <div class="nx-gradient h-full transition-all duration-300" :style="`width:${pct}%`"></div>
        </div>
        <div class="mt-2.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px] text-cream/60">
            <span x-show="running" x-cloak class="inline-block h-3.5 w-3.5 shrink-0 animate-spin rounded-full border-2 border-white/15 border-t-brand-2"></span>
            <span class="font-semibold" x-text="phaseText"></span>
            <span>· <b x-text="processed"></b> / <span x-text="total"></span> (<span x-text="pct"></span>%)</span>
            <span>· สำเร็จ <span class="text-success" x-text="processed - failed"></span></span>
            <span>· พลาด <span class="text-[#ff6b81]" x-text="failed"></span></span>
            <span x-show="running" x-cloak class="text-cream/35" x-text="'· ' + elapsed + ' วิ'"></span>
            <span x-show="pending>0" x-cloak class="text-cream/35" x-text="'· ในคิวเซิร์ฟเวอร์ ' + pending"></span>
        </div>

        {{-- Reassurance while the worker hasn't picked up the first job yet --}}
        <div x-show="running && processed===0 && phase==='running'" x-cloak class="mt-2 flex items-center gap-2 text-[12px] text-cream/45">
            <span class="inline-block h-1.5 w-1.5 animate-ping rounded-full bg-brand-2"></span>
            ส่งงานเข้าคิวแล้ว กำลังรอเซิร์ฟเวอร์หยิบไปทำ (ปกติ 1–2 วินาที) — ระบบทำงานอยู่ ไม่ได้ค้าง
        </div>

        {{-- Live log --}}
        <div x-show="log.length" class="mt-3 max-h-64 overflow-y-auto rounded-lg border border-white/5 bg-black/25 p-3 font-mono text-[12px] leading-relaxed">
            <template x-for="(row,i) in log" :key="i">
                <div :class="row.ok ? 'text-cream/70' : 'text-[#ff6b81]'">
                    <span x-text="row.ok ? '✓' : '✗'"></span>
                    <span x-text="row.text"></span>
                </div>
            </template>
        </div>
    </div>
</div>

<script>
function thumbGen() {
    const csrf = '{{ csrf_token() }}';
    const sleep = (ms) => new Promise(r => setTimeout(r, ms));
    return {
        scope: 'title', genreId: '{{ $genres->first()->id ?? '' }}',
        titleQ: '', titleResults: [], contentId: null, contentLabel: '',
        skipExisting: true,
        running: false, stopped: false, phase: 'idle', batch: null, priority: false,
        total: 0, processed: 0, failed: 0, after: 0, pending: 0, elapsed: 0, t0: 0, log: [],

        get force() { return !this.skipExisting; },
        get pct() { return this.total ? Math.min(100, Math.round(this.processed / this.total * 100)) : 0; },
        get phaseText() {
            if (this.phase === 'running') return this.processed > 0 ? 'กำลังสร้างปก…' : '⏳ รอเซิร์ฟเวอร์เริ่มงาน…';
            return { counting: 'กำลังนับตอน…', queuing: 'กำลังส่งเข้าคิว…',
                     done: '✅ เสร็จแล้ว', stopped: '■ หยุดแล้ว' }[this.phase] || '';
        },

        async req(url, opts) { const r = await fetch(url, opts); return r.json(); },
        post(url, body) {
            return this.req(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(body) });
        },
        scopeBody(extra = {}) {
            return { scope: this.scope, genre_id: this.genreId, content_id: this.contentId, force: this.force, ...extra };
        },

        async searchTitle() {
            const q = this.titleQ.trim();
            this.contentId = null;
            if (!q) { this.titleResults = []; return; }
            try { const res = await this.req('{{ route('admin.thumbs.search') }}?q=' + encodeURIComponent(q), {}); this.titleResults = res.items || []; }
            catch (e) { this.titleResults = []; }
        },
        pickTitle(r) { this.contentId = r.id; this.contentLabel = r.title + ' (' + r.episodes + ' ตอน)'; this.titleResults = []; },
        clearTitle() { this.contentId = null; this.contentLabel = ''; this.titleQ = ''; },
        reasonText(r) {
            return { no_source: 'แหล่งวิดีโอหมดอายุ/ไม่พร้อม', download_failed: 'ดาวน์โหลดไม่ได้',
                     ffmpeg_failed: 'แปลงภาพไม่ได้', stopped: 'หยุดแล้ว', error: 'ผิดพลาด' }[r] || r;
        },

        async start() {
            if (this.running) return;
            this.running = true; this.stopped = false;
            this.processed = 0; this.failed = 0; this.after = 0; this.pending = 0; this.elapsed = 0; this.log = []; this.batch = null;

            // 1) Open the batch (snapshot the denominator).
            this.phase = 'counting';
            let begin;
            try { begin = await this.post('{{ route('admin.thumbs.begin') }}', this.scopeBody()); }
            catch (e) { this.phase = 'idle'; this.running = false; return; }
            this.batch = begin.batch; this.total = begin.total || 0;
            if (!this.total) { this.phase = 'done'; this.running = false; return; }
            // Small runs get the fast lane so they never queue behind a big backlog.
            this.priority = this.total <= 500;

            // 2) Enqueue every episode in the scope (fast — just DB inserts).
            this.phase = 'queuing';
            while (!this.stopped) {
                let res;
                try { res = await this.post('{{ route('admin.thumbs.enqueue') }}', this.scopeBody({ batch: this.batch, after_id: this.after, priority: this.priority })); }
                catch (e) { await sleep(1500); continue; }
                this.after = res.next_after;
                if (res.done) break;
            }

            // 3) Poll progress while the CLI worker drains the queue.
            this.phase = 'running';
            this.t0 = Date.now();
            let lastText = '';
            while (!this.stopped) {
                let p;
                try { p = await this.req('{{ route('admin.thumbs.progress') }}?batch=' + this.batch, {}); }
                catch (e) { await sleep(1200); continue; }
                this.processed = p.processed || 0;
                this.failed = p.failed || 0;
                this.pending = p.pending || 0;
                this.elapsed = Math.round((Date.now() - this.t0) / 1000);
                if (p.last && p.last.text && p.last.text !== lastText) {
                    lastText = p.last.text;
                    const why = p.last.ok ? '' : ' — ' + this.reasonText(p.last.reason);
                    this.log.unshift({ ok: p.last.ok, text: p.last.text + why });
                    if (this.log.length > 60) this.log.length = 60;
                }
                if (this.processed >= this.total) break;
                await sleep(1200);
            }
            this.phase = this.stopped ? 'stopped' : 'done';
            this.running = false;
        },
        stop() {
            this.stopped = true;
            if (this.batch) { try { this.post('{{ route('admin.thumbs.stop') }}', { batch: this.batch }); } catch (e) {} }
        },
    };
}
</script>
@endsection
