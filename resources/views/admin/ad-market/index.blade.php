@extends('layouts.admin')
@section('page-title', 'ขายโฆษณาให้ลูกค้า')
@section('page-subtitle', 'กำหนดตำแหน่งที่ขาย ราคา ลิมิต และกติกา — ลูกค้าลงโฆษณาเองได้ที่ /advertise')

@section('content')
@if (session('status'))
    <div class="mb-5 rounded-xl border border-success/30 bg-success/10 px-4 py-3 text-[13px] text-success">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="mb-5 rounded-xl border border-brand/40 bg-brand/10 px-4 py-3 text-[13px] text-brand">
        @foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach
    </div>
@endif

<div class="mb-5 grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-white/5 sm:grid-cols-4" style="background:rgba(255,255,255,0.06)">
    <div class="bg-panel-2 p-4">
        <div class="text-[12px] text-cream/50">รออนุมัติ</div>
        <div class="mt-1 text-xl font-bold {{ $pendingCount > 0 ? 'text-gold' : '' }}">{{ number_format($pendingCount) }}</div>
    </div>
    <div class="bg-panel-2 p-4">
        <div class="text-[12px] text-cream/50">กำลังแสดงอยู่</div>
        <div class="mt-1 text-xl font-bold">{{ number_format($stats['active']) }}</div>
    </div>
    <div class="bg-panel-2 p-4">
        <div class="text-[12px] text-cream/50">รายได้รวม (USDT)</div>
        <div class="mt-1 text-xl font-bold">${{ number_format($stats['revenue'], 2) }}</div>
    </div>
    <div class="bg-panel-2 p-4">
        <div class="text-[12px] text-cream/50">ตำแหน่งที่เปิดขาย</div>
        <div class="mt-1 text-xl font-bold">{{ $placements->where('is_active', true)->count() }}</div>
    </div>
</div>

<div class="mb-5 flex flex-wrap gap-2">
    <a href="{{ route('admin.ad-market.review') }}" class="rounded-lg bg-white/10 px-4 py-2.5 text-[13.5px] font-semibold hover:bg-white/15">
        ตรวจอนุมัติโฆษณา @if ($pendingCount > 0)<span class="ml-1 rounded-full bg-[#e5484d] px-2 py-0.5 text-[11px] text-white">{{ $pendingCount }}</span>@endif
    </a>
    <a href="{{ route('admin.ad-market.calendar') }}" class="rounded-lg bg-white/10 px-4 py-2.5 text-[13.5px] font-semibold hover:bg-white/15">ปฏิทินการจอง</a>
    <a href="{{ route('advertise.index') }}" target="_blank" class="rounded-lg bg-white/5 px-4 py-2.5 text-[13.5px] text-cream/70 hover:bg-white/10">ดูหน้าลูกค้า ↗</a>
</div>

{{-- Global settings --}}
<form method="POST" action="{{ route('admin.ad-market.settings') }}" class="nx-card mb-5 space-y-4 p-5">
    @csrf @method('PUT')
    <div class="text-[15px] font-bold">ตั้งค่ารวม</div>

    <div>
        <label class="mb-1 block text-[13px] text-cream/60">สัดส่วนที่ให้โฆษณาลูกค้าที่จ่ายเงินได้ช่อง (%)</label>
        <input type="number" name="ad_paid_share" min="0" max="100" value="{{ old('ad_paid_share', $paidShare) }}"
               class="w-full max-w-xs rounded-lg border border-white/10 bg-surface-2 px-3 py-2.5 text-[14px]">
        <div class="mt-1 text-[12px] text-cream/40">
            ที่เหลือจะเป็นของ Google AdSense / แบนเนอร์เราเอง · ถ้าไม่มีลูกค้าจองช่วงนั้น ระบบใช้ AdSense ให้อัตโนมัติ
        </div>
    </div>

    <div class="grid gap-3 sm:grid-cols-2">
        <div>
            <label class="mb-1 block text-[13px] text-cream/60">คำต้องห้ามเพิ่มเติม (คั่นด้วย , หรือขึ้นบรรทัดใหม่)</label>
            <textarea name="ad_block_keywords" rows="3" placeholder="เช่น ชื่อเว็บพนันใหม่ๆ"
                      class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-[13px]">{{ old('ad_block_keywords', \App\Models\Setting::get('ad_block_keywords', '')) }}</textarea>
        </div>
        <div>
            <label class="mb-1 block text-[13px] text-cream/60">โดเมนต้องห้ามเพิ่มเติม</label>
            <textarea name="ad_block_domains" rows="3" placeholder="เช่น example-shortener.com"
                      class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-[13px]">{{ old('ad_block_domains', \App\Models\Setting::get('ad_block_domains', '')) }}</textarea>
        </div>
    </div>
    <div class="text-[12px] text-cream/40">
        ระบบมีรายการพื้นฐานอยู่แล้ว (เว็บพนัน/ผู้ใหญ่ ไทย+อังกฤษ และลิงก์ย่อยอดนิยม) ช่องนี้คือ<b class="text-cream/60">เพิ่มเติม</b>จากนั้น
    </div>

    <div>
        <label class="mb-1 block text-[13px] text-cream/60">กติกาที่แสดงให้ลูกค้า (HTML — เว้นว่าง = ใช้ค่าเริ่มต้นของระบบ)</label>
        <textarea name="ad_rules_html" rows="4"
                  class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 font-mono text-[12px]">{{ old('ad_rules_html', \App\Models\Setting::get('ad_rules_html', '')) }}</textarea>
    </div>

    <button class="rounded-lg bg-brand px-5 py-2.5 text-[14px] font-semibold text-white hover:bg-brand/90">บันทึกตั้งค่า</button>
