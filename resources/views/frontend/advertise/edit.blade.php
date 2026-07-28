@extends('layouts.app')
@section('title', 'แก้ไขโฆษณา — '.$booking->reference)
@section('meta_robots', 'noindex,nofollow')

@section('content')
<div class="pt-16 pb-14">
    <div class="mx-auto max-w-3xl px-4">
        <a href="{{ route('advertise.mine') }}" class="text-[13px] text-cream/50 hover:text-cream">← กลับไปโฆษณาของฉัน</a>
        <h1 class="mt-2 text-2xl font-extrabold">แก้ไขแล้วส่งตรวจใหม่</h1>
        <div class="mt-1 text-[13.5px] text-cream/55">
            {{ $booking->reference }} · {{ $p->name }} · {{ $booking->days }} วัน
            · จ่ายแล้ว ${{ number_format((float) $booking->price_usdt, 2) }}
        </div>

        <div class="mt-4 rounded-xl border border-brand/40 bg-brand/10 px-4 py-3 text-[13px]">
            <div class="font-bold text-brand">เหตุผลที่ไม่ผ่าน</div>
            <div class="mt-1 text-cream/80">{{ $booking->review_note }}</div>
        </div>

        <div class="mt-3 rounded-xl border border-success/30 bg-success/10 px-4 py-3 text-[13px] text-success">
            แก้ไขแล้วส่งตรวจใหม่ได้ <b>โดยไม่ต้องจ่ายเพิ่ม</b> — ตำแหน่งและจำนวนวันคงเดิม เปลี่ยนได้เฉพาะรูป ลิงก์ และวันเริ่ม
        </div>

        @if ($errors->any())
            <div class="mt-4 rounded-xl border border-brand/40 bg-brand/10 px-4 py-3 text-[13px] text-brand">
                @foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('advertise.resubmit', $booking) }}" enctype="multipart/form-data"
              class="mt-5 space-y-5"
              x-data="adCropper({ w: {{ $p->width }}, h: {{ $p->height }}, pricePerDay: 0 })">
            @csrf @method('PUT')

            <div class="nx-card p-5">
                <div class="mb-3 text-[15px] font-bold">แบนเนอร์</div>
                <div class="mb-3">
                    <div class="mb-1.5 text-[12.5px] text-cream/55">รูปปัจจุบัน</div>
                    <img src="{{ $booking->image_src }}" alt="" class="w-full max-w-md rounded-lg ring-1 ring-white/10">
                </div>

                <label class="mb-1 block text-[12.5px] text-cream/55">เปลี่ยนรูปใหม่ (เว้นว่าง = ใช้รูปเดิม)</label>
                <input type="file" name="image_file" accept="image/jpeg,image/png,image/gif,image/webp" @change="load($event)"
                       class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2.5 text-[13px] file:mr-3 file:rounded file:border-0 file:bg-white/10 file:px-3 file:py-1 file:text-cream">

                <div x-show="src" x-cloak class="mt-4">
                    <div class="mb-1.5 text-[12.5px] text-cream/55">ลากเพื่อจัดตำแหน่ง · เลื่อนแถบเพื่อซูม</div>
                    <div class="relative mx-auto w-full overflow-hidden rounded-lg border border-white/10 bg-black/40 select-none"
                         style="aspect-ratio: {{ $p->width }} / {{ $p->height }}"
                         x-ref="stage"
                         @pointerdown="startDrag($event)" @pointermove="drag($event)"
                         @pointerup="endDrag()" @pointerleave="endDrag()">
                        <img x-ref="img" :src="src" alt="" draggable="false"
                             class="pointer-events-none absolute origin-top-left will-change-transform"
                             :style="`left:0;top:0;transform:translate(${tx}px,${ty}px) scale(${zoom});`">
                    </div>
                    <input type="range" min="1" max="4" step="0.01" x-model.number="zoom" @input="clamp()" class="mt-3 w-full accent-[#b026ff]">
                    <input type="hidden" name="crop" :value="cropJson">
                </div>
            </div>

            <div class="nx-card p-5">
                <div class="mb-3 text-[15px] font-bold">ลิงก์และช่วงเวลา</div>
                <input type="text" name="title" value="{{ old('title', $booking->title) }}" maxlength="120"
                       placeholder="ชื่อแคมเปญ (ไว้ดูเอง)"
                       class="mb-3 w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2.5 text-[14px]">
                <input type="url" name="link_url" required value="{{ old('link_url', $booking->link_url) }}"
                       class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2.5 text-[14px]">
                <div class="mt-1.5 text-[12px] text-cream/40">ระบบจะตรวจลิงก์ปลายทางอีกครั้งเมื่อส่ง</div>

                <label class="mb-1 mt-4 block text-[12.5px] text-cream/55">วันเริ่มใหม่ (จำนวนวันคงเดิม {{ $booking->days }} วัน)</label>
                <input type="date" name="starts_at" required min="{{ now()->toDateString() }}"
                       value="{{ old('starts_at', $booking->starts_at?->isFuture() ? $booking->starts_at->toDateString() : now()->toDateString()) }}"
                       class="w-full max-w-xs rounded-lg border border-white/10 bg-surface-2 px-3 py-2.5 text-[14px]">

                <div class="mt-4">
                    <div class="mb-1.5 text-[12.5px] text-cream/55">ปฏิทินคิว</div>
                    <div class="flex flex-wrap gap-1">
                        @foreach ($calendar as $d)
                            <div title="{{ $d['date'] }} — {{ $d['taken'] }}/{{ $d['cap'] }}"
                                 class="h-5 w-5 rounded text-center text-[9px] leading-5
                                    {{ $d['full'] ? 'bg-brand/70 text-white' : ($d['taken'] > 0 ? 'bg-gold/50 text-black/70' : 'bg-white/[0.07] text-cream/35') }}">
                                {{ (int) substr($d['date'], 8, 2) }}
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <button class="nx-gradient w-full rounded-xl py-4 text-[15px] font-bold text-white">ส่งตรวจใหม่ (ไม่มีค่าใช้จ่ายเพิ่ม)</button>
        </form>
    </div>
