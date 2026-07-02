@extends('layouts.admin')
@section('page-title', 'จัดเก็บสื่อ')
@section('page-subtitle', 'ไฟล์วิดีโอที่ดาวน์โหลดมาเก็บ + พื้นที่คงเหลือ')
@section('action')<a href="{{ route('admin.import.index') }}" class="rounded-lg bg-white/5 px-4 py-2.5 text-sm hover:bg-white/10">→ นำเข้าหนัง</a>@endsection

@php
    $s = $summary;
    $usedGb = $s['used'] / 1e9;
    $capGb = $s['cap_bytes'] / 1e9;
    $pct = $capGb > 0 ? min(100, round($usedGb / $capGb * 100, 1)) : 0;
    $freeGb = $s['free'] / 1e9;
    $avgMb = $s['avg'] / 1e6;
    $projGb = $projectedBytes / 1e9;
    $srcColors = ['rongyok' => '#ff2d55', 'wowdrama' => '#b026ff'];
    $srcMax = collect($s['per_source'])->max('bytes') ?: 1;
@endphp

@section('content')
{{-- Stat cards --}}
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    @foreach ([
        ['มิเรอร์แล้ว', number_format($s['mirrored']).' ตอน', '#ff2d55'],
        ['ใช้พื้นที่', number_format($usedGb, 2).' / '.number_format($capGb, 0).' GB', '#b026ff'],
        ['ขนาดเฉลี่ย/ตอน', ($s['mirrored'] ? number_format($avgMb, 1) : '0').' MB', '#ff2d55'],
        ['ดิสก์เซิร์ฟเวอร์ว่าง', number_format($freeGb, 0).' GB', '#b026ff'],
    ] as [$label, $value, $glow])
        <div class="nx-card relative overflow-hidden p-5">
            <div class="absolute -right-8 -top-8 h-24 w-24 rounded-full opacity-50 blur-2xl" style="background:{{ $glow }}"></div>
            <div class="relative text-[13px] text-cream/55">{{ $label }}</div>
            <div class="relative mt-1.5 text-2xl font-extrabold">{{ $value }}</div>
        </div>
    @endforeach
</div>

{{-- Customer download requests --}}
@if ($requestsTotal > 0)
    <div class="nx-card mt-6 overflow-hidden border-brand/30">
        <div class="flex items-center justify-between border-b border-white/5 bg-brand/[0.06] px-5 py-4">
            <h3 class="flex items-center gap-2 text-base font-semibold">🔔 คำขอจากลูกค้า (รอโหลด)
                <span class="nx-gradient rounded-full px-2 py-0.5 text-xs font-bold">{{ number_format($requestsTotal) }}</span>
            </h3>
            <span class="text-xs text-cream/50">ลูกค้ากดดูแต่ยังไม่ได้มิเรอร์ — NetwixSync จะโหลดให้ก่อน</span>
        </div>
        <div class="divide-y divide-white/[0.04]">
            @foreach ($requests as $ep)
                <div class="flex items-center gap-3 px-5 py-3">
                    <span class="flex h-7 min-w-7 items-center justify-center rounded-full bg-brand/20 px-2 text-xs font-bold text-brand" title="จำนวนคำขอ">{{ $ep->mirror_requests }}×</span>
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-medium">{{ $ep->content->title }} <span class="text-cream/50">· ตอนที่ {{ $ep->number }}</span></div>
                        <div class="text-xs text-cream/40">ขอครั้งแรก {{ $ep->mirror_requested_at?->diffForHumans() }}</div>
                    </div>
                    <span class="rounded-full bg-white/10 px-2.5 py-0.5 text-[11px] text-cream/60">รอ NetwixSync</span>
                    <a href="{{ route('admin.contents.edit', $ep->content) }}" class="rounded-md bg-white/5 px-3 py-1.5 text-xs hover:bg-white/10">ดูเรื่อง</a>
                </div>
            @endforeach
        </div>
        @if ($customerMirrored > 0)
            <div class="border-t border-white/5 px-5 py-2.5 text-xs text-cream/45">โหลดตามคำขอลูกค้าไปแล้วทั้งหมด {{ number_format($customerMirrored) }} ตอน</div>
        @endif
    </div>
@endif

