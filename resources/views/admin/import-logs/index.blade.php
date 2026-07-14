@extends('layouts.admin')
@section('page-title', 'ประวัติการนำเข้าหนัง')
@section('page-subtitle', 'บันทึกทุกครั้งที่มีการนำเข้าคอนเทนต์เข้าคลัง')

@section('content')
@php
    $actionMeta = [
        'manual' => ['label' => 'ในแผงแอดมิน', 'cls' => 'bg-brand/15 text-brand'],
        'scheduled' => ['label' => 'อัตโนมัติตามเวลา', 'cls' => 'bg-success/15 text-success'],
        'backfill' => ['label' => 'ข้อมูลเดิม', 'cls' => 'bg-white/10 text-cream/55'],
    ];
@endphp

{{-- summary --}}
<div class="mb-5 grid gap-3 sm:grid-cols-3">
    <div class="nx-card p-4">
        <div class="text-xs text-cream/45">นำเข้าทั้งหมด (สะสม)</div>
        <div class="mt-1 text-2xl font-extrabold">{{ number_format($totals['imported']) }}</div>
    </div>
    <div class="nx-card p-4">
        <div class="text-xs text-cream/45">นำเข้าวันนี้</div>
        <div class="mt-1 text-2xl font-extrabold">{{ number_format($totals['today']) }}</div>
    </div>
    <div class="nx-card p-4">
        <div class="text-xs text-cream/45">จำนวนรอบนำเข้า</div>
        <div class="mt-1 text-2xl font-extrabold">{{ number_format($totals['runs']) }}</div>
    </div>
</div>

{{-- filters --}}
<form method="GET" action="{{ route('admin.import-logs.index') }}" class="mb-4 flex flex-wrap items-center gap-2 text-sm">
    <select name="source" onchange="this.form.submit()" class="rounded-lg border border-white/10 bg-surface-2 px-3 py-2 outline-none focus:border-brand">
        <option value="">ทุกแหล่ง</option>
        @foreach ($sources as $s)
            <option value="{{ $s['id'] }}" @selected($source === $s['id'])>{{ $s['name'] }}</option>
        @endforeach
    </select>
    <select name="action" onchange="this.form.submit()" class="rounded-lg border border-white/10 bg-surface-2 px-3 py-2 outline-none focus:border-brand">
        <option value="">ทุกประเภท</option>
        @foreach ($actionMeta as $k => $m)
            <option value="{{ $k }}" @selected($action === $k)>{{ $m['label'] }}</option>
        @endforeach
    </select>
    @if ($source || $action)
        <a href="{{ route('admin.import-logs.index') }}" class="text-xs text-cream/50 hover:text-cream">ล้างตัวกรอง</a>
    @endif
</form>

<div class="nx-card p-4">
    <div class="hidden grid-cols-[1fr_1.2fr_2fr_1.4fr_1.2fr] gap-3 px-2 pb-3 text-xs uppercase text-cream/40 sm:grid">
        <span>แหล่ง</span><span>ประเภท</span><span>ผล</span><span>โดย</span><span>เวลา</span>
    </div>
    <div class="flex flex-col gap-1.5">
        @forelse ($logs as $log)
            <div class="grid grid-cols-1 items-center gap-2 rounded-lg bg-white/[0.03] px-3 py-2.5 text-sm sm:grid-cols-[1fr_1.2fr_2fr_1.4fr_1.2fr]">
                <span class="font-semibold">{{ $log->source }}</span>
                <span>
                    <span class="rounded-full px-2 py-0.5 text-[11px] {{ $actionMeta[$log->action]['cls'] ?? 'bg-white/10 text-cream/55' }}">{{ $actionMeta[$log->action]['label'] ?? $log->action }}</span>
                </span>
                <span class="text-cream/80">
                    <span class="text-success">+{{ number_format($log->imported) }}</span> นำเข้า
                    @if ($log->skipped)<span class="text-cream/45">· ข้าม {{ number_format($log->skipped) }}</span>@endif
                    @if ($log->failed)<span style="color:#ff6b81">· พลาด {{ number_format($log->failed) }}</span>@endif
                    @if ($log->note)<span class="block text-[11px] text-cream/35">{{ $log->note }}</span>@endif
                </span>
                <span class="text-cream/55">{{ $log->user?->name ?? 'ระบบ (อัตโนมัติ)' }}</span>
                <span class="text-cream/50" title="{{ $log->created_at }}">{{ $log->created_at?->diffForHumans() }}</span>
            </div>
        @empty
            <div class="px-2 py-12 text-center text-cream/45">ยังไม่มีประวัติการนำเข้า — เมื่อมีการนำเข้าคอนเทนต์จะบันทึกที่นี่</div>
        @endforelse
    </div>
</div>

<div class="mt-5">{{ $logs->links() }}</div>
@endsection
