@extends('layouts.admin')
@section('page-title', 'หยุดเผยแพร่ (ปัญหาการเล่น)')
@section('page-subtitle', 'หนังที่ถูกหยุดเผยแพร่อัตโนมัติเพราะผู้ใช้หลายคนเล่นไม่ได้ — ตรวจสอบแล้วเผยแพร่ใหม่ หรือลบเพื่อไปหาแหล่งใหม่')

@section('content')
@php
    $reasonText = ['no_source' => 'ลิงค์ต้นทางตาย (ระบบตรวจเจอ)', 'player' => 'ผู้ใช้เปิดแล้วเล่นไม่ได้'];
@endphp

@if (session('status'))
    <div class="mb-4 rounded-lg border border-success/30 bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</div>
@endif

<div class="mb-5 rounded-xl border border-white/5 bg-white/[0.02] px-4 py-3 text-[13px] leading-relaxed text-cream/50">
    ระบบจะหยุดเผยแพร่หนังให้อัตโนมัติเมื่อมีผู้ชม <b class="text-cream/75">ตั้งแต่ 5 คนขึ้นไป</b> เปิดแล้วเล่นไม่ได้ (หรือระบบตรวจพบว่าลิงค์ต้นทางตาย) —
    หนังจะถูกซ่อนจากหน้าเว็บทันที มารอให้ตรวจสอบที่นี่ ถ้ากด <span class="text-cream">เผยแพร่อีกครั้ง</span> จะมีช่วงผ่อนผัน 12 ชม. ก่อนถูกหยุดซ้ำ
</div>

@if ($items->total() === 0)
    <div class="nx-card p-10 text-center text-cream/55">
        <div class="text-4xl">✅</div>
        <div class="mt-3">ยังไม่มีหนังที่มีปัญหา — ทุกเรื่องเล่นได้ปกติ</div>
    </div>
@else
    <div class="text-[13px] text-cream/45">พบ {{ number_format($items->total()) }} เรื่องที่มีปัญหา</div>
    <div class="mt-3 space-y-2.5">
        @foreach ($items as $c)
            <div class="nx-card flex flex-wrap items-center gap-4 p-3.5">
                <img src="{{ $c->poster_url }}" alt="" referrerpolicy="no-referrer" onerror="this.style.visibility='hidden'"
                     class="h-20 w-14 shrink-0 rounded-lg object-cover ring-1 ring-white/10" style="background:#1a1420">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="truncate font-semibold">{{ $c->title }}</span>
                        <span class="rounded-full bg-white/5 px-2 py-0.5 text-[11px] text-cream/50">{{ $c->source ?: '—' }}</span>
                        <span class="rounded-full bg-white/5 px-2 py-0.5 text-[11px] text-cream/50">{{ ['series' => 'ซีรี่ส์', 'movie' => 'ภาพยนตร์', 'vertical' => 'แนวตั้ง'][$c->type] ?? $c->type }}</span>
                    </div>
                    <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[12px] text-cream/45">
                        <span class="text-[#ff6b81]">✗ {{ $reasonText[$c->suspend_reason] ?? $c->suspend_reason }}</span>
                        <span>· เล่นไม่ได้ {{ $c->playback_fail_count }} คน</span>
                        <span>· หยุดเมื่อ {{ $c->suspended_at?->diffForHumans() }}</span>
                    </div>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <a href="{{ route('watch', $c) }}" target="_blank"
                       class="rounded-lg bg-white/5 px-3 py-2 text-[13px] text-cream/70 hover:bg-white/10">▶ ลองเล่น</a>
                    <form method="POST" action="{{ route('admin.suspended.republish', $c) }}">
                        @csrf
                        <button class="rounded-lg bg-success/15 px-3.5 py-2 text-[13px] font-semibold text-success hover:bg-success/25">↑ เผยแพร่อีกครั้ง</button>
                    </form>
                    <form method="POST" action="{{ route('admin.contents.destroy', $c) }}"
                          onsubmit="return confirm('ลบ “{{ addslashes($c->title) }}” ทิ้งถาวร?')">
                        @csrf @method('DELETE')
                        <button class="rounded-lg bg-[#e5484d]/15 px-3.5 py-2 text-[13px] text-[#ff6b81] hover:bg-[#e5484d]/25">🗑 ลบ</button>
                    </form>
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-5">{{ $items->links() }}</div>
@endif
@endsection