</form>

{{-- Add placement --}}
<details class="nx-card mb-5 p-5">
    <summary class="cursor-pointer text-[15px] font-bold">+ เพิ่มตำแหน่งขายใหม่</summary>
    <form method="POST" action="{{ route('admin.ad-market.placements.store') }}" class="mt-4 space-y-3">
        @csrf
        @include('admin.ad-market._placement-fields', ['p' => null, 'slots' => $slots])
        <button class="rounded-lg bg-brand px-5 py-2.5 text-[14px] font-semibold text-white hover:bg-brand/90">เพิ่มตำแหน่ง</button>
    </form>
</details>

@if ($placements->isEmpty())
    <div class="nx-card p-10 text-center text-cream/55">
        <div class="text-4xl">🏷️</div>
        <div class="mt-3">ยังไม่ได้เปิดขายตำแหน่งไหนเลย</div>
    </div>
@else
    <div class="space-y-3">
        @foreach ($placements as $p)
            <div class="nx-card p-4">
                <div class="flex flex-wrap items-center gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold">{{ $p->name }}</span>
                            <span class="rounded-full bg-white/5 px-2 py-0.5 text-[11px] text-cream/50">ช่อง {{ $p->slot }}</span>
                            <span class="rounded-full px-2 py-0.5 text-[11px] {{ $p->is_active ? 'bg-success/15 text-success' : 'bg-white/5 text-cream/45' }}">
                                {{ $p->is_active ? 'เปิดขาย' : 'ปิดขาย' }}
                            </span>
                        </div>
                        <div class="mt-1 text-[12.5px] text-cream/45">
                            ${{ rtrim(rtrim(number_format((float) $p->price_usdt_per_day, 2), '0'), '.') }}/วัน ·
                            {{ $p->width }}×{{ $p->height }}px ·
                            สูงสุด {{ $p->max_concurrent }} เจ้าพร้อมกัน ·
                            จองได้ {{ $p->max_days }} วัน ·
                            ไฟล์ ≤ {{ $p->max_upload_kb }} KB ·
                            คนเห็นราว {{ number_format($reach[$p->id]['per_day'] ?? 0) }}/วัน
                        </div>
                    </div>
                    <form method="POST" action="{{ route('admin.ad-market.placements.destroy', $p) }}"
                          onsubmit="return confirm('ลบตำแหน่งนี้?')" class="shrink-0">@csrf @method('DELETE')
                        <button class="rounded-lg bg-brand/15 px-3 py-2 text-[13px] text-brand hover:bg-brand/25">ลบ</button>
                    </form>
                </div>

                <details class="mt-3">
                    <summary class="cursor-pointer text-[13px] text-cream/55">แก้ไข</summary>
                    <form method="POST" action="{{ route('admin.ad-market.placements.update', $p) }}" class="mt-3 space-y-3">
                        @csrf @method('PUT')
                        @include('admin.ad-market._placement-fields', ['p' => $p, 'slots' => $slots])
                        <button class="rounded-lg bg-white/10 px-4 py-2 text-[13px] font-semibold hover:bg-white/15">บันทึก</button>
                    </form>
                </details>
            </div>
        @endforeach
    </div>
@endif
@endsection
