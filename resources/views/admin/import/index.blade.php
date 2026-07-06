@extends('layouts.admin')
@section('page-title', 'นำเข้าหนัง')
@section('page-subtitle', 'ดึงคอนเทนต์จากแหล่งภายนอกเข้าคลัง NetWix')
@section('action')
    <div class="flex items-center gap-2.5">
        {{-- Daily auto-import on/off (drives netwix:auto-import). Clicking submits the opposite state. --}}
        <form method="POST" action="{{ route('admin.import.auto-toggle') }}" title="ดึงหนังใหม่จากทุกแหล่งให้เองทุกวัน">
            @csrf
            <input type="hidden" name="enabled" value="{{ $autoImport ? '0' : '1' }}">
            <button class="flex items-center gap-2 rounded-lg border px-3.5 py-2.5 text-sm font-semibold transition {{ $autoImport ? 'border-success/40 bg-success/15 text-success' : 'border-white/10 bg-white/5 text-cream/55 hover:text-cream' }}">
                <span class="relative inline-flex h-4 w-7 shrink-0 items-center rounded-full transition {{ $autoImport ? 'bg-success/70' : 'bg-white/15' }}">
                    <span class="absolute h-3 w-3 rounded-full bg-white transition-all" style="{{ $autoImport ? 'left:14px' : 'left:2px' }}"></span>
                </span>
                นำเข้าอัตโนมัติทุกวัน · {{ $autoImport ? 'เปิด' : 'ปิด' }}
            </button>
        </form>
        <form method="POST" action="{{ route('admin.import.sync') }}" x-data="syncer()" @submit.prevent="run()">
            @csrf
            <button type="submit" x-bind:disabled="running"
                    class="nx-gradient flex items-center gap-1.5 rounded-lg px-4 py-2.5 text-sm font-semibold disabled:opacity-40" style="box-shadow:0 8px 22px rgba(176,38,255,0.32)">
                <span x-show="!running">⟳ ซิงค์แคตตาล็อก</span>
                <span x-show="running" x-cloak>⟳ กำลังซิงค์…</span>
            </button>

            {{-- Sync progress overlay — teleported to <body> because the header's backdrop-blur creates a
                 containing block that would clip a position:fixed child. --}}
            <template x-teleport="body">
                <div x-show="running || done" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center bg-black/75 p-4">
                    <div class="nx-card w-full max-w-lg p-6">
                        <h3 class="mb-4 text-lg font-bold" x-text="title"></h3>

                        <div x-show="!done" class="mb-3 h-2.5 overflow-hidden rounded-full bg-white/10">
                            <div class="h-full rounded-full nx-gradient" style="width:34%;animation:nxIndet 1.15s ease-in-out infinite"></div>
                        </div>

                        <div x-show="!done" class="mb-1 text-sm text-cream/70">
                            ซิงค์แล้ว <span class="font-bold text-cream" x-text="synced.toLocaleString()"></span> เรื่อง
                            <span x-show="stopping" class="text-cream/50"> · กำลังหยุด…</span>
                        </div>
                        <p x-show="!done" class="text-xs text-cream/45">ทำงานเบื้องหลัง — ปิดหน้านี้ได้ ระบบจะซิงค์ต่อจนเสร็จ (หน้าจะจำสถานะเมื่อเปิดใหม่)</p>

                        <p x-show="done && message" x-cloak class="text-sm text-success" x-text="message"></p>
                        <p x-show="done && error" x-cloak class="text-sm" style="color:#ff6b81" x-text="error"></p>

                        <div class="mt-5 flex justify-end gap-2">
                            <button type="button" x-show="!done" @click="stop()" x-bind:disabled="stopping"
                                    class="rounded-lg bg-white/10 px-4 py-2 text-sm hover:bg-white/15 disabled:opacity-40">หยุดกลางคัน</button>
                            <button type="button" x-show="done" @click="window.location.reload()"
                                    class="btn-brand px-5 py-2 text-sm">รีเฟรชรายการ</button>
                            <button type="button" x-show="done" @click="done = false"
                                    class="rounded-lg bg-white/10 px-4 py-2 text-sm hover:bg-white/15">ปิด</button>
                        </div>
                    </div>
                </div>
            </template>
        </form>
    </div>
