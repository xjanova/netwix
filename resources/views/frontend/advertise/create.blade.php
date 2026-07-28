@extends('layouts.app')
@section('title', 'ลงโฆษณา — '.$p->name)
@section('meta_robots', 'noindex,nofollow')

@section('content')
<div class="pt-16 pb-14">
    <div class="mx-auto max-w-3xl px-4">
        <a href="{{ route('advertise.index') }}" class="text-[13px] text-cream/50 hover:text-cream">← กลับไปเลือกตำแหน่ง</a>
        <h1 class="mt-2 text-2xl font-extrabold">{{ $p->name }}</h1>
        <div class="mt-1 text-[13.5px] text-cream/55">
            ${{ rtrim(rtrim(number_format((float) $p->price_usdt_per_day, 2), '0'), '.') }}/วัน ·
            ขนาด {{ $p->width }}×{{ $p->height }} px ·
            คนเห็นราว {{ number_format($reach['per_day']) }}/วัน ·
            ไฟล์ไม่เกิน {{ $p->max_upload_kb }} KB
        </div>

        @if ($errors->any())
            <div class="mt-4 rounded-xl border border-brand/40 bg-brand/10 px-4 py-3 text-[13px] text-brand">
                @foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('advertise.store', $p) }}" enctype="multipart/form-data"
              class="mt-5 space-y-5"
              x-data="adCropper({ w: {{ $p->width }}, h: {{ $p->height }}, pricePerDay: {{ (float) $p->price_usdt_per_day }} })">
            @csrf

            {{-- 1. Creative --}}
            <div class="nx-card p-5">
                <div class="mb-3 text-[15px] font-bold">1. แบนเนอร์ของคุณ</div>

                <input type="file" name="image_file" accept="image/jpeg,image/png,image/gif,image/webp"
                       @change="load($event)" required
                       class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2.5 text-[13px] file:mr-3 file:rounded file:border-0 file:bg-white/10 file:px-3 file:py-1 file:text-cream">
                <div class="mt-1.5 text-[12px] text-cream/40">
                    รองรับ JPG, PNG, GIF — ระบบจะแปลงเป็นไฟล์ใหม่ทั้งหมดเพื่อความปลอดภัย
                    (GIF เคลื่อนไหวจะกลายเป็นภาพนิ่ง)
                </div>

                {{-- Crop stage: drag to reposition, slider to zoom. The box IS the banner's aspect. --}}
                <div x-show="src" x-cloak class="mt-4">
                    <div class="mb-1.5 text-[12.5px] text-cream/55">ลากรูปเพื่อจัดตำแหน่ง · เลื่อนแถบเพื่อซูม</div>
                    <div class="relative mx-auto w-full overflow-hidden rounded-lg border border-white/10 bg-black/40 select-none"
                         style="aspect-ratio: {{ $p->width }} / {{ $p->height }}"
                         x-ref="stage"
                         @pointerdown="startDrag($event)" @pointermove="drag($event)"
                         @pointerup="endDrag()" @pointerleave="endDrag()">
                        <img x-ref="img" :src="src" alt="" draggable="false"
                             class="pointer-events-none absolute origin-top-left will-change-transform"
                             :style="`left:0;top:0;transform:translate(${tx}px,${ty}px) scale(${zoom});`">
                    </div>
                    <input type="range" min="1" max="4" step="0.01" x-model.number="zoom" @input="clamp()"
                           class="mt-3 w-full accent-[#b026ff]">
                    <input type="hidden" name="crop" :value="cropJson">
                    <div class="mt-1 text-[11.5px] text-cream/35">ตัวอย่างด้านบนคือสิ่งที่จะแสดงจริง</div>
                </div>
            </div>

            {{-- 2. Link --}}
            <div class="nx-card p-5">
                <div class="mb-3 text-[15px] font-bold">2. ลิงก์ปลายทาง</div>
                <input type="text" name="title" value="{{ old('title') }}" placeholder="ชื่อแคมเปญ (ไว้ดูเอง ไม่แสดงต่อผู้ชม)" maxlength="120"
                       class="mb-3 w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2.5 text-[14px]">
                <input type="url" name="link_url" value="{{ old('link_url') }}" required placeholder="https://เว็บของคุณ"
                       class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2.5 text-[14px]">
                <div class="mt-1.5 text-[12px] text-cream/40">
                    ต้องเป็นลิงก์ตรงไปเว็บของคุณ — ระบบจะตรวจปลายทางอัตโนมัติ<b class="text-cream/60">ก่อน</b>ให้ชำระเงิน
                    ไม่รับลิงก์ย่อ ลิงก์เด้ง เว็บพนัน และเว็บผู้ใหญ่
                </div>
            </div>

            {{-- 3. Schedule --}}
            <div class="nx-card p-5">
                <div class="mb-3 text-[15px] font-bold">3. ช่วงเวลาแสดงผล</div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-[12.5px] text-cream/55">เริ่มแสดงวันที่</label>
                        <input type="date" name="starts_at" required x-model="startsAt"
                               min="{{ now()->toDateString() }}" value="{{ old('starts_at', now()->toDateString()) }}"
                               class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2.5 text-[14px]">
                    </div>
                    <div>
                        <label class="mb-1 block text-[12.5px] text-cream/55">จำนวนวัน (สูงสุด {{ $p->max_days }})</label>
                        <input type="number" name="days" required min="1" max="{{ $p->max_days }}"
                               x-model.number="days" value="{{ old('days', 7) }}"
                               class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2.5 text-[14px]">
                    </div>
                </div>

                <div class="mt-4 flex items-center justify-between rounded-lg border border-white/10 bg-white/[0.03] px-4 py-3">
                    <span class="text-[13.5px] text-cream/60">ยอดชำระรวม</span>
                    <span class="text-2xl font-extrabold">$<span x-text="total.toFixed(2)">0.00</span></span>
                </div>

                {{-- Availability for the chosen window --}}
                <div class="mt-4">
                    <div class="mb-1.5 text-[12.5px] text-cream/55">ปฏิทินคิว (สีเข้ม = เต็ม)</div>
                    <div class="flex flex-wrap gap-1">
                        @foreach ($calendar as $d)
                            <div title="{{ $d['date'] }} — จองแล้ว {{ $d['taken'] }}/{{ $d['cap'] }}"
                                 class="h-5 w-5 rounded text-center text-[9px] leading-5
                                    {{ $d['full'] ? 'bg-brand/70 text-white' : ($d['taken'] > 0 ? 'bg-gold/50 text-black/70' : 'bg-white/[0.07] text-cream/35') }}">
                                {{ (int) substr($d['date'], 8, 2) }}
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- 4. Rules + the acknowledgement that makes "no refund" fair --}}
            <div class="nx-card p-5">
                <div class="mb-3 text-[15px] font-bold">4. กติกาและเงื่อนไข</div>
                <div class="max-h-64 overflow-y-auto rounded-lg border border-white/5 bg-black/20 p-4">{!! $rules !!}</div>

                <label class="mt-4 flex cursor-pointer items-start gap-2.5 rounded-lg border border-brand/30 bg-brand/5 p-3.5 text-[13.5px]">
                    <input type="checkbox" name="accept_terms" value="1" required class="mt-0.5 h-4 w-4 shrink-0 rounded border-white/20 bg-white/5">
                    <span class="text-cream/80">
                        ข้าพเจ้าอ่านและยอมรับกติกาแล้ว และเข้าใจว่า
                        <b class="text-brand">ชำระเงินแล้วไม่มีการคืนเงินทุกกรณี</b>
                        โฆษณาต้องผ่านการตรวจอนุมัติก่อนขึ้นแสดง และหากไม่ผ่าน จะไม่คืนเงินแต่สามารถแก้ไขแล้วส่งใหม่ได้
                    </span>
                </label>
            </div>

            <button class="nx-gradient w-full rounded-xl py-4 text-[15px] font-bold text-white">
                ตรวจลิงก์แล้วไปหน้าชำระเงิน →
            </button>
            <div class="pb-2 text-center text-[12px] text-cream/35">ยังไม่ตัดเงินในขั้นนี้ — ระบบจะตรวจลิงก์ก่อน แล้วจึงแสดง QR ให้โอน</div>
        </form>
    </div>
