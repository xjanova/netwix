@extends('layouts.admin')
@section('page-title', 'ยอดดาวน์โหลดแอป')
@section('page-subtitle', 'จำนวนครั้งที่ลูกค้าโหลดแอปแอนดรอยด์จากหน้าเว็บ')
@section('action')<span></span>@endsection

@section('content')
@php
    // AppDownload stores the tag with its punctuation intact (e.g. "v1.3.0"), so this
    // matches the release tag as-is.
    $latestVersion = $latest['version'] ?? null;
@endphp

{{-- KPIs --}}
<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    @foreach ($kpis as $k)
        <div class="nx-card p-5">
            <div class="text-[13px] text-cream/55">{{ $k['label'] }}</div>
            <div class="mt-1.5 text-3xl font-extrabold">{{ $k['value'] }}</div>
        </div>
    @endforeach
</div>

{{-- Daily chart --}}
<div class="nx-card mt-6 p-5">
    <h3 class="mb-5 text-base font-semibold">ดาวน์โหลดรายวัน (30 วัน)
        <span class="text-[13px] font-normal text-cream/40">นับ 1 ครั้งต่อผู้ใช้ต่อเวอร์ชันในแต่ละชั่วโมง (กันนับซ้ำจากโปรแกรมโหลดไฟล์)</span>
    </h3>
    <div class="flex h-48 items-end gap-[3px] pt-2">
        @foreach ($daily as $d)
            <div class="group relative flex h-full flex-1 flex-col items-center justify-end gap-1" title="{{ $d['label'] }}: {{ $d['value'] }}">
                <div class="w-full rounded-t nx-gradient" style="height:{{ $d['height'] }};min-height:2px"></div>
                @if ($loop->index % 5 === 0 || $loop->last)
                    <span class="text-[9px] text-cream/40">{{ $d['label'] }}</span>
                @else
                    <span class="text-[9px] text-transparent">·</span>
                @endif
            </div>
        @endforeach
    </div>
</div>

<div class="mt-6 grid gap-4 lg:grid-cols-2">
    {{-- Per version --}}
    <div class="nx-card p-5">
        <h3 class="mb-4 text-base font-semibold">แยกตามเวอร์ชัน (สะสม)</h3>
        <div class="flex flex-col gap-3">
            @forelse ($versions as $v)
                <div>
                    <div class="mb-1.5 flex justify-between gap-3 text-[13px]">
                        <span class="truncate">
                            {{ $v['label'] }}
                            @if ($latestVersion && $v['label'] === $latestVersion)
                                <span class="ml-1 rounded-full bg-success/15 px-2 py-0.5 text-[11px] text-success">ล่าสุด</span>
                            @endif
                        </span>
                        <span class="shrink-0 text-cream/50">{{ number_format($v['value']) }}</span>
                    </div>
                    <div class="h-2.5 overflow-hidden rounded-full bg-white/[0.07]"><div class="h-full rounded-full nx-gradient" style="width:{{ $v['pct'] }}"></div></div>
                    <div class="mt-1 text-[11px] text-cream/35">โหลดล่าสุด {{ $v['ago'] }}</div>
                </div>
            @empty
                <div class="py-6 text-center text-sm text-cream/45">ยังไม่มีใครดาวน์โหลด — ยอดจะเริ่มนับทันทีที่มีคนกดโหลดจากหน้า /download</div>
            @endforelse
        </div>
    </div>

    {{-- Splits + release info --}}
    <div class="nx-card p-5">
        <h3 class="mb-4 text-base font-semibold">รายละเอียด</h3>
        <div class="grid grid-cols-2 gap-3 text-sm">
            <div class="rounded-lg bg-white/[0.03] p-3.5">
                <div class="text-[12px] text-cream/50">โหลดจากมือถือแอนดรอยด์</div>
                <div class="mt-1 text-2xl font-bold">{{ $splits['androidPct'] }}%</div>
                <div class="text-[11px] text-cream/40">{{ number_format($splits['android']) }} ครั้ง · เครื่องอื่น {{ number_format($splits['other']) }}</div>
            </div>
            <div class="rounded-lg bg-white/[0.03] p-3.5">
                <div class="text-[12px] text-cream/50">โหลดโดยสมาชิก</div>
                <div class="mt-1 text-2xl font-bold">{{ $splits['memberPct'] }}%</div>
                <div class="text-[11px] text-cream/40">{{ number_format($splits['members']) }} ครั้ง · ยังไม่สมัคร {{ number_format($splits['guests']) }}</div>
            </div>
        </div>

        <div class="mt-4 rounded-lg bg-white/[0.03] p-3.5 text-sm">
            <div class="text-[12px] text-cream/50">เวอร์ชันที่เว็บแจกอยู่ตอนนี้</div>
            @if ($latest)
                <div class="mt-1 text-lg font-bold">{{ $latest['version'] }}</div>
                <div class="text-[11px] text-cream/40">
                    ขนาด {{ number_format($latest['size'] / 1048576, 1) }} MB
                    @if ($latest['published_at'])
                        · ออกเมื่อ {{ \Illuminate\Support\Carbon::parse($latest['published_at'])->diffForHumans() }}
                    @endif
                </div>
            @else
                <div class="mt-1 text-[13px] text-cream/45">ยังไม่ได้ตั้งค่า GitHub repo ของแอป (ตั้งที่ "ตั้งค่า / เชื่อมต่อ")</div>
            @endif
        </div>

        <p class="mt-4 text-[12px] leading-relaxed text-cream/40">
            นับเฉพาะการโหลดจากหน้าเว็บ (/download) — แอปที่อัปเดตตัวเองในเครื่อง (OTA) ดึงไฟล์จาก GitHub โดยตรง จึงไม่ถูกนับซ้ำที่นี่
        </p>
    </div>
</div>
@endsection