@endsection

@section('content')
<script>
// User-stop signal — thrown by nxPostRetry when the admin presses "หยุดกลางคัน" so callers can tell
// a deliberate cancel apart from a real error and exit quietly.
class NxCancelled extends Error { constructor() { super('cancelled'); this.name = 'NxCancelled'; } }
window.NxCancelled = NxCancelled;

// Only a *connection-level* failure is worth auto-retrying: network dropped (fetch throws), a timeout,
// or the server temporarily unavailable (5xx / 408 / 429). A real rejection (validation / auth, other
// 4xx) must NOT loop forever — it's surfaced immediately as fatal.
window.nxRetriable = (s) => s === 0 || s === 408 || s === 425 || s === 429 || s >= 500;

/**
 * POST that auto-retries a dropped connection instead of giving up (owner rule: "ถ้าเชื่อมต่อไม่ได้ …
 * ทำใหม่ซ้ำอัตโนมัติไม่ใช่หยุดไปเลย"). Retries FOREVER with capped exponential backoff (1→30s) until it
 * succeeds or the admin cancels — isCancelled() is polled between attempts, during the backoff wait,
 * AND mid-flight (the in-flight fetch is aborted) so "หยุดกลางคัน" is instant. A non-retriable 4xx is
 * thrown as { fatal:true } so it never loops. onRetry(attempt, err) drives the "กำลังลองใหม่" banner.
 */
window.nxPostRetry = async function (url, body, { isCancelled, onRetry } = {}) {
    const cancelled = () => (isCancelled ? isCancelled() : false);
    for (let attempt = 1; ; attempt++) {
        if (cancelled()) throw new NxCancelled();
        try {
            const ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
            const poll = ctrl ? setInterval(() => { if (cancelled()) ctrl.abort(); }, 200) : null;
            let r;
            try {
                r = await fetch(url, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    body,
                    signal: ctrl ? ctrl.signal : undefined,
                });
            } finally { if (poll) clearInterval(poll); }

            if (!r.ok) {
                if (window.nxRetriable(r.status)) throw new Error('HTTP ' + r.status);
                let data = null; try { data = await r.json(); } catch (e) {}
                const err = new Error((data && (data.error || data.message)) || ('HTTP ' + r.status));
                err.fatal = true; err.status = r.status; err.data = data;
                throw err;
            }
            return await r.json();
        } catch (e) {
            if (e instanceof NxCancelled) throw e;
            if (cancelled()) throw new NxCancelled();   // stop pressed (incl. an aborted in-flight fetch)
            if (e.fatal) throw e;                        // genuine rejection — surface it, don't loop
            if (onRetry) onRetry(attempt, e);
            // 1,2,4,8,16 → capped 30s (+jitter). Broken into 250ms slices so a cancel escapes fast.
            const wait = Math.min(30000, 1000 * Math.pow(2, Math.min(attempt - 1, 5))) + Math.floor(Math.random() * 500);
            for (let waited = 0; waited < wait; waited += 250) {
                if (cancelled()) throw new NxCancelled();
                await new Promise((res) => setTimeout(res, 250));
            }
        }
    }
};