</div>

@push('scripts')
<script>
// Small purpose-built cropper: drag to pan, slider to zoom, and it reports the crop in SOURCE pixels.
// The server re-crops from those numbers, so this is a convenience for the buyer, never the authority
// on the output (see App\Support\AdCreative).
function adCropper({ w, h, pricePerDay }) {
    return {
        src: null, zoom: 1, tx: 0, ty: 0, natural: { w: 0, h: 0 },
        dragging: false, lastX: 0, lastY: 0,
        days: {{ (int) old('days', 7) }}, startsAt: '{{ old('starts_at', now()->toDateString()) }}',
        get total() { return Math.max(0, (this.days || 0)) * pricePerDay; },
        get cropJson() {
            if (!this.natural.w) return '';
            const stage = this.$refs.stage; if (!stage) return '';
            const rect = stage.getBoundingClientRect();
            // Displayed image size = natural * baseFit * zoom; invert that to get source pixels.
            const base = rect.width / this.natural.w;
            const scale = base * this.zoom;
            return JSON.stringify({
                x: Math.max(0, -this.tx / scale),
                y: Math.max(0, -this.ty / scale),
                w: Math.min(this.natural.w, rect.width / scale),
                h: Math.min(this.natural.h, rect.height / scale),
            });
        },
        load(e) {
            const f = e.target.files && e.target.files[0];
            if (!f) { this.src = null; return; }
            const url = URL.createObjectURL(f);
            const img = new Image();
            img.onload = () => {
                this.natural = { w: img.naturalWidth, h: img.naturalHeight };
                this.src = url;
                this.$nextTick(() => { this.zoom = 1; this.tx = 0; this.ty = 0; this.fitWidth(); this.clamp(); });
            };
            img.src = url;
        },
        fitWidth() {
            const stage = this.$refs.stage, img = this.$refs.img;
            if (!stage || !img) return;
            img.style.width = stage.getBoundingClientRect().width + 'px';
            img.style.height = 'auto';
        },
        startDrag(e) { this.dragging = true; this.lastX = e.clientX; this.lastY = e.clientY; e.target.setPointerCapture?.(e.pointerId); },
        endDrag() { this.dragging = false; },
        drag(e) {
            if (!this.dragging) return;
            this.tx += e.clientX - this.lastX; this.ty += e.clientY - this.lastY;
            this.lastX = e.clientX; this.lastY = e.clientY;
            this.clamp();
        },
        // Never let the pan expose empty canvas — the banner must always be fully covered.
        clamp() {
            const stage = this.$refs.stage; if (!stage || !this.natural.w) return;
            const rect = stage.getBoundingClientRect();
            const dispW = rect.width * this.zoom;
            const dispH = (this.natural.h / this.natural.w) * rect.width * this.zoom;
            this.tx = Math.min(0, Math.max(rect.width - dispW, this.tx));
            this.ty = Math.min(0, Math.max(rect.height - dispH, this.ty));
        },
    };
}
</script>
@endpush
@endsection
