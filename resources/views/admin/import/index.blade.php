@extends('layouts.admin')
@section('page-title', 'นำเข้าหนัง')
@section('page-subtitle', 'ดึงคอนเทนต์จากแหล่งภายนอกเข้าคลัง NetWix')
@section('action')
    <form method="POST" action="{{ route('admin.import.sync') }}"
          onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='กำลังซิงค์…'">
        @csrf
        <input type="hidden" name="source" value="{{ $sourceId }}">
        <input type="hidden" name="max_pages" value="30">
        <button class="nx-gradient flex items-center gap-1.5 rounded-lg px-4 py-2.5 text-sm font-semibold" style="box-shadow:0 8px 22px rgba(176,38,255,0.32)">
            ⟳ ซิงค์แคตตาล็อก
        </button>
    </form>
@endsection

@section('content')
<script>
window.importer = () => ({
    sel: [],
    running: false, done: false, cancelled: false,
    total: 0, processed: 0, ok: 0, failed: 0, log: [],
    chunkSize: 4,
    get pct() { return this.total ? Math.round(this.processed / this.total * 100) : 0; },
    titleFor(id) {
        const el = document.querySelector('.imp-cb[value="' + id + '"]');
        return el ? el.dataset.title : ('#' + id);
    },
    async run(form) {
        if (this.running || this.sel.length === 0) return;
        this.running = true; this.done = false; this.cancelled = false;
        this.total = this.sel.length; this.processed = 0; this.ok = 0; this.failed = 0; this.log = [];

        const fd = new FormData(form);
        const token = fd.get('_token');
        const genres = [...form.querySelectorAll('input[name="genres[]"]:checked')].map(c => c.value);
        const ids = [...this.sel];

        for (let i = 0; i < ids.length && !this.cancelled; i += this.chunkSize) {
            const batch = ids.slice(i, i + this.chunkSize);
            const body = new URLSearchParams();
            body.set('_token', token);
            body.set('source', fd.get('source'));
            body.set('type', fd.get('type') || '');
            if (fd.get('publish')) body.set('publish', '1');
            if (fd.get('auto_type')) body.set('auto_type', '1');
            if (fd.get('auto_genres')) body.set('auto_genres', '1');
            if (fd.get('primary_genre')) body.set('primary_genre', fd.get('primary_genre'));
            genres.forEach(g => body.append('genres[]', g));
            batch.forEach(id => body.append('ids[]', id));

            try {
                const r = await fetch('{{ route('admin.import.batch') }}', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    body,
                });
                if (!r.ok) throw new Error('HTTP ' + r.status);
                const j = await r.json();
                (j.results || []).forEach(res => {
                    this.processed++;
                    res.ok ? this.ok++ : this.failed++;
                    this.log.unshift({
                        title: res.title, ok: res.ok,
                        detail: res.ok ? ((res.type || '') + ' · ' + (res.episodes || 0) + ' ตอน') : (res.error || 'ผิดพลาด'),
                    });
                });
            } catch (e) {
                batch.forEach(id => {
                    this.processed++; this.failed++;
                    this.log.unshift({ title: this.titleFor(id), ok: false, detail: 'เชื่อมต่อผิดพลาด' });
                });
            }
            this.log = this.log.slice(0, 40);
        }
        this.done = true;
    },
});
</script>
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

            <button type="submit" x-bind:disabled="sel.length === 0"
                    class="btn-brand ml-auto px-6 py-2.5 text-sm disabled:opacity-40">นำเข้าที่เลือก →</button>
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
                    <h3 class="text-lg font-bold" x-text="done ? (failed ? 'นำเข้าเสร็จ (มีบางเรื่องล้มเหลว)' : 'นำเข้าเสร็จสิ้น ✓') : 'กำลังนำเข้า…'"></h3>
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
                            class="rounded-lg bg-white/10 px-4 py-2 text-sm hover:bg-white/15">หยุด</button>
                    <button type="button" x-show="done" @click="window.location.reload()"
                            class="btn-brand px-5 py-2 text-sm">รีเฟรชรายการ</button>
                </div>
            </div>
        </div>
    </form>
@endif
@endsection
