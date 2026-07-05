@extends('layouts.admin')
@section('page-title', 'หนังที่ใช้ลิ้งค์สำรอง')
@section('page-subtitle', 'หนังที่ถูกดึงลิ้งค์สำรองมาจากเว็บอื่นอัตโนมัติ เพราะลิ้งค์เดิมเล่นไม่ได้ — และเผยแพร่ให้ใหม่แล้ว')

@section('content')
{{-- On/off toggle for the daily finder --}}
<div class="mb-5 flex flex-wrap items-center justify-between gap-4 rounded-xl border border-white/5 bg-white/[0.02] px-4 py-4">
    <div class="min-w-0 text-[13px] leading-relaxed text-cream/55">
        <div class="mb-0.5 font-semibold text-cream/80">ค้นหาลิ้งค์สำรองอัตโนมัติทุกวัน</div>
        เมื่อหนังถูกหยุดเผยแพร่เพราะเล่นไม่ได้ ระบบจะไปหาลิ้งค์เดียวกันจากเว็บสำรอง แล้วสลับมาใช้ + เผยแพร่ให้อัตโนมัติ
        @if (!empty($poolNames))
            <div class="mt-1 text-[12px] text-cream/40">เว็บสำรองในระบบ: {{ implode(' · ', $poolNames) }}</div>
        @endif
    </div>
    <form method="POST" action="{{ route('admin.backups.toggle') }}" class="shrink-0">
        @csrf
        <input type="hidden" name="enabled" value="{{ $enabled ? '0' : '1' }}">
        <button class="rounded-lg px-4 py-2.5 text-[13px] font-semibold {{ $enabled ? 'bg-success/15 text-success hover:bg-success/25' : 'bg-white/5 text-cream/60 hover:bg-white/10' }}">
            <span class="inline-flex h-2 w-2 rounded-full {{ $enabled ? 'bg-success' : 'bg-cream/40' }} align-middle"></span>
            <span class="ml-1.5 align-middle">{{ $enabled ? 'เปิดอยู่ — กดเพื่อปิด' : 'ปิดอยู่ — กดเพื่อเปิด' }}</span>
        </button>
    </form>
</div>

@if ($items->total() === 0)
    <div class="nx-card p-10 text-center text-cream/55">
        <div class="text-4xl">🔗</div>
        <div class="mt-3">ยังไม่มีหนังที่ใช้ลิ้งค์สำรอง</div>
    </div>
@else
    <div class="text-[13px] text-cream/45">มี {{ number_format($items->total()) }} เรื่องที่ใช้ลิ้งค์สำรองอยู่</div>
    <div class="mt-3 space-y-2.5">
        @foreach ($items as $c)
            <div class="nx-card flex flex-wrap items-center gap-4 p-3.5">
                <img src="{{ $c->poster_url }}" alt="" referrerpolicy="no-referrer" onerror="this.style.visibility='hidden'"
                     class="h-20 w-14 shrink-0 rounded-lg object-cover ring-1 ring-white/10" style="background:#1a1420">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="truncate font-semibold">{{ $c->title }}</span>
                        <span class="rounded-full bg-white/5 px-2 py-0.5 text-[11px] text-cream/50">{{ ['series' => 'ซีรี่ส์', 'movie' => 'ภาพยนตร์', 'vertical' => 'แนวตั้ง'][$c->type] ?? $c->type }}</span>
                    </div>
                    <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[12px] text-cream/45">
                        <span class="text-[#8b5cf6]">🔗 ลิ้งค์สำรองจาก <b class="text-cream/70">{{ $siteLabels[$c->id] ?? '—' }}</b></span>
                        <span>· ต้นทางเดิม {{ $c->source ?: '—' }}</span>
                        <span>· {{ $c->episodes->count() }} ตอนใช้สำรอง</span>
                    </div>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <a href="{{ route('watch', $c) }}" target="_blank"
                       class="rounded-lg bg-white/5 px-3 py-2 text-[13px] text-cream/70 hover:bg-white/10">▶ ลองเล่น</a>
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-5">{{ $items->links() }}</div>
@endif
@endsection
