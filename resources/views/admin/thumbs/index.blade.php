@extends('layouts.admin')
@section('page-title', 'สร้างปกตอน')
@section('page-subtitle', 'สร้างภาพปกของแต่ละตอนด้วย ffmpeg (WebP) — เลือกทีละเรื่อง (แนะนำ) ทั้งหมด หรือตามหมวด')

@section('content')
<div class="nx-card p-5" x-data="thumbGen()" x-init="init()">

    <div class="mb-5 grid grid-cols-2 gap-3 sm:max-w-md">
        <div class="rounded-xl bg-white/[0.03] p-4">
            <div class="text-[12px] text-cream/50">ตอนทั้งหมด</div>
            <div class="text-2xl font-bold">{{ number_format($total) }}</div>
        </div>
        <div class="rounded-xl bg-white/[0.03] p-4">
            <div class="text-[12px] text-cream/50">ยังไม่มีปก</div>
            <div class="text-2xl font-bold text-[#ffb454] transition-all" x-text="liveMissing.toLocaleString()"></div>
        </div>
    </div>

    {{-- Re-attached to a run already going on the server (page was closed & reopened) --}}
    <div x-show="attached && (phase==='seeding' || phase==='running')" x-cloak
         class="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-brand/20 bg-brand/10 px-4 py-3 text-[13px]">
        <span class="inline-block h-2 w-2 animate-ping rounded-full bg-brand-2"></span>
        <span class="text-cream/80">มีงานที่สั่งไว้กำลังทำงานอยู่บนเซิร์ฟเวอร์ (เปิดหน้านี้ค้างไว้หรือปิดไปก็ได้)</span>
        <span x-show="label" class="rounded-full bg-white/5 px-2.5 py-0.5 text-brand-2" x-text="label"></span>
    </div>

    {{-- Finished run still on screen from a previous visit --}}
    <div x-show="attached && (phase==='done' || phase==='stopped')" x-cloak
         class="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-white/10 bg-white/[0.03] px-4 py-3 text-[13px]">
        <span class="text-cream/70" x-text="phase==='stopped' ? 'งานก่อนหน้าถูกหยุดไว้' : 'งานก่อนหน้าทำเสร็จแล้ว'"></span>
        <span x-show="label" class="rounded-full bg-white/5 px-2.5 py-0.5 text-cream/60" x-text="label"></span>
        <span x-show="failedIds>0" class="text-[#ffb454]" x-text="'· พลาด ' + failedIds.toLocaleString() + ' ตอน'"></span>
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
        <b class="text-cream/60">ปิดหน้านี้หรือปิดเว็บได้เลย งานจะยังทำต่อ</b> กลับมาเปิดหน้านี้อีกครั้งจะเห็นงานเดิมที่ค้างอยู่
    </p>

    {{-- Controls --}}
    <div class="mt-5 flex flex-wrap items-center gap-2.5">
        <button @click="start()" :disabled="running || (scope==='title' && !contentId)"
                class="nx-gradient rounded-lg px-5 py-2.5 text-sm font-semibold disabled:opacity-40"
                style="box-shadow:0 8px 22px rgba(176,38,255,0.32)">เริ่มสร้างปก</button>
        <button x-show="running" @click="stop()" x-cloak
                class="rounded-lg bg-[#e5484d]/15 px-4 py-2.5 text-sm text-[#ff6b81] hover:bg-[#e5484d]/25">■ หยุด</button>
        {{-- Redo ONLY the failures of the finished run — no rescan --}}
        <button x-show="!running && failedIds>0" @click="redoFailed()" x-cloak
                class="rounded-lg bg-[#ffb454]/15 px-4 py-2.5 text-sm text-[#ffb454] hover:bg-[#ffb454]/25"
                x-text="'↻ ทำซ้ำเฉพาะที่พลาด (' + failedIds.toLocaleString() + ')'"></button>
    </div>

    {{-- Progress --}}
    <div x-show="phase!=='idle'" x-cloak class="mt-6">
        <div class="h-3 w-full overflow-hidden rounded-full bg-white/10">
            <div class="nx-gradient h-full transition-all duration-300" :style="`width:${pct}%`"></div>
        </div>
        <div class="mt-2.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px] text-cream/60">
            <span x-show="running" x-cloak class="inline-block h-3.5 w-3.5 shrink-0 animate-spin rounded-full border-2 border-white/15 border-t-brand-2"></span>
            <span class="font-semibold" x-text="phaseText"></span>
            {{-- While sending: the % is the SEND progress (matches the bar) --}}
            <template x-if="phase==='seeding'">
                <span>· ส่งเข้าคิว <b x-text="seeded.toLocaleString()"></b> / <span x-text="seedTotal.toLocaleString()"></span> (<span x-text="seedPct"></span>%)</span>
            </template>
            {{-- Otherwise: the % is the PROCESSING progress --}}
            <template x-if="phase!=='seeding'">
                <span>· <b x-text="processed.toLocaleString()"></b> / <span x-text="procTarget.toLocaleString()"></span> (<span x-text="procPct"></span>%)</span>
            </template>
            <span>· สำเร็จ <span class="text-success" x-text="(processed - failed).toLocaleString()"></span></span>
            <span>· พลาด <span class="text-[#ff6b81]" x-text="failed.toLocaleString()"></span></span>
            <span x-show="running" x-cloak class="text-cream/35" x-text="'· ' + elapsed + ' วิ'"></span>
            <span x-show="pending>0" x-cloak class="text-cream/35" x-text="'· ในคิวเซิร์ฟเวอร์ ' + pending"></span>
        </div>

        {{-- While still sending to queue, show generation is already running too --}}
        <div x-show="phase==='seeding'" x-cloak class="mt-1.5 text-[12px] text-cream/45">
            เซิร์ฟเวอร์เริ่มสร้างปกไปพร้อมกันแล้ว · สร้างเสร็จ
            <b class="text-cream/70" x-text="(processed - failed).toLocaleString()"></b> ตอน
        </div>

        {{-- Reassurance while the worker hasn't picked up the first job yet --}}
        <div x-show="running && processed===0 && phase==='running'" x-cloak class="mt-2 flex items-center gap-2 text-[12px] text-cream/45">
            <span class="inline-block h-1.5 w-1.5 animate-ping rounded-full bg-brand-2"></span>
            ส่งงานเข้าคิวครบแล้ว กำลังรอเซิร์ฟเวอร์หยิบไปทำ (ปกติ 1–2 วินาที) — ระบบทำงานอยู่ ไม่ได้ค้าง
        </div>

        {{-- Live agent grid — one card per worker currently generating --}}
        <div x-show="agents.length" x-cloak class="mt-4">
            <div class="mb-2 text-[12px] text-cream/45" x-text="'เอเจนที่กำลังทำงาน (' + agents.length + ')'"></div>
            <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2 lg:grid-cols-3">
                <template x-for="(a,i) in agents" :key="i">
                    <div class="rounded-xl border border-white/8 bg-white/[0.03] p-3.5">
                        <div class="flex items-center gap-2">
                            <span class="inline-block h-3 w-3 shrink-0 animate-spin rounded-full border-2 border-white/15 border-t-brand-2"></span>
                            <span class="text-[12px] font-semibold text-brand-2" x-text="'Agent ' + (i+1)"></span>
                            <span class="ml-auto rounded-full bg-white/5 px-2 py-0.5 text-[11px] text-cream/50" x-text="'ทำแล้ว ' + a.done"></span>
                        </div>
                        <div class="mt-2 truncate text-[13px] font-medium text-cream/85" x-text="a.title" :title="a.title"></div>
                        <div class="text-[12px] text-cream/45" x-text="'กำลังสร้างปก · ตอน ' + a.ep"></div>
                    </div>
                </template>
            </div>
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
        liveMissing: {{ (int) $missing }},
        running: false, stopped: false, phase: 'idle', batch: null,
        attached: false, label: '',
        total: 0, processed: 0, failed: 0, failedIds: 0, pending: 0, elapsed: 0, t0: 0,
        seeded: 0, seedTotal: 0, seedDone: false,
        agents: [], log: [], _lastText: '',

        get force() { return !this.skipExisting; },
        // Processing denominator: while seeding, `total` is the begin-time estimate;
        // once seeding is done the true count is what was actually dispatched.
        get procTarget() { return this.seedDone ? (this.seeded || this.total) : this.total; },
        get procPct() { return this.procTarget ? Math.min(100, Math.round(this.processed / this.procTarget * 100)) : 0; },
        get seedPct() { return this.seedTotal ? Math.min(100, Math.round(this.seeded / this.seedTotal * 100)) : 0; },
        // The main bar shows send-progress while seeding, then processing progress.
        get pct() { return this.phase === 'seeding' ? this.seedPct : this.procPct; },
        get phaseText() {
            if (this.phase === 'seeding') return '📤 กำลังส่งงานเข้าคิว…';
            if (this.phase === 'running') return this.processed > 0 ? 'กำลังสร้างปก…' : '⏳ รอเซิร์ฟเวอร์เริ่มงาน…';
            return { done: '✅ เสร็จแล้ว', stopped: '■ หยุดแล้ว' }[this.phase] || '';
        },

        async req(url, opts) { const r = await fetch(url, opts); return r.json(); },
        post(url, body) {
            return this.req(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(body || {}) });
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

        // Copy a server snapshot into the component + drive the live log.
        apply(p) {
            this.label = p.label || this.label;
            this.total = p.total || 0;
            this.processed = p.processed || 0;
            this.failed = p.failed || 0;
            this.failedIds = p.failed_ids || 0;
            this.pending = p.pending || 0;
            this.seeded = p.seeded || 0;
            this.seedTotal = p.seed_total || 0;
            this.seedDone = !!p.seed_done;
            this.agents = p.agents || [];
            this.phase = p.status; // seeding | running | done | stopped
            if (typeof p.missing === 'number') this.liveMissing = p.missing;
            if (p.last && p.last.text && p.last.text !== this._lastText) {
                this._lastText = p.last.text;
                const why = p.last.ok ? '' : ' — ' + this.reasonText(p.last.reason);
                this.log.unshift({ ok: p.last.ok, text: p.last.text + why });
                if (this.log.length > 60) this.log.length = 60;
            }
        },

        // On page load: re-attach to whatever run is already going on the server.
        async init() {
            let a;
            try { a = await this.req('{{ route('admin.thumbs.active') }}', {}); }
            catch (e) { return; }
            if (!a || !a.active) return;
            this.batch = a.active;
            this.attached = true;
            this.apply(a);
            if (a.status === 'seeding' || a.status === 'running') {
                this.running = true; this.stopped = false; this.t0 = Date.now();
                this.watch();
            }
            // done / stopped → leave it on screen; the redo button shows if failedIds>0.
        },

        async start() {
            if (this.running) return;
            this.reset();
            this.running = true;

            let begin;
            try { begin = await this.post('{{ route('admin.thumbs.begin') }}', this.scopeBody()); }
            catch (e) { this.phase = 'idle'; this.running = false; return; }
            this.batch = begin.batch; this.total = begin.total || 0; this.seedTotal = this.total;
            if (!this.total) { this.phase = 'done'; this.running = false; return; }

            // Seeding + generation now happen entirely on the server — just watch.
            this.phase = 'seeding'; this.t0 = Date.now();
            this.watch();
        },

        // Re-run ONLY the failed episodes of the current batch (no catalogue scan).
        async redoFailed() {
            if (this.running || !this.batch) return;
            let res;
            try { res = await this.post('{{ route('admin.thumbs.redo-failed') }}', { batch: this.batch }); }
            catch (e) { return; }
            if (!res || !res.batch) { this.failedIds = 0; return; }
            this.reset();
            this.batch = res.batch; this.total = res.total || 0; this.seedTotal = this.total;
            this.running = true; this.phase = 'seeding'; this.t0 = Date.now();
            this.watch();
        },

        // Poll the server until the run is done or stopped.
        async watch() {
            while (true) {
                if (this.stopped) { this.phase = 'stopped'; break; }
                let p;
                try { p = await this.req('{{ route('admin.thumbs.progress') }}?batch=' + this.batch, {}); }
                catch (e) { await sleep(1200); continue; }
                if (this.stopped) { this.phase = 'stopped'; break; } // stop pressed mid-poll
                this.apply(p); // sets phase from server status (seeding|running|done|stopped)
                this.elapsed = Math.round((Date.now() - this.t0) / 1000);
                if (p.status === 'done' || p.status === 'stopped') break;
                await sleep(1200);
            }
            this.running = false; this.agents = [];
        },

        reset() {
            this.stopped = false; this.attached = false; this._lastText = '';
            this.processed = 0; this.failed = 0; this.failedIds = 0; this.pending = 0;
            this.elapsed = 0; this.seeded = 0; this.seedTotal = 0; this.seedDone = false;
            this.agents = []; this.log = []; this.batch = null;
        },
        stop() {
            this.stopped = true; this.phase = 'stopped'; this.running = false;
            if (this.batch) { try { this.post('{{ route('admin.thumbs.stop') }}', { batch: this.batch }); } catch (e) {} }
        },
    };
}
</script>
@endsection