window.importer = () => ({
    sel: [],
    running: false, done: false, cancelled: false, autoMode: false,
    retrying: false, retryAttempt: 0,
    total: 0, processed: 0, ok: 0, failed: 0, log: [],
    chunkSize: 4,
    get pct() { return this.total ? Math.round(this.processed / this.total * 100) : 0; },
    titleFor(id) {
        const el = document.querySelector('.imp-cb[value="' + id + '"]');
        return el ? el.dataset.title : ('#' + id);
    },
    baseBody(form) {
        const fd = new FormData(form);
        const body = new URLSearchParams();
        body.set('_token', fd.get('_token'));
        body.set('source', fd.get('source'));
        body.set('type', fd.get('type') || '');
        if (fd.get('publish')) body.set('publish', '1');
        if (fd.get('auto_type')) body.set('auto_type', '1');
        if (fd.get('auto_genres')) body.set('auto_genres', '1');
        if (fd.get('primary_genre')) body.set('primary_genre', fd.get('primary_genre'));
        [...form.querySelectorAll('input[name="genres[]"]:checked')].forEach(c => body.append('genres[]', c.value));
        return body;
    },
    pushResults(list, onFail) {
        (list || []).forEach(res => {
            this.processed++;
            res.ok ? this.ok++ : (this.failed++, onFail && onFail(res));
            this.log.unshift({
                title: res.title, ok: res.ok,
                detail: res.ok ? ((res.type || '') + ' · ' + (res.episodes || 0) + ' ตอน') : (res.error || 'ผิดพลาด'),
            });
        });
        this.log = this.log.slice(0, 60);
    },
    // Auto-import: keep pulling the next un-imported chunk from the server until none remain. A dropped
    // connection retries automatically (nxPostRetry) instead of aborting — the loop only ends on
    // success-exhausted, the admin's "หยุดกลางคัน", or a genuine (non-connection) error.
    async runAuto(form) {
        if (this.running) return;
        this.running = true; this.done = false; this.cancelled = false; this.autoMode = true;
        this.retrying = false; this.retryAttempt = 0;
        this.total = 0; this.processed = 0; this.ok = 0; this.failed = 0; this.log = [];
        const exclude = [];
        try {
            while (!this.cancelled) {
                const body = this.baseBody(form);
                body.set('chunk', '5');
                exclude.forEach(id => body.append('exclude[]', id));
                const j = await window.nxPostRetry('{{ route('admin.import.auto') }}', body, {
                    isCancelled: () => this.cancelled,
                    onRetry: (n) => { this.retrying = true; this.retryAttempt = n; },
                });
                this.retrying = false;
                this.pushResults(j.results, res => exclude.push(res.id));
                this.total = this.processed + (j.remaining || 0);
                if (!(j.results || []).length || j.remaining === 0) break;
            }
        } catch (e) {
            this.retrying = false;
            if (!(e instanceof window.NxCancelled)) {
                this.log.unshift({ title: 'หยุดการนำเข้า — ' + (e.message || 'เกิดข้อผิดพลาด'), ok: false, detail: '' });
            }
        }
        this.done = true;
    },
    // Import just the checked titles. A dropped connection retries the same chunk automatically; only a
    // real per-chunk error records those titles as failed and moves on. "หยุดกลางคัน" breaks out.
    async run(form) {
        if (this.running || this.sel.length === 0) return;
        this.running = true; this.done = false; this.cancelled = false; this.autoMode = false;
        this.retrying = false; this.retryAttempt = 0;
        this.total = this.sel.length; this.processed = 0; this.ok = 0; this.failed = 0; this.log = [];
        const ids = [...this.sel];
        try {
            for (let i = 0; i < ids.length && !this.cancelled; i += this.chunkSize) {
                const batch = ids.slice(i, i + this.chunkSize);
                const body = this.baseBody(form);
                batch.forEach(id => body.append('ids[]', id));
                let j;
                try {
                    j = await window.nxPostRetry('{{ route('admin.import.batch') }}', body, {
                        isCancelled: () => this.cancelled,
                        onRetry: (n) => { this.retrying = true; this.retryAttempt = n; },
                    });
                    this.retrying = false;
                } catch (e) {
                    this.retrying = false;
                    if (e instanceof window.NxCancelled) break;
                    batch.forEach(id => {
                        this.processed++; this.failed++;
                        this.log.unshift({ title: this.titleFor(id), ok: false, detail: e.message || 'ผิดพลาด' });
                    });
                    continue;
                }
                this.pushResults(j.results);
            }
        } finally {
            this.done = true;
        }
    },
});

