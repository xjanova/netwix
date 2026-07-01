@extends('layouts.admin')
@section('page-title', 'วิเคราะห์ข้อมูล')
@section('page-subtitle', 'สถิติการเติบโตและพฤติกรรมการรับชม')
@section('action')<span></span>@endsection

@section('content')
<div class="grid gap-4 sm:grid-cols-3">
    @foreach ($kpis as $k)
        <div class="nx-card p-5">
            <div class="text-[13px] text-cream/55">{{ $k['label'] }}</div>
            <div class="mt-1.5 text-3xl font-extrabold">{{ $k['value'] }}</div>
        </div>
    @endforeach
</div>

<div class="nx-card mt-6 p-5">
    <h3 class="mb-5 text-base font-semibold">สมาชิกใหม่รายวัน (14 วัน)</h3>
    <div class="flex h-48 items-end gap-2 pt-2">
        @foreach ($signups as $d)
            <div class="flex h-full flex-1 flex-col items-center justify-end gap-1.5">
                <span class="text-[10px] text-cream/60">{{ $d['value'] }}</span>
                <div class="w-full rounded-t nx-gradient" style="height:{{ $d['height'] }}"></div>
                <span class="text-[10px] text-cream/40">{{ $d['label'] }}</span>
            </div>
        @endforeach
    </div>
</div>

<div class="mt-6 grid gap-4 lg:grid-cols-2">
    <div class="nx-card p-5">
        <h3 class="mb-4 text-base font-semibold">ยอดวิวตามประเภท</h3>
        <div class="flex flex-col gap-3.5">
            @foreach ($typeViews as $t)
                <div>
                    <div class="mb-1.5 flex justify-between text-[13px]"><span>{{ $t['label'] }}</span><span class="text-cream/50">{{ number_format($t['value']) }} · {{ $t['pct'] }}</span></div>
                    <div class="h-2.5 overflow-hidden rounded-full bg-white/[0.07]"><div class="h-full rounded-full nx-gradient" style="width:{{ $t['pct'] }}"></div></div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="nx-card p-5">
        <h3 class="mb-4 text-base font-semibold">หมวดยอดนิยม (ตามยอดวิว)</h3>
        <div class="flex flex-col gap-3">
            @forelse ($genrePopularity as $g)
                <div>
                    <div class="mb-1.5 flex justify-between text-[13px]"><span>{{ $g['label'] }}</span><span class="text-cream/50">{{ number_format($g['value']) }}</span></div>
                    <div class="h-2.5 overflow-hidden rounded-full bg-white/[0.07]"><div class="h-full rounded-full nx-gradient" style="width:{{ $g['pct'] }}"></div></div>
                </div>
            @empty
                <div class="text-sm text-cream/45">ยังไม่มีข้อมูล</div>
            @endforelse
        </div>
    </div>
</div>
@endsection
