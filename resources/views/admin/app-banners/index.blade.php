@extends('layouts.admin')
@section('page-title', 'แบนเนอร์ในแอป')
@section('page-subtitle', 'แบนเนอร์โปรโมชันบนสุดของหน้าแรกแอปมือถือ — เรียงตามลำดับ (มาก→น้อย) เลื่อนดูได้หลายใบ')
@section('action')<span></span>@endsection

@section('content')
<div class="grid gap-6 lg:grid-cols-[2fr_1fr]">
    <div class="nx-card p-4">
        <div class="flex flex-col gap-3">
            @forelse ($banners as $b)
                <div class="rounded-lg bg-white/[0.03] p-3">
                    <div class="flex flex-wrap items-start gap-3">
                        @if ($b->image_src)
                            <img src="{{ $b->image_src }}" alt="" class="h-20 w-40 shrink-0 rounded-md object-cover ring-1 ring-white/10">
                        @else
                            <div class="flex h-20 w-40 shrink-0 items-center justify-center rounded-md bg-white/5 text-xs text-cream/40">ไม่มีรูป</div>
                        @endif
                        <div class="min-w-0 flex-1">
                            <form method="POST" action="{{ route('admin.app-banners.update', $b) }}" enctype="multipart/form-data" id="bn-{{ $b->id }}"></form>
                            <input form="bn-{{ $b->id }}" type="hidden" name="_token" value="{{ csrf_token() }}">
                            <input form="bn-{{ $b->id }}" type="hidden" name="_method" value="PUT">
                            <div class="grid gap-2 md:grid-cols-[1fr_90px]">
                                <input form="bn-{{ $b->id }}" name="title" value="{{ $b->title }}" placeholder="ชื่อ/แคมเปญ" class="rounded-md border border-white/10 bg-surface-2 px-2.5 py-1.5 text-sm outline-none focus:border-brand">
                                <input form="bn-{{ $b->id }}" name="sort" type="number" value="{{ $b->sort }}" title="ลำดับ" class="rounded-md border border-white/10 bg-surface-2 px-2.5 py-1.5 text-sm outline-none focus:border-brand">
                            </div>
                            <div class="mt-2 grid gap-2 md:grid-cols-3">
                                <input form="bn-{{ $b->id }}" name="link_url" value="{{ $b->link_url }}" placeholder="ลิงก์เมื่อกด (ไม่บังคับ)" class="rounded-md border border-white/10 bg-surface-2 px-2.5 py-1.5 text-xs outline-none focus:border-brand">
                                <input form="bn-{{ $b->id }}" name="image_url" value="{{ $b->image_url }}" placeholder="ลิงก์รูป (แทนการอัพโหลด)" class="rounded-md border border-white/10 bg-surface-2 px-2.5 py-1.5 text-xs outline-none focus:border-brand">
                                <input form="bn-{{ $b->id }}" name="image_file" type="file" accept="image/*" class="text-xs text-cream/60 file:mr-2 file:rounded-md file:border-0 file:bg-white/10 file:px-2.5 file:py-1.5 file:text-xs file:text-cream">
                            </div>
                            <div class="mt-2 flex flex-wrap items-center gap-3 text-xs text-cream/60">
                                <label class="flex items-center gap-1.5">เริ่ม
                                    <input form="bn-{{ $b->id }}" name="starts_at" type="datetime-local" value="{{ $b->starts_at?->format('Y-m-d\TH:i') }}" class="rounded-md border border-white/10 bg-surface-2 px-2 py-1 outline-none focus:border-brand">
                                </label>
                                <label class="flex items-center gap-1.5">สิ้นสุด
                                    <input form="bn-{{ $b->id }}" name="ends_at" type="datetime-local" value="{{ $b->ends_at?->format('Y-m-d\TH:i') }}" class="rounded-md border border-white/10 bg-surface-2 px-2 py-1 outline-none focus:border-brand">
                                </label>
                                <label class="flex items-center gap-1.5">
                                    <input form="bn-{{ $b->id }}" type="checkbox" name="hide_for_pro" value="1" @checked($b->hide_for_pro) class="h-4 w-4 accent-brand"> ซ่อนจากสมาชิก Pro
                                </label>
                                <label class="flex items-center gap-1.5">
                                    <input form="bn-{{ $b->id }}" type="checkbox" name="is_active" value="1" @checked($b->is_active) class="h-4 w-4 accent-brand"> แสดง
                                </label>
                                <button form="bn-{{ $b->id }}" class="rounded-md bg-white/5 px-3 py-1.5 hover:bg-white/10">บันทึก</button>
                                <form method="POST" action="{{ route('admin.app-banners.destroy', $b) }}" onsubmit="return confirm('ลบแบนเนอร์นี้?')">
                                    @csrf @method('DELETE')
                                    <button class="rounded-md bg-[#e5484d]/15 px-3 py-1.5 text-[#ff6b81] hover:bg-[#e5484d]/25">ลบ</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="px-2 py-10 text-center text-cream/45">ยังไม่มีแบนเนอร์ — เพิ่มได้จากแบบฟอร์มด้านขวา</div>
            @endforelse
        </div>
    </div>

    <div class="nx-card h-fit p-5">
        <h3 class="mb-4 text-base font-semibold">เพิ่มแบนเนอร์ใหม่</h3>
        <form method="POST" action="{{ route('admin.app-banners.store') }}" enctype="multipart/form-data" class="flex flex-col gap-3">
            @csrf
            <input name="title" placeholder="ชื่อ เช่น สมัครใหม่รับฟรีโปร 1 เดือน" class="nx-input" maxlength="120">
            <label class="text-xs text-cream/50">รูปแบนเนอร์ (แนะนำ 1600×640 หรืออัตราส่วน ~2.5:1)</label>
            <input name="image_file" type="file" accept="image/*" class="text-sm text-cream/70 file:mr-3 file:rounded-md file:border-0 file:bg-white/10 file:px-3 file:py-2 file:text-sm file:text-cream">
            <input name="image_url" type="url" placeholder="หรือใส่ลิงก์รูปโดยตรง" class="nx-input">
            <input name="link_url" type="url" placeholder="ลิงก์เมื่อกด (ไม่บังคับ)" class="nx-input">
            <div class="grid grid-cols-2 gap-2 text-xs text-cream/60">
                <label>เริ่มแสดง<input name="starts_at" type="datetime-local" class="nx-input mt-1 w-full"></label>
                <label>สิ้นสุด<input name="ends_at" type="datetime-local" class="nx-input mt-1 w-full"></label>
            </div>
            <div class="flex items-center gap-4">
                <input name="sort" type="number" value="0" placeholder="ลำดับ" class="nx-input w-24">
                <label class="flex items-center gap-2 text-sm text-cream/70">
                    <input type="checkbox" name="hide_for_pro" value="1" class="h-4 w-4 accent-brand"> ซ่อนจาก Pro
                </label>
                <label class="flex items-center gap-2 text-sm text-cream/70">
                    <input type="checkbox" name="is_active" value="1" checked class="h-4 w-4 accent-brand"> แสดง
                </label>
            </div>
            <button class="btn-brand py-2.5">+ เพิ่มแบนเนอร์</button>
        </form>
    </div>
</div>
@endsection
