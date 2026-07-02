@extends('layouts.admin')
@section('page-title', 'แดชบอร์ด')
@section('page-subtitle', 'ภาพรวมระบบ NetWix')

@section('content')
{{-- Stat cards --}}
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    @foreach ($stats as $s)
        <div class="nx-card relative overflow-hidden p-5">
            <div class="absolute -right-8 -top-8 h-24 w-24 rounded-full opacity-50 blur-2xl" style="background:{{ $s['glow'] }}"></div>
            <div class="relative text-[13px] text-cream/55">{{ $s['label'] }}</div>
            <div class="relative my-1.5 text-3xl font-extrabold">{{ $s['value'] }}</div>
            <div class="relative text-[12.5px] {{ ($s['positive'] ?? null) === true ? 'text-success' : 'text-cream/50' }}">{{ $s['delta'] }}</div>
        </div>
    @endforeach
</div>

{{-- Mini metrics --}}
<div class="mt-6 grid grid-cols-2 overflow-hidden rounded-2xl border border-white/5 sm:grid-cols-3 xl:grid-cols-6" style="gap:1px;background:rgba(255,255,255,0.06)">
    @foreach ($miniMetrics as $m)
        <div class="bg-panel-2 p-4">
            <div class="whitespace-nowrap text-[12px] text-cream/50">{{ $m['label'] }}</div>
            <div class="mt-1.5 text-xl font-bold">{{ $m['value'] }}</div>
        </div>
    @endforeach
</div>

<div class="mt-6 grid gap-4 lg:grid-cols-[1.6fr_1fr]">
    {{-- Activity bar chart --}}
    <div class="nx-card p-5">
        <div class="mb-5 flex items-center justify-between">
            <h3 class="text-base font-semibold">กิจกรรมการรับชม (7 วันล่าสุด)</h3>
            <span class="text-xs text-cream/45">หน่วย: ครั้ง</span>
        </div>
        <div class="flex h-44 items-end gap-3.5 pt-2">
            @foreach ($chartBars as $b)
                <div class="flex h-full flex-1 flex-col items-center justify-end gap-2">
                    <span class="text-[11.5px] font-semibold text-cream/70">{{ $b['value'] }}</span>
                    <div class="w-full max-w-[38px] rounded-t-md nx-gradient" style="height:{{ $b['height'] }}"></div>
                    <span class="text-[11.5px] text-cream/45">{{ $b['day'] }}</span>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Genre shares --}}
    <div class="nx-card p-5">
        <h3 class="mb-4 text-base font-semibold">สัดส่วนตามหมวด</h3>
        <div class="flex flex-col gap-3.5">
            @forelse ($genreShares as $g)
                <div>
                    <div class="mb-1.5 flex justify-between text-[13px]">
                        <span>{{ $g['label'] }}</span><span class="text-cream/50">{{ $g['pct'] }}</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-white/[0.07]">
                        <div class="h-full rounded-full nx-gradient" style="width:{{ $g['pct'] }}"></div>
                    </div>
                </div>
            @empty
                <div class="text-sm text-cream/45">ยังไม่มีข้อมูล</div>
            @endforelse
        </div>
    </div>
</div>

{{-- Storage usage --}}
@php
    $usedGb = $storage['used'] / 1e9;
    $capGb = $storage['cap_bytes'] / 1e9;
    $pct = $capGb > 0 ? min(100, round($usedGb / $capGb * 100, 1)) : 0;
    $srcMax = collect($storage['per_source'])->max('bytes') ?: 1;
    $srcColors = ['rongyok' => '#ff2d55', 'wowdrama' => '#b026ff'];