// Catalogue sync runs as a BACKGROUND JOB (SyncCatalogJob on the `sync` queue) so a big/slow scrape
// never blocks a web request past Cloudflare's ~100s timeout — which used to make the browser retry
// and stack concurrent 30-min scrapes (incident 2026-07-06). This component just STARTS the job then
// POLLS progress; it re-attaches on page reload, shows a live count, and can stop the run. Its own
// Alpine component because the button lives in the header, apart from the import form.
window.syncer = () => ({
    source: @js($sourceId),
    startUrl: '{{ route('admin.import.sync') }}',
    progressUrl: '{{ route('admin.import.sync.progress') }}',
    stopUrl: '{{ route('admin.import.sync.stop') }}',
    running: false, done: false, ok: false, stopped: false, stopping: false,
    synced: 0, total: 0, message: '', error: '',
    poller: null, ticks: 0,
    tok() { return document.querySelector('meta[name="csrf-token"]')?.content || ''; },
    // Alpine auto-calls init() once. Re-attach to a sync already running server-side (page reloaded
    // mid-sync) so the admin sees it instead of a blank button — and can't accidentally start another.
    init() { this.resume(); },
    async resume() {
        const s = await this.poll();
        if (s && (s.status === 'running' || s.status === 'queued')) {
            this.running = true; this.done = false;
            this.synced = s.synced || 0; this.total = s.total || 0;
            this.startPolling();
        }
    },
    async poll() {
        try {
            const r = await fetch(this.progressUrl + '?source=' + encodeURIComponent(this.source),
                { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
            return r.ok ? await r.json() : null;
        } catch (e) { return null; }
    },
    async run() {
        if (this.running) return;
        this.running = true; this.done = false; this.ok = false; this.stopped = false; this.stopping = false;
        this.synced = 0; this.message = ''; this.error = ''; this.ticks = 0;
        // START is fast (just queues the job) — retry a dropped connection so the click isn't lost.
        try {
            const body = new URLSearchParams();
            body.set('_token', this.tok());
            body.set('source', this.source);
            body.set('max_pages', '100');
            await window.nxPostRetry(this.startUrl, body, { isCancelled: () => false, onRetry: () => {} });
        } catch (e) {
            this.running = false; this.done = true;
            this.error = 'เริ่มซิงค์ไม่สำเร็จ: ' + (e.message || '');
            return;
        }
        this.startPolling();
    },
    startPolling() {
        this.stopPolling();
        this.tick();
        this.poller = setInterval(() => this.tick(), 2000);
    },
    stopPolling() { if (this.poller) { clearInterval(this.poller); this.poller = null; } },
    async tick() {
        // Safety cap (~13 min) — the job's own timeout is 10 min, so past this it's not coming back;
        // don't poll forever. It keeps running server-side either way.
        if (++this.ticks > 400) { this.stopPolling(); this.finish(false, false, '', 'ยังทำงานอยู่เบื้องหลัง — รีเฟรชหน้าเพื่อดูผลล่าสุด'); return; }
        const s = await this.poll();
        if (!s) return;                        // transient poll failure — retry next tick
        this.synced = s.synced || 0;
        this.total = s.total || 0;
        if (s.status === 'done')    return this.finish(true, false, s.message, '');
        if (s.status === 'stopped') return this.finish(false, true, s.message, '');
        if (s.status === 'error')   return this.finish(false, false, '', s.error || 'ซิงค์ไม่สำเร็จ');
        // queued | running → keep polling
    },
    finish(ok, stopped, message, error) {
        this.stopPolling();
        this.ok = ok; this.stopped = stopped;
        this.message = message || ''; this.error = error || '';
        this.done = true; this.running = false;
    },
    async stop() {
        this.stopping = true;
        try {
            const body = new URLSearchParams();
            body.set('_token', this.tok());
            body.set('source', this.source);
            await fetch(this.stopUrl, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, body });
        } catch (e) { /* the next page boundary flips it to "stopped" anyway */ }
    },
    get title() {
        if (!this.done) return this.stopping ? 'กำลังหยุด…' : 'กำลังซิงค์แคตตาล็อก…';
        if (this.stopped) return 'หยุดการซิงค์แล้ว';
        return this.ok ? 'ซิงค์เสร็จสิ้น ✓' : 'ซิงค์ไม่สำเร็จ';
    },
});
</script>
<style>@keyframes nxIndet{0%{transform:translateX(-120%)}100%{transform:translateX(220%)}}</style>
{{-- Source tabs --}}
<div class="mb-5 flex flex-wrap gap-2">
    @foreach ($sources as $s)
        <a href="{{ route('admin.import.index', ['source' => $s['id']]) }}"
           class="flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm transition {{ $sourceId === $s['id'] ? 'nx-gradient font-semibold' : 'bg-white/5 text-cream/60 hover:text-cream' }}">
            {{ $s['name'] }}
            <span class="rounded-full bg-black/25 px-2 py-0.5 text-[11px]">ซิงค์ {{ number_format($s['synced']) }} · นำเข้า {{ number_format($s['imported']) }}</span>
        </a>
    @endforeach
</div>

{{-- NetWix resolves signed CDN links itself at watch time, so importing needs no home downloader.
     Hive Download is now optional (only for pre-downloading preview files). --}}
<div class="mb-5 flex flex-wrap items-center gap-3 rounded-xl border border-success/25 bg-success/[0.06] px-4 py-3 text-sm">
    <span class="relative inline-flex h-3 w-3 rounded-full bg-success"></span>
    <span class="font-semibold">พร้อมนำเข้า — NetWix ดึงลิงก์วิดีโอสดเองบนเซิร์ฟเวอร์</span>
    <span class="text-cream/50">
        โปรแกรม Hive Download เป็นอุปกรณ์เสริม (ใช้ดาวน์โหลดไฟล์พรีวิวเท่านั้น)
        @if ($agent['connected'])· เชื่อมต่ออยู่{{ $agent['last_seen'] ? ' · '.$agent['last_seen']->diffForHumans() : '' }}@endif
    </span>
    <a href="{{ route('admin.storage.index') }}" class="ml-auto text-xs text-brand hover:underline">ดูสถานะ →</a>
</div>

@if ($titles->total() === 0)
    <div class="nx-card p-10 text-center text-cream/55">
        ยังไม่มีข้อมูลจาก {{ $currentSource?->displayName() }} — กด <span class="text-cream">⟳ ซิงค์แคตตาล็อก</span> ด้านบนมุมขวาเพื่อดึงรายการหนังก่อน
        <div class="mt-2 text-xs text-cream/40">การซิงค์ครั้งแรกอาจใช้เวลาสักครู่ (ดึงจากเว็บต้นทางโดยตรง)</div>
    </div>
@else
    {{-- Filter / search --}}
    <form method="GET" action="{{ route('admin.import.index') }}" class="mb-4 flex flex-wrap items-center gap-2">
        <input type="hidden" name="source" value="{{ $sourceId }}">
        @foreach (['all' => 'ทั้งหมด', 'new' => 'ยังไม่นำเข้า', 'imported' => 'นำเข้าแล้ว'] as $k => $lbl)
            <a href="{{ route('admin.import.index', ['source' => $sourceId, 'filter' => $k, 'q' => $q]) }}"
               class="rounded-lg px-3.5 py-2 text-sm {{ $filter === $k ? 'nx-gradient font-semibold' : 'bg-white/5 text-cream/60 hover:text-cream' }}">{{ $lbl }}</a>
        @endforeach
        <input type="text" name="q" value="{{ $q }}" placeholder="ค้นหาชื่อเรื่อง…"
               class="ml-auto rounded-lg border border-white/10 bg-surface-2 px-3.5 py-2 text-sm outline-none focus:border-brand">
    </form>

    <form method="POST" action="{{ route('admin.import.store') }}" x-data="importer()" @submit.prevent="run($el)">
        @csrf
        <input type="hidden" name="source" value="{{ $sourceId }}">

        {{-- Options toolbar --}}
        <div class="nx-card sticky top-[73px] z-10 mb-4 flex flex-wrap items-center gap-4 p-4">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" class="accent-brand"
                       @change="sel = $event.target.checked ? [...document.querySelectorAll('.imp-cb')].map(c=>{c.checked=true;return c.value}) : (document.querySelectorAll('.imp-cb').forEach(c=>c.checked=false),[])">
                เลือกทั้งหน้า
            </label>
            <span class="text-sm text-cream/60">เลือกแล้ว <span class="font-bold text-cream" x-text="sel.length"></span> เรื่อง</span>

            <div class="flex items-center gap-2 text-sm">
                <span class="text-cream/50">ประเภท:</span>
                <select name="type" class="rounded-md border border-white/10 bg-surface-2 px-2.5 py-1.5 text-sm outline-none">
                    @php $dt = $currentSource?->defaultContentType(); @endphp
                    <option value="vertical" @selected($dt === 'vertical')>ซีรีส์แนวตั้ง</option>
                    <option value="series" @selected($dt === 'series')>ซีรี่ส์</option>
                    <option value="movie" @selected($dt === 'movie')>ภาพยนตร์</option>
                </select>
            </div>

            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="publish" value="1" checked class="accent-brand"> เผยแพร่ทันที</label>
            <label class="flex items-center gap-2 text-sm" title="แยกหนัง/ซีรีส์ให้อัตโนมัติตามข้อมูลต้นทาง (Anime108)">
                <input type="checkbox" name="auto_type" value="1" class="accent-brand"> แยกหนัง/ซีรีส์อัตโนมัติ</label>
            <label class="flex items-center gap-2 text-sm" title="ใส่หมวดหมู่ให้อัตโนมัติจากต้นทาง (Anime108) — ถ้าไม่ติ๊กจะใช้หมวดที่เลือกด้านล่าง">
                <input type="checkbox" name="auto_genres" value="1" class="accent-brand"> หมวดอัตโนมัติจากต้นทาง</label>

            <div class="ml-auto flex items-center gap-2">
                @if ($pending > 0)
                    <button type="button" x-bind:disabled="running"
                            @click="if (confirm('นำเข้าทุกเรื่องที่ยังไม่นำเข้าของแหล่งนี้ (~{{ number_format($pending) }} เรื่อง)?\nระบบจะทยอยนำเข้าทีละชุดจนหมด — กดหยุดได้ตลอดเวลา')) runAuto($root)"
                            class="rounded-lg bg-white/10 px-4 py-2.5 text-sm font-semibold hover:bg-white/15 disabled:opacity-40"
                            title="วนนำเข้าทุกเรื่องที่ยังไม่นำเข้าโดยอัตโนมัติจนกว่าจะหมด">
                        ⭮ นำเข้าทั้งหมดอัตโนมัติ <span class="text-cream/50">({{ number_format($pending) }})</span>
                    </button>
                @endif
                <button type="submit" x-bind:disabled="sel.length === 0"
                        class="btn-brand px-6 py-2.5 text-sm disabled:opacity-40">นำเข้าที่เลือก →</button>
            </div>
        </div>

        {{-- Genre assignment --}}
        <div class="nx-card mb-4 p-4">
            <div class="mb-2 text-sm text-cream/60">จัดหมวดหมู่ให้เรื่องที่นำเข้า (เลือกได้หลายหมวด, จุดคือหมวดหลัก)</div>
            <div class="flex flex-wrap gap-2">
                @forelse ($genres as $g)
                    <label class="flex items-center gap-1.5 rounded-lg bg-white/5 px-3 py-1.5 text-sm hover:bg-white/10">
                        <input type="checkbox" name="genres[]" value="{{ $g->id }}" class="accent-brand"> {{ $g->name }}
                        <input type="radio" name="primary_genre" value="{{ $g->id }}" class="ml-1 accent-brand" title="หมวดหลัก">
                    </label>
                @empty
                    <a href="{{ route('admin.genres.index') }}" class="text-sm text-brand">+ เพิ่มหมวดก่อน</a>
                @endforelse
            </div>
        </div>

        {{-- Titles grid --}}
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-6">
            @foreach ($titles as $t)
                <label class="group relative block cursor-pointer">
                    <input type="checkbox" name="ids[]" value="{{ $t->id }}" x-model="sel"
                           data-title="{{ $t->displayTitle() }}"
                           class="imp-cb absolute left-2 top-2 z-10 h-5 w-5 accent-brand">
                    <div class="relative aspect-[2/3] overflow-hidden rounded-lg ring-1 ring-white/10 transition group-hover:ring-2 group-hover:ring-brand"
                         style="background:linear-gradient(160deg,#1c1626,#120e1a)">
                        @if ($t->poster_url)
                            <img src="{{ $t->poster_url }}" alt="" loading="lazy" referrerpolicy="no-referrer"
                                 class="absolute inset-0 h-full w-full object-cover"
                                 onerror="this.style.display='none'">
                        @endif
                        @if ($t->content_id)
                            <span class="absolute right-2 top-2 rounded bg-success/90 px-1.5 py-0.5 text-[9px] font-bold text-black">นำเข้าแล้ว</span>
                        @endif
                        @if ($t->dubLabel())
                            <span class="absolute bottom-9 left-2 rounded bg-black/60 px-1.5 py-0.5 text-[9px]">{{ $t->dubLabel() }}</span>
                        @endif
                        @if (isset($duplicates[$t->id]))
                            <span class="absolute left-2 top-8 z-10 rounded px-1.5 py-0.5 text-[9px] font-semibold"
                                  style="background:rgba(245,197,66,.9);color:#1a1206"
                                  title="อาจซ้ำกับที่มีอยู่แล้ว: {{ $duplicates[$t->id] }}">⚠ อาจซ้ำ</span>
                        @endif
                        <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/90 to-transparent p-2">
                            <div class="line-clamp-2 text-[12px] font-semibold leading-tight">{{ $t->displayTitle() }}</div>
                            <div class="text-[10px] text-cream/50">{{ $t->year }} @if ($t->view_count) · 👁 {{ number_format($t->view_count) }} @endif</div>
                        </div>
                    </div>
                </label>
            @endforeach
        </div>

        <div class="mt-5">{{ $titles->links() }}</div>

        {{-- Live import progress overlay --}}
        <div x-show="running || done" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center bg-black/75 p-4">
            <div class="nx-card w-full max-w-lg p-6">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-lg font-bold" x-text="done ? (failed ? 'นำเข้าเสร็จ (มีบางเรื่องล้มเหลว)' : 'นำเข้าเสร็จสิ้น ✓') : (autoMode ? 'นำเข้าทั้งหมดอัตโนมัติ…' : 'กำลังนำเข้า…')"></h3>
                    <span class="text-sm text-cream/60"><span x-text="processed"></span> / <span x-text="total"></span></span>
                </div>
                <div class="mb-3 h-3 overflow-hidden rounded-full bg-white/10">
                    <div class="h-full nx-gradient transition-all duration-300" :style="'width:' + pct + '%'"></div>
                </div>
                <div class="mb-4 flex items-center gap-4 text-sm">
                    <span style="color:#3ecf8e">✓ สำเร็จ <span x-text="ok"></span></span>
                    <span style="color:#ff6b81" x-show="failed > 0">✗ ล้มเหลว <span x-text="failed"></span></span>
                    <span class="ml-auto font-semibold text-cream/70" x-text="pct + '%'"></span>
                </div>
                {{-- Auto-retry banner: a dropped connection keeps retrying instead of stopping the import. --}}
                <div x-show="retrying" x-cloak class="mb-3 rounded-lg px-3 py-2 text-xs font-semibold" style="background:rgba(245,197,66,.14);color:#f5c542">
                    ⟳ เชื่อมต่อไม่ได้ชั่วคราว — กำลังลองเชื่อมต่อใหม่อัตโนมัติ (ครั้งที่ <span x-text="retryAttempt"></span>)… กด “หยุดกลางคัน” เพื่อยกเลิก
                </div>
                <div class="max-h-56 overflow-y-auto rounded-lg bg-black/25 p-2 text-xs">
                    <template x-for="(row, i) in log" :key="i">
                        <div class="flex items-center gap-2 border-b border-white/5 py-1">
                            <span x-text="row.ok ? '✓' : '✗'" :style="row.ok ? 'color:#3ecf8e' : 'color:#ff6b81'"></span>
                            <span class="truncate" x-text="row.title"></span>
                            <span class="ml-auto flex-shrink-0 text-cream/40" x-text="row.detail"></span>
                        </div>
                    </template>
                    <div x-show="log.length === 0" class="py-3 text-center text-cream/40">กำลังเริ่ม…</div>
                </div>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" x-show="running && !done" @click="cancelled = true"
                            class="rounded-lg bg-white/10 px-4 py-2 text-sm hover:bg-white/15">หยุดกลางคัน</button>
                    <button type="button" x-show="done" @click="window.location.reload()"
                            class="btn-brand px-5 py-2 text-sm">รีเฟรชรายการ</button>
                </div>
            </div>
        </div>
    </form>
@endif
@endsection
