@extends('layouts.admin')
@section('page-title', 'ข่าวสารหน้าแรก')
@section('page-subtitle', 'ข้อความที่เลื่อนบนแถบข่าวหน้าแรก (แก้ไข/เพิ่ม/ปิดได้)')
@section('action')<span></span>@endsection

@section('content')
<div class="grid gap-6 lg:grid-cols-[2fr_1fr]">
    <div class="nx-card p-4">
        <div class="mb-3 hidden grid-cols-[90px_1fr_70px_80px_auto] gap-3 px-2 text-xs uppercase text-cream/40 md:grid">
            <span>ป้าย</span><span>ข้อความ</span><span>ลำดับ</span><span>แสดง</span><span></span>
        </div>
        <div class="flex flex-col gap-1.5">
            @forelse ($announcements as $a)
                <div class="grid grid-cols-2 items-center gap-3 rounded-lg bg-white/[0.03] px-2 py-2 md:grid-cols-[90px_1fr_70px_80px_auto]">
                    <form method="POST" action="{{ route('admin.announcements.update', $a) }}" id="ann-{{ $a->id }}"></form>
                    <input form="ann-{{ $a->id }}" name="badge" value="{{ $a->badge }}" placeholder="ป้าย" class="rounded-md border border-white/10 bg-surface-2 px-2.5 py-1.5 text-sm outline-none focus:border-brand">
                    <input form="ann-{{ $a->id }}" name="body" value="{{ $a->body }}" class="col-span-2 rounded-md border border-white/10 bg-surface-2 px-2.5 py-1.5 text-sm outline-none focus:border-brand md:col-span-1" required>
                    <input form="ann-{{ $a->id }}" name="sort" type="number" value="{{ $a->sort }}" class="w-full rounded-md border border-white/10 bg-surface-2 px-2.5 py-1.5 text-sm outline-none focus:border-brand">
                    <label class="flex items-center gap-2 text-xs text-cream/60">
                        <input form="ann-{{ $a->id }}" type="checkbox" name="is_active" value="1" @checked($a->is_active) class="h-4 w-4 accent-brand">
                        แสดง
                    </label>
                    <div class="col-span-2 flex items-center gap-2 md:col-span-1">
                        <input form="ann-{{ $a->id }}" name="link" value="{{ $a->link }}" placeholder="ลิงก์ (ไม่บังคับ)" class="min-w-0 flex-1 rounded-md border border-white/10 bg-surface-2 px-2.5 py-1.5 text-xs outline-none focus:border-brand">
                        <button form="ann-{{ $a->id }}" class="rounded-md bg-white/5 px-3 py-1.5 text-xs hover:bg-white/10">บันทึก</button>
                        <form method="POST" action="{{ route('admin.announcements.destroy', $a) }}" onsubmit="return confirm('ลบข่าวนี้?')">
                            @csrf @method('DELETE')
                            <button class="rounded-md bg-[#e5484d]/15 px-3 py-1.5 text-xs text-[#ff6b81] hover:bg-[#e5484d]/25">ลบ</button>
                        </form>
                    </div>
                    <input form="ann-{{ $a->id }}" type="hidden" name="_token" value="{{ csrf_token() }}">
                    <input form="ann-{{ $a->id }}" type="hidden" name="_method" value="PUT">
                </div>
            @empty
                <div class="px-2 py-10 text-center text-cream/45">ยังไม่มีข่าวสาร — เพิ่มได้จากแบบฟอร์มด้านขวา</div>
            @endforelse
        </div>
        <p class="mt-4 px-2 text-xs text-cream/40">ปล่อยว่างไว้ระบบจะแสดงข้อความเริ่มต้นอัตโนมัติบนหน้าแรก</p>
    </div>

    <div class="nx-card h-fit p-5">
        <h3 class="mb-4 text-base font-semibold">เพิ่มข่าวใหม่</h3>
        <form method="POST" action="{{ route('admin.announcements.store') }}" class="flex flex-col gap-3">
            @csrf
            <input name="badge" placeholder="ป้าย เช่น ใหม่ / ร้อนแรง" class="nx-input" maxlength="40">
            <textarea name="body" placeholder="ข้อความข่าวสาร" class="nx-input" rows="2" maxlength="300" required></textarea>
            <input name="link" type="url" placeholder="ลิงก์ (ไม่บังคับ)" class="nx-input">
            <div class="flex items-center gap-3">
                <input name="sort" type="number" value="{{ $announcements->count() }}" placeholder="ลำดับ" class="nx-input flex-1">
                <label class="flex items-center gap-2 whitespace-nowrap text-sm text-cream/70">
                    <input type="checkbox" name="is_active" value="1" checked class="h-4 w-4 accent-brand"> แสดง
                </label>
            </div>
            <button class="btn-brand py-2.5">+ เพิ่มข่าว</button>
        </form>
    </div>
</div>
@endsection
