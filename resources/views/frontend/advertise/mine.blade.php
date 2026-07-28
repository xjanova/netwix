@extends('layouts.app')
@section('title', 'โฆษณาของฉัน')
@section('meta_robots', 'noindex,nofollow')

@section('content')
<div class="pt-16 pb-14">
    <div class="mx-auto max-w-4xl px-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-extrabold">โฆษณาของฉัน</h1>
            <a href="{{ route('advertise.index') }}" class="nx-gradient rounded-lg px-4 py-2.5 text-[13.5px] font-bold text-white">+ ลงโฆษณาใหม่</a>
        </div>

        @if ($bookings->total() === 0)
            <div class="nx-card mt-6 p-10 text-center text-cream/55">
                <div class="text-4xl">📢</div>
                <div class="mt-3">ยังไม่มีโฆษณา</div>
                <a href="{{ route('advertise.index') }}" class="mt-4 inline-block text-[13.5px] text-cream/70 underline">เริ่มลงโฆษณา</a>
            </div>
        @else
            <div class="mt-5 space-y-3">
                @foreach ($bookings as $b)
                    <div class="nx-card p-4">
                        <div class="flex flex-wrap items-start gap-4">
                            @if ($b->image_src)
                                <img src="{{ $b->image_src }}" alt="" class="h-14 w-36 shrink-0 rounded-lg object-cover ring-1 ring-white/10" style="background:#1a1420">
                            @endif
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold">{{ $b->title ?: $b->reference }}</span>
                                    <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold
                                        @class([
                                            'bg-success/15 text-success' => $b->status === 'approved',
                                            'bg-gold/15 text-gold' => in_array($b->status, ['paid', 'awaiting_payment']),
                                            'bg-brand/15 text-brand' => $b->status === 'rejected',
                                            'bg-white/5 text-cream/50' => in_array($b->status, ['draft', 'expired', 'finished']),
                                        ])">{{ $b->status_label }}</span>
                                </div>
                                <div class="mt-1 text-[12.5px] text-cream/45">
                                    {{ $b->placement?->name }} · {{ $b->days }} วัน
                                    ({{ $b->starts_at?->format('d/m/y') }}–{{ $b->ends_at?->format('d/m/y') }})
                                    · ${{ number_format((float) $b->price_usdt, 2) }}
                                    @if ($b->status === 'approved')
                                        · เห็นแล้ว {{ number_format($b->impressions) }} ครั้ง · คลิก {{ number_format($b->clicks) }}
                                    @endif
                                </div>
                                <div class="mt-1 truncate text-[12px] text-cream/35">{{ $b->link_url }}</div>

                                @if ($b->status === 'rejected' && $b->review_note)
                                    <div class="mt-2 rounded-lg border border-brand/30 bg-brand/5 p-3 text-[12.5px] text-cream/75">
                                        <b class="text-brand">เหตุผลที่ไม่ผ่าน:</b> {{ $b->review_note }}
                                        <a href="{{ route('advertise.edit', $b) }}"
                                           class="mt-2 inline-block rounded-lg bg-brand px-4 py-2 text-[13px] font-bold text-white hover:bg-brand/90">
                                            แก้ไขแล้วส่งตรวจใหม่ (ไม่มีค่าใช้จ่ายเพิ่ม)
                                        </a>
                                    </div>
                                @endif
                            </div>

                            @if ($b->status === 'awaiting_payment')
                                <a href="{{ route('advertise.checkout', $b) }}"
                                   class="nx-gradient shrink-0 rounded-lg px-4 py-2.5 text-[13px] font-bold text-white">ชำระเงิน</a>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-5">{{ $bookings->links() }}</div>
        @endif
    </div>
</div>
@endsection
