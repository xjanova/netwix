@extends('layouts.admin')
@section('page-title', 'สร้างปกตอน')
@section('page-subtitle', 'สร้างภาพปกของแต่ละตอนด้วย ffmpeg (WebP) — เลือกทั้งเว็บ ตามหมวด หรือตามชื่อเรื่อง')

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

    <div class="space-y-3 text-sm">
        <label class="flex items-center gap-2.5">
            <input type="radio" value="all" x-model="scope" class="h-4 w-4 accent-brand"> ทั้งเว็บ
        </label>
        <label class="flex flex-wrap items-center gap-2.5">
            <input type="radio" value="genre" x-model="scope" class="h-4 w-4 accent-brand"> ตามหมวด
            <select x-model="genreId" x-show="scope==='genre'"
                    class="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-sm text-cream">
                @foreach ($genres as $g)
                    <option value="{{ $g->id }}">{{ $g->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="flex flex-wrap items-center gap-2.5">
            <input type="radio" value="title" x-model="scope" class="h-4 w-4 accent-brand"> ตามชื่อเรื่อง
            <input x-model="q" x-show="scope==='title'" placeholder="พิมพ์ชื่อเรื่อง…"
                   class="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-sm text-cream placeholder:text-cream/30">
        </label>
        <label class="flex items-center gap-2.5 pt-1">
            <input type="checkbox" x-model="force" class="h-4 w-4 accent-brand">
            ทำใหม่ทับของเดิม <span class="text-cream/40">(ไม่งั้นสร้างเฉพาะตอนที่ยังไม่มีปก)</span>
        </label>
    </div>

    <button @click="start()" :disabled="running"
            class="nx-gradient mt-5 rounded-lg px-5 py-2.5 text-sm font-semibold disabled:opacity-50"
            style="box-shadow:0 8px 22px rgba(176,38,255,0.32)">
        <span x-show="!running">เริ่มสร้างปก</span>
        <span x-show="running" x-cloak>กำลังสร้าง…</span>
    </button>

    <div x-show="phase!=='idle'" x-cloak class="mt-6">
        <div class="h-3 w-full overflow-hidden rounded-full bg-white/10">
            <div class="nx-gradient h-full transition-all duration-300" :style="`width:${pct}%`"></div>
        </div>
        <div class="mt-2.5 text-[13px] text-cream/60">
            <span x-text="phase==='scanning' ? 'กำลังค้นหาตอน…' : (phase==='done' ? '✅ เสร็จแล้ว' : 'กำลังสร้าง…')"></span>
            · <span x-text="done+failed"></span>/<span x-text="total"></span>
            (<span x-text="pct"></span>%)
            · สำเร็จ <span class="text-success" x-text="done"></span>
            · พลาด <span class="text-[#ff6b81]" x-text="failed"></span>
        </div>
        <p x-show="phase==='done'" class="mt-1 text-[12px] text-cream/40">
            ปกที่พลาดมักเป็นตอนที่แหล่งวิดีโอหมดอายุ/ดึงไม่ได้ชั่วคราว — กดสร้างซ้ำภายหลังได้
        </p>
    </div>
</div>

<script>
function thumbGen() {
    return {
        scope: 'all', genreId: '{{ $genres->first()->id ?? '' }}', q: '', force: false,
        running: false, phase: 'idle', total: 0, done: 0, failed: 0,
        get pct() { return this.total ? Math.round((this.done + this.failed) / this.total * 100) : 0; },
        async post(url, body) {
            const r = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                body: JSON.stringify(body),
            });
            return r.json();
        },
        async start() {
            if (this.running) return;
            this.running = true; this.phase = 'scanning'; this.done = 0; this.failed = 0; this.total = 0;
            let scan;
            try {
                scan = await this.post('{{ route('admin.thumbs.scan') }}',
                    { scope: this.scope, mode: this.force ? 'all' : 'missing', genre_id: this.genreId, q: this.q });
            } catch (e) { this.phase = 'done'; this.running = false; return; }
            this.total = scan.total || 0;
            const ids = scan.ids || [];
            this.phase = 'running';
            for (let i = 0; i < ids.length; i += 3) {
                const batch = ids.slice(i, i + 3);
                try {
                    const res = await this.post('{{ route('admin.thumbs.run') }}', { ids: batch, force: this.force });
                    this.done += (res.done || 0); this.failed += (res.failed || 0);
                } catch (e) { this.failed += batch.length; }
            }
            this.phase = 'done'; this.running = false;
        },
    };
}
</script>
@endsection