</div>

@push('scripts')
<script>
// Same cropper as the create page — see frontend/advertise/create.blade.php for the notes.
function adCropper({ w, h }) {
    return {
        src: null, zoom: 1, tx: 0, ty: 0, natural: { w: 0, h: 0 }, dragging: false, lastX: 0, lastY: 0,
        get cropJson() {
            if (!this.natural.w) return '';
            const stage = this.$refs.stage; if (!stage) return '';
            const rect = stage.getBoundingClientRect();
            const scale = (rect.width / this.natural.w) * this.zoom;
            return JSON.stringify({
                x: Math.max(0, -this.tx / scale), y: Math.max(0, -this.ty / scale),
                w: Math.min(this.natural.w, rect.width / scale), h: Math.min(this.natural.h, rect.height / scale),
            });
        },
        load(e) {
            const f = e.target.files && e.target.files[0];
            if (!f) { this.src = null; return; }
            const url = URL.createObjectURL(f), img = new Image();
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
            img.style.width = stage.getBoundingClientRect().width + 'px'; img.style.height = 'auto';
        },
        startDrag(e) { this.dragging = true; this.lastX = e.clientX; this.lastY = e.clientY; e.target.setPointerCapture?.(e.pointerId); },
        endDrag() { this.dragging = false; },
        drag(e) {
            if (!this.dragging) return;
            this.tx += e.clientX - this.lastX; this.ty += e.clientY - this.lastY;
            this.lastX = e.clientX; this.lastY = e.clientY; this.clamp();
        },
        clamp() {
            const stage = this.$refs.stage; if (!stage || !this.natural.w) return;
            const rect = stage.getBoundingClientRect();
            const dispW = rect.width * this.zoom, dispH = (this.natural.h / this.natural.w) * rect.width * this.zoom;
            this.tx = Math.min(0, Math.max(rect.width - dispW, this.tx));
            this.ty = Math.min(0, Math.max(rect.height - dispH, this.ty));
        },
    };
}
</script>
@endpush
@endsection
