@extends('layouts.admin')
@section('page-title', 'ปฏิทินการจองโฆษณา')
@section('page-subtitle', 'มุมมองแอดมิน — เห็นว่าใครจองช่วงไหน (หน้าลูกค้าเห็นแค่ว่าว่างหรือเต็ม ไม่เห็นชื่อ)')

@section('content')
<a href="{{ route('admin.ad-market.index') }}" class="text-[13px] text-cream/50 hover:text-cream">← กลับไปตั้งค่าตลาดโฆษณา</a>

@if ($placements->isEmpty())
    <div class="nx-card mt-4 p-10 text-center text-cream/55">ยังไม่มีตำแหน่งขาย</div>
@else
    <div class="mt-4 flex flex-wrap gap-2">
        @foreach ($placements as $p)
            <a href="{{ route('admin.ad-market.calendar', ['placement' => $p->id]) }}"
               class="rounded-lg px-4 py-2 text-[13.5px] {{ $placement && $placement->id === $p->id ? 'nx-gradient font-semibold text-white' : 'bg-white/5 text-cream/65 hover:bg-white/10' }}">
                {{ $p->name }}
            </a>
        @endforeach
    </div>

    @if ($placement)
        {{-- 60-day heat strip --}}
        <div class="nx-card mt-4 p-5">
            <div class="mb-3 text-[15px] font-bold">{{ $placement->name }} — 60 วันข้างหน้า (รับได้ {{ $placement->max_concurrent }} เจ้า/วัน)</div>
            <div class="flex flex-wrap gap-1">
                @foreach ($days as $d)
                    <div title="{{ $d['date'] }} — {{ $d['taken'] }}/{{ $d['cap'] }}"
                         class="flex h-7 w-9 flex-col items-center justify-center rounded text-[9px] leading-none
                            {{ $d['full'] ? 'bg-brand/70 text-white' : ($d['taken'] > 0 ? 'bg-gold/50 text-black/75' : 'bg-white/[0.06] text-cream/35') }}">
                        <span>{{ (int) substr($d['date'], 8, 2) }}/{{ (int) substr($d['date'], 5, 2) }}</span>
                        <span class="font-bold">{{ $d['taken'] }}/{{ $d['cap'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Who booked what — the part the public calendar deliberately omits --}}
        <div class="nx-card mt-4 p-5">
            <div class="mb-3 text-[15px] font-bold">รายการจอง ({{ $bookings->count() }})</div>
            @if ($bookings->isEmpty())
                <div class="py-6 text-center text-[13px] text-cream/45">ยังไม่มีใครจองตำแหน่งนี้</div>
            @else
                <div class="space-y-2">
                    @foreach ($bookings as $b)
                        <div class="flex flex-wrap items-center gap-3 rounded-lg border border-white/5 bg-white/[0.02] p-3">
                            @if ($b->image_src)
                                <img src="{{ $b->image_src }}" alt="" class="h-10 w-24 shrink-0 rounded object-cover ring-1 ring-white/10">
                            @endif
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2 text-[13.5px]">
                                    <b>{{ $b->user?->name ?? '—' }}</b>
                                    <span class="text-cream/45">{{ $b->reference }}</span>
                                    <span class="rounded-full px-2 py-0.5 text-[11px]
                                        @class([
                                            'bg-success/15 text-success' => $b->status === 'approved',
                                            'bg-gold/15 text-gold' => in_array($b->status, ['paid','awaiting_payment']),
                                        ])">{{ $b->status_label }}</span>
                                </div>
                                <div class="mt-0.5 text-[12px] text-cream/45">
                                    {{ $b->starts_at?->format('d/m/y') }} – {{ $b->ends_at?->format('d/m/y') }}
                                    ({{ $b->days }} วัน) · ${{ number_format((float) $b->price_usdt, 2) }}
                                    · เห็น {{ number_format($b->impressions) }} · คลิก {{ number_format($b->clicks) }}
                                </div>
                            </div>
                            <a href="{{ route('admin.ad-market.review') }}" class="shrink-0 text-[12.5px] text-cream/55 hover:text-cream">ตรวจ →</a>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
@endif
@endsection
