@extends('layouts.admin')
@section('page-title', 'โฆษณาสำรอง (แบนเนอร์เราเอง)')
@section('page-subtitle', 'แบนเนอร์ที่เราอัพโหลดเอง ใช้เติมช่องโฆษณาที่เครือข่ายไม่มีโฆษณาให้ — วน / สุ่ม / ตามน้ำหนักเปอร์เซ็นต์')

@section('content')
@if (session('status'))
    <div class="mb-5 rounded-xl border border-success/30 bg-success/10 px-4 py-3 text-[13px] text-success">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="mb-5 rounded-xl border border-brand/40 bg-brand/10 px-4 py-3 text-[13px] text-brand">
        @foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach
    </div>
@endif

@unless ($adsOn)
    <div class="mb-5 rounded-xl border border-gold/30 bg-gold/10 px-4 py-3 text-[13px] text-gold/90">
        ตอนนี้ระบบโฆษณาปิดอยู่ทั้งหมด — แบนเนอร์เหล่านี้จะยังไม่แสดง
        เปิดได้ที่ <a href="{{ route('admin.google-ads.index') }}" class="underline">โฆษณา Google</a> (ติ๊ก "เปิดโฆษณาบนเว็บ")
    </div>
@endunless

{{-- Rotation settings --}}
<form method="POST" action="{{ route('admin.house-banners.settings') }}" class="nx-card mb-5 p-5">
    @csrf @method('PUT')
    <div class="mb-3 text-[15px] font-bold">การหมุนแบนเนอร์</div>
    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label class="mb-1 block text-[13px] text-cream/60">รูปแบบการเลือก</label>
            <select name="house_ads_mode" class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2.5 text-[14px]">
                <option value="rotate" @selected($mode === 'rotate')>วนทีละอัน (เท่ากันทุกตัว เรียงตามลำดับ)</option>
                <option value="random" @selected($mode === 'random')>สุ่ม (โอกาสเท่ากัน แต่ลำดับไม่แน่นอน)</option>
                <option value="weighted" @selected($mode === 'weighted')>ตามน้ำหนัก (ตัวไหนน้ำหนักมาก ยิ่งออกบ่อย)</option>
            </select>
        </div>
        <div>
            <label class="mb-1 block text-[13px] text-cream/60">สัดส่วนที่ให้แบนเนอร์เราแทรก (%)</label>
            <input type="number" name="house_ads_fill" min="0" max="100" value="{{ old('house_ads_fill', $fill) }}"
                   class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2.5 text-[14px]">
            <div class="mt-1 text-[12px] text-cream/40">
                0 = ใช้เฉพาะตอนที่ช่องนั้นไม่ได้ตั้งรหัส Google · 100 = ใช้แบนเนอร์เราเสมอ
                <br>ถ้าช่องไหนไม่มีรหัส Google ระบบจะใช้แบนเนอร์เราให้อยู่แล้ว ไม่ว่าตั้งกี่เปอร์เซ็นต์
            </div>
        </div>
    </div>
    <button class="mt-4 rounded-lg bg-white/10 px-4 py-2 text-[13px] font-semibold hover:bg-white/15">บันทึกการหมุน</button>
</form>

{{-- Add new --}}
<details class="nx-card mb-5 p-5">
    <summary class="cursor-pointer text-[15px] font-bold">+ เพิ่มแบนเนอร์ใหม่</summary>
    <form method="POST" action="{{ route('admin.house-banners.store') }}" enctype="multipart/form-data" class="mt-4 space-y-3">
        @csrf
        @include('admin.house-banners._fields', ['banner' => null, 'slots' => $slots])
        <button class="rounded-lg bg-brand px-5 py-2.5 text-[14px] font-semibold text-white hover:bg-brand/90">เพิ่มแบนเนอร์</button>
    </form>
</details>

@if ($banners->isEmpty())
    <div class="nx-card p-10 text-center text-cream/55">
        <div class="text-4xl">🖼️</div>
        <div class="mt-3">ยังไม่มีแบนเนอร์ของเราเอง</div>
    </div>
@else
    <div class="space-y-3">
        @foreach ($banners as $b)
            <div class="nx-card p-4">
                <div class="flex flex-wrap items-start gap-4">
                    @if ($b->image_src)
                        <img src="{{ $b->image_src }}" alt="" class="h-16 w-40 shrink-0 rounded-lg object-cover ring-1 ring-white/10" style="background:#1a1420">
                    @endif
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold">{{ $b->name ?: 'แบนเนอร์ #'.$b->id }}</span>
                            <span class="rounded-full bg-white/5 px-2 py-0.5 text-[11px] text-cream/50">ช่อง: {{ $b->slot }}</span>
                            @if ($mode === 'weighted' && isset($share[$b->id]))
                                <span class="rounded-full bg-brand/15 px-2 py-0.5 text-[11px] text-brand">
                                    ออกประมาณ {{ $share[$b->id] }}% (น้ำหนัก {{ $b->weight }})
                                </span>
                            @endif
                            <span class="rounded-full px-2 py-0.5 text-[11px] {{ $b->is_active ? 'bg-success/15 text-success' : 'bg-white/5 text-cream/45' }}">
                                {{ $b->is_active ? 'เปิดอยู่' : 'ปิดอยู่' }}
                            </span>
                        </div>
                        <div class="mt-1 text-[12px] text-cream/45">
                            คลิก {{ number_format($b->clicks) }} ครั้ง
                            @if ($b->starts_at || $b->ends_at)
                                · แสดง {{ $b->starts_at?->format('d/m/y') ?: '—' }} ถึง {{ $b->ends_at?->format('d/m/y') ?: '—' }}
                            @endif
                        </div>
                    </div>
                    <div class="flex shrink-0 gap-2">
                        <form method="POST" action="{{ route('admin.house-banners.toggle', $b) }}">@csrf
                            <button class="rounded-lg bg-white/5 px-3 py-2 text-[13px] hover:bg-white/10">{{ $b->is_active ? 'ปิด' : 'เปิด' }}</button>
                        </form>
                        <form method="POST" action="{{ route('admin.house-banners.destroy', $b) }}"
                              onsubmit="return confirm('ลบแบนเนอร์นี้ถาวร?')">@csrf @method('DELETE')
                            <button class="rounded-lg bg-brand/15 px-3 py-2 text-[13px] text-brand hover:bg-brand/25">ลบ</button>
                        </form>
                    </div>
                </div>

                <details class="mt-3">
                    <summary class="cursor-pointer text-[13px] text-cream/55">แก้ไข</summary>
                    <form method="POST" action="{{ route('admin.house-banners.update', $b) }}" enctype="multipart/form-data" class="mt-3 space-y-3">
                        @csrf @method('PUT')
                        @include('admin.house-banners._fields', ['banner' => $b, 'slots' => $slots])
                        <button class="rounded-lg bg-white/10 px-4 py-2 text-[13px] font-semibold hover:bg-white/15">บันทึก</button>
                    </form>
                </details>
            </div>
        @endforeach
    </div>
@endif
@endsection
