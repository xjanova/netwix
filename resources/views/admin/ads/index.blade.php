@extends('layouts.admin')
@section('page-title', 'โฆษณาก่อนเล่น (Pre-roll)')
@section('page-subtitle', 'ตั้งโฆษณาให้เล่นก่อนเริ่มวิดีโอ — ภาพนิ่ง/วิดีโอ, ตั้งเวลาข้าม, เจาะจงหมวด, ตั้งช่วงเวลา และซ่อนสำหรับสมาชิก Pro ได้')

@section('content')
{{-- add new --}}
<div class="mb-6 nx-card p-5" x-data="{ open: false }">
    <button @click="open = !open" class="btn-brand px-4 py-2 text-sm">＋ เพิ่มโฆษณาใหม่</button>
    <form x-show="open" x-cloak method="POST" action="{{ route('admin.ads.store') }}" enctype="multipart/form-data" class="mt-4">
        @csrf
        @include('admin.ads._fields', ['a' => null])
        <div class="mt-4 flex justify-end"><button class="btn-brand px-5 py-2 text-sm">บันทึกโฆษณา</button></div>
    </form>
</div>

@if ($ads->isEmpty())
    <div class="nx-card p-10 text-center text-cream/50">ยังไม่มีโฆษณา — กด “เพิ่มโฆษณาใหม่” ด้านบน</div>
@endif

<div class="flex flex-col gap-3">
    @foreach ($ads as $ad)
        @php
            $targetLabel = match ($ad->target) {
                'type' => 'ประเภท: '.($types[$ad->target_type] ?? $ad->target_type),
                'genre' => 'แนว: '.($ad->genre?->name ?? '—'),
                default => 'ทุกวิดีโอ',
            };
            $live = $ad->is_active
                && (! $ad->starts_at || $ad->starts_at->isPast())
                && (! $ad->ends_at || $ad->ends_at->isFuture());
        @endphp
        <div class="nx-card p-4" x-data="{ edit: false }">
            <div class="flex flex-wrap items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white/5 text-lg">{{ $ad->media_type === 'video' ? '🎬' : '🖼' }}</span>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-semibold">{{ $ad->name }}</span>
                        @if (! $ad->is_active)
                            <span class="rounded bg-white/10 px-1.5 py-0.5 text-[10px] text-cream/50">ปิดอยู่</span>
                        @elseif (! $live)
                            <span class="rounded bg-gold/15 px-1.5 py-0.5 text-[10px] text-gold">นอกช่วงเวลา</span>
                        @else
                            <span class="rounded bg-success/15 px-1.5 py-0.5 text-[10px] text-success">กำลังแสดง</span>
                        @endif
                        @if ($ad->hide_for_pro)<span class="rounded bg-white/5 px-1.5 py-0.5 text-[10px] text-cream/50">ซ่อนจาก Pro</span>@endif
                    </div>
                    <div class="mt-0.5 text-xs text-cream/45">
                        {{ $targetLabel }}
                        · {{ $ad->skippable ? 'ข้ามได้ '.$ad->skip_after.'วิ' : 'ข้ามไม่ได้' }}
                        @if ($ad->starts_at || $ad->ends_at)
                            · {{ $ad->starts_at?->format('j M') ?? '…' }}–{{ $ad->ends_at?->format('j M') ?? '…' }}
                        @endif
                    </div>
                </div>
                <button type="button" @click="edit = !edit" class="rounded-md bg-white/5 px-3 py-1.5 text-xs hover:bg-white/10">แก้ไข</button>
                <form method="POST" action="{{ route('admin.ads.toggle', $ad) }}">
                    @csrf
                    <button class="rounded-md px-3 py-1.5 text-xs font-semibold {{ $ad->is_active ? 'text-success' : 'text-cream/45' }} hover:bg-white/5">{{ $ad->is_active ? 'เปิด' : 'ปิด' }}</button>
                </form>
                <form method="POST" action="{{ route('admin.ads.destroy', $ad) }}" onsubmit="return confirm('ลบโฆษณานี้?')">
                    @csrf @method('DELETE')
                    <button class="rounded-md bg-[#e5484d]/15 px-3 py-1.5 text-xs text-[#ff6b81] hover:bg-[#e5484d]/25">ลบ</button>
                </form>
            </div>
            <form x-show="edit" x-cloak method="POST" action="{{ route('admin.ads.update', $ad) }}" enctype="multipart/form-data" class="mt-4 border-t border-white/5 pt-4">
                @csrf @method('PUT')
                @include('admin.ads._fields', ['a' => $ad])
                <div class="mt-4 flex justify-end"><button class="btn-brand px-5 py-2 text-sm">บันทึกการแก้ไข</button></div>
            </form>
        </div>
    @endforeach
</div>
@endsection
