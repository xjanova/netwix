@extends('layouts.admin')
@section('page-title', 'ตรวจอนุมัติโฆษณา')
@section('page-subtitle', 'ลูกค้าจ่ายเงินแล้ว แต่ยังไม่ขึ้นแสดงจนกว่าจะอนุมัติ — ไม่ผ่านไม่คืนเงิน ให้แก้แล้วส่งใหม่')

@section('content')
@if (session('status'))
    <div class="mb-5 rounded-xl border border-success/30 bg-success/10 px-4 py-3 text-[13px] text-success">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="mb-5 rounded-xl border border-brand/40 bg-brand/10 px-4 py-3 text-[13px] text-brand">{{ $errors->first() }}</div>
@endif

<a href="{{ route('admin.ad-market.index') }}" class="text-[13px] text-cream/50 hover:text-cream">← กลับไปตั้งค่าตลาดโฆษณา</a>

@if ($bookings->total() === 0)
    <div class="nx-card mt-4 p-10 text-center text-cream/55">
        <div class="text-4xl">✅</div>
        <div class="mt-3">ไม่มีโฆษณารอตรวจ</div>
    </div>
@else
    <div class="mt-4 space-y-3">
        @foreach ($bookings as $b)
            <div class="nx-card p-4 {{ $b->status === 'paid' ? 'ring-1 ring-gold/30' : '' }}">
                <div class="flex flex-wrap items-start gap-4">
                    @if ($b->image_src)
                        <a href="{{ $b->image_src }}" target="_blank" class="shrink-0">
                            <img src="{{ $b->image_src }}" alt="" class="h-20 w-52 rounded-lg object-cover ring-1 ring-white/10" style="background:#1a1420">
                        </a>
                    @endif

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold">{{ $b->title ?: $b->reference }}</span>
                            <span class="rounded-full bg-white/5 px-2 py-0.5 text-[11px] text-cream/50">{{ $b->reference }}</span>
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold
                                @class([
                                    'bg-gold/15 text-gold' => $b->status === 'paid',
                                    'bg-success/15 text-success' => $b->status === 'approved',
                                    'bg-brand/15 text-brand' => $b->status === 'rejected',
                                ])">{{ $b->status_label }}</span>
                        </div>

                        <div class="mt-1 text-[12.5px] text-cream/50">
                            ลูกค้า: <b class="text-cream/75">{{ $b->user?->name ?? '—' }}</b> ({{ $b->user?->email }})
                            · {{ $b->placement?->name }}
                            · {{ $b->days }} วัน ({{ $b->starts_at?->format('d/m/y') }}–{{ $b->ends_at?->format('d/m/y') }})
                            · <b class="text-cream/75">${{ number_format((float) $b->price_usdt, 2) }}</b>
                        </div>

                        <div class="mt-2 rounded-lg border border-white/5 bg-black/20 p-2.5 text-[12.5px]">
                            <div>ลิงก์ที่แจ้ง: <a href="{{ $b->link_url }}" target="_blank" rel="noopener nofollow" class="text-cream/80 underline break-all">{{ $b->link_url }}</a></div>
                            @if ($b->link_final_url && $b->link_final_url !== $b->link_url)
                                <div class="mt-0.5 text-gold/90">ปลายทางจริง: <span class="break-all">{{ $b->link_final_url }}</span></div>
                            @endif
                            @if (!empty($b->screen_result))
                                <div class="mt-1 text-[11.5px] text-cream/40">
                                    ระบบตรวจอัตโนมัติ: ผ่าน · เด้ง {{ $b->screen_result['hops'] ?? 0 }} ทอด
                                    · {{ \Illuminate\Support\Carbon::parse($b->screen_result['checked_at'] ?? now())->format('d/m/y H:i') }}
                                </div>
                            @endif
                        </div>

                        @if ($b->status === 'rejected' && $b->review_note)
                            <div class="mt-2 text-[12.5px] text-brand">เหตุผลที่ปฏิเสธ: {{ $b->review_note }}</div>
                        @endif
                    </div>
                </div>

                @if (in_array($b->status, ['paid', 'rejected'], true))
                    <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-white/5 pt-3">
                        <form method="POST" action="{{ route('admin.ad-market.approve', $b) }}">@csrf
                            <button class="rounded-lg bg-success/15 px-4 py-2 text-[13px] font-semibold text-success hover:bg-success/25">✓ อนุมัติ</button>
                        </form>
                        <form method="POST" action="{{ route('admin.ad-market.reject', $b) }}" class="flex flex-1 flex-wrap gap-2">@csrf
                            <input type="text" name="review_note" required minlength="5" maxlength="500"
                                   placeholder="เหตุผลที่ไม่ผ่าน (ลูกค้าจะเห็นข้อความนี้ เพื่อแก้ให้ถูกจุด)"
                                   class="min-w-[240px] flex-1 rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-[13px]">
                            <button class="rounded-lg bg-brand/15 px-4 py-2 text-[13px] font-semibold text-brand hover:bg-brand/25">✕ ไม่ผ่าน</button>
                        </form>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    <div class="mt-5">{{ $bookings->links() }}</div>
@endif
@endsection