@endphp
<div class="nx-card mt-6 p-5">
    <div class="mb-4 flex items-center justify-between">
        <h3 class="text-base font-semibold">พื้นที่จัดเก็บสื่อ</h3>
        <a href="{{ route('admin.storage.index') }}" class="text-[13px] text-brand hover:underline">ดูรายละเอียด →</a>
    </div>
    <div class="grid gap-6 md:grid-cols-[auto_1fr]">
        <div class="flex items-center gap-4">
            <div class="relative h-28 w-28 flex-shrink-0 rounded-full"
                 style="background:conic-gradient(#ff2d55 0 {{ $pct }}%, #b026ff 0 {{ $pct }}%, rgba(255,255,255,0.08) {{ $pct }}% 100%)">
                <div class="absolute inset-3 flex flex-col items-center justify-center rounded-full bg-panel-2">
                    <span class="text-xl font-extrabold">{{ $pct }}%</span>
                </div>
            </div>
            <div class="text-sm">
                <div class="text-lg font-bold">{{ number_format($usedGb, 2) }} <span class="text-sm font-normal text-cream/50">/ {{ number_format($capGb, 0) }} GB</span></div>
                <div class="text-xs text-cream/50">{{ number_format($storage['mirrored']) }} ตอน · เฉลี่ย {{ $storage['mirrored'] ? number_format($storage['avg'] / 1e6, 1) : 0 }} MB/ตอน</div>
                <div class="mt-1 text-xs text-cream/40">ดิสก์ว่าง {{ number_format($storage['free'] / 1e9, 0) }} GB</div>
            </div>
        </div>
        <div class="flex flex-col justify-center gap-3">
            @forelse ($storage['per_source'] as $src)
                <div>
                    <div class="mb-1 flex justify-between text-[12.5px]">
                        <span>{{ $src['source'] === 'rongyok' ? 'โรงหยก' : ($src['source'] === 'wowdrama' ? 'wow-drama' : $src['source']) }}</span>
                        <span class="text-cream/50">{{ number_format($src['count']) }} ตอน · {{ number_format($src['bytes'] / 1e9, 2) }} GB</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-white/[0.07]">
                        <div class="h-full rounded-full" style="width:{{ max(2, round($src['bytes'] / $srcMax * 100)) }}%;background:{{ $srcColors[$src['source']] ?? '#8b2ff0' }}"></div>
                    </div>
                </div>
            @empty
                <div class="text-sm text-cream/45">ยังไม่มีไฟล์ที่ดาวน์โหลดมาเก็บ — รัน NetwixSync บนเครื่องบ้าน</div>
            @endforelse
        </div>
    </div>
</div>

{{-- Top content --}}
<div class="nx-card mt-6 p-5">
    <div class="mb-4 flex items-center justify-between">
        <h3 class="text-base font-semibold">คอนเทนต์ยอดนิยม</h3>
        <a href="{{ route('admin.contents.index') }}" class="text-[13px] text-brand hover:underline">ดูทั้งหมด →</a>
    </div>
    <div class="flex flex-col gap-0.5">
        @forelse ($topContent as $i => $c)
            <div class="flex items-center gap-4 rounded-lg p-2.5 hover:bg-white/[0.03]">
                <span class="w-6 text-[15px] font-bold text-cream/35">{{ $i + 1 }}</span>
                <div class="h-10 w-[68px] flex-shrink-0 rounded" style="background:{{ $c->gradient }}"></div>
                <div class="min-w-0 flex-1">
                    <div class="truncate text-sm font-semibold">{{ $c->title }}</div>
                    <div class="text-xs text-cream/45">{{ $c->primaryGenre()?->name }}</div>
                </div>
                <div class="w-24 text-right"><div class="text-[13.5px] font-semibold">{{ number_format($c->views) }}</div><div class="text-[11px] text-cream/40">ผู้ชม</div></div>
                <div class="w-14 text-right"><div class="text-[13.5px] font-semibold text-gold">★ {{ $c->rating }}</div><div class="text-[11px] text-cream/40">คะแนน</div></div>
            </div>
        @empty
            <div class="py-8 text-center text-sm text-cream/45">ยังไม่มีคอนเทนต์ — <a href="{{ route('admin.contents.create') }}" class="text-brand">เพิ่มเรื่องแรก</a></div>
        @endforelse
    </div>
</div>
@endsection