<div class="mt-6 grid gap-4 lg:grid-cols-[1fr_1.4fr]">
    {{-- Usage donut --}}
    <div class="nx-card flex items-center gap-6 p-6">
        <div class="relative h-36 w-36 flex-shrink-0 rounded-full"
             style="background:conic-gradient(#ff2d55 0 {{ $pct }}%, #b026ff 0 {{ $pct }}%, rgba(255,255,255,0.08) {{ $pct }}% 100%)">
            <div class="absolute inset-[14px] flex flex-col items-center justify-center rounded-full bg-panel-2">
                <span class="text-2xl font-extrabold">{{ $pct }}%</span>
                <span class="text-[11px] text-cream/45">ของเพดาน</span>
            </div>
        </div>
        <div class="text-sm">
            <div class="mb-2 font-semibold">พื้นที่จัดเก็บ NetWix</div>
            <div class="flex items-center gap-2 text-cream/70"><span class="h-2.5 w-2.5 rounded-full" style="background:#ff2d55"></span> ใช้ไป {{ number_format($usedGb, 2) }} GB</div>
            <div class="flex items-center gap-2 text-cream/70"><span class="h-2.5 w-2.5 rounded-full bg-white/15"></span> เหลือในเพดาน {{ number_format(max(0, $capGb - $usedGb), 2) }} GB</div>
            <div class="mt-2 text-xs text-cream/45">เพดาน {{ number_format($capGb, 0) }} GB · ดิสก์จริงว่าง {{ number_format($freeGb, 0) }} GB</div>
        </div>
    </div>

    {{-- Per-source bars + projection --}}
    <div class="nx-card p-6">
        <h3 class="mb-4 text-base font-semibold">แยกตามแหล่งที่มา</h3>
        <div class="flex flex-col gap-3.5">
            @forelse ($s['per_source'] as $src)
                @php $c = $srcColors[$src['source']] ?? '#8b2ff0'; @endphp
                <div>
                    <div class="mb-1.5 flex justify-between text-[13px]">
                        <span>{{ $src['source'] === 'rongyok' ? 'โรงหยก (แนวตั้ง)' : ($src['source'] === 'wowdrama' ? 'wow-drama' : $src['source']) }}</span>
                        <span class="text-cream/50">{{ number_format($src['count']) }} ตอน · {{ number_format($src['bytes'] / 1e9, 2) }} GB</span>
                    </div>
                    <div class="h-2.5 overflow-hidden rounded-full bg-white/[0.07]">
                        <div class="h-full rounded-full" style="width:{{ max(2, round($src['bytes'] / $srcMax * 100)) }}%;background:{{ $c }}"></div>
                    </div>
                </div>
            @empty
                <div class="text-sm text-cream/45">ยังไม่มีไฟล์ที่มิเรอร์ — รัน NetwixSync บนเครื่องบ้านเพื่อดาวน์โหลดมาเก็บ</div>
            @endforelse
        </div>

        <div class="mt-5 rounded-xl border border-white/5 bg-white/[0.03] p-4 text-sm">
            <div class="mb-1 font-semibold text-cream/80">📊 ประมาณการวางแผน (rongyok)</div>
            <div class="text-cream/60">
                ยังไม่ได้มิเรอร์ <span class="font-semibold text-cream">{{ number_format($pendingCount) }}</span> ตอน ·
                ถ้ามิเรอร์ครบทั้งหมด <span class="font-semibold text-cream">{{ number_format($mirrorableTotal) }}</span> ตอน
                @if ($s['mirrored'])
                    ≈ <span class="font-semibold {{ $projGb > $capGb ? 'text-[#ff6b81]' : 'text-success' }}">{{ number_format($projGb, 1) }} GB</span>
                    (ที่ขนาดเฉลี่ย {{ number_format($avgMb, 1) }} MB/ตอน)
                    @if ($projGb > $capGb)<div class="mt-1 text-xs text-[#ff6b81]">⚠ เกินเพดาน {{ number_format($capGb, 0) }} GB — เพิ่ม NETWIX_MEDIA_MAX_GB หรือย้ายบางส่วนไป R2</div>@endif
                @else
                    — มิเรอร์อย่างน้อย 1 ตอนเพื่อคำนวณค่าเฉลี่ย
                @endif
            </div>
        </div>
    </div>
</div>

{{-- Per-title table --}}
<div class="nx-card mt-6 overflow-hidden">
    <div class="border-b border-white/5 px-5 py-4 text-base font-semibold">รายเรื่องที่ดาวน์โหลดมาเก็บ ({{ $titles->total() }})</div>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[640px] text-sm">
            <thead class="border-b border-white/5 text-left text-xs uppercase text-cream/40">
                <tr>
                    <th class="px-5 py-3 font-medium">เรื่อง</th>
                    <th class="px-5 py-3 font-medium">แหล่ง</th>
                    <th class="px-5 py-3 font-medium">ตอนที่เก็บ</th>
                    <th class="px-5 py-3 font-medium">ขนาดรวม</th>
                    <th class="px-5 py-3 font-medium">เฉลี่ย/ตอน</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($titles as $t)
                    <tr class="border-b border-white/[0.04] hover:bg-white/[0.02]">
                        <td class="px-5 py-3">
                            <div class="flex items-center gap-3">
                                <div class="h-10 w-[68px] flex-shrink-0 rounded" style="background:{{ $t->gradient }}"></div>
                                <span class="font-medium">{{ $t->title }}</span>
                            </div>
                        </td>
                        <td class="px-5 py-3 text-cream/60">{{ $t->source === 'rongyok' ? 'โรงหยก' : ($t->source ?: '—') }}</td>
                        <td class="px-5 py-3 text-cream/70">{{ $t->mirrored_count }} / {{ $t->total_episodes }}</td>
                        <td class="px-5 py-3 font-semibold">{{ number_format(($t->media_bytes ?? 0) / 1e6, 0) }} MB</td>
                        <td class="px-5 py-3 text-cream/70">{{ $t->mirrored_count ? number_format(($t->media_bytes ?? 0) / 1e6 / $t->mirrored_count, 1) : 0 }} MB</td>
                        <td class="px-5 py-3 text-right"><a href="{{ route('admin.contents.edit', $t) }}" class="rounded-md bg-white/5 px-3 py-1.5 text-xs hover:bg-white/10">ดูตอน</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-5 py-12 text-center text-cream/45">
                        ยังไม่มีไฟล์ที่ดาวน์โหลดมาเก็บ<br>
                        <span class="text-xs">รัน <code class="rounded bg-white/10 px-1.5 py-0.5">NetwixSync --token …</code> บนเครื่องบ้าน แล้วไฟล์จะมาโผล่ที่นี่</span>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-5">{{ $titles->links() }}</div>
@endsection
