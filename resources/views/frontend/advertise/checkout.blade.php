@extends('layouts.app')
@section('title', 'ชำระเงิน — '.$booking->reference)
@section('meta_robots', 'noindex,nofollow')

@section('content')
<div class="pt-16 pb-14">
    <div class="mx-auto max-w-2xl px-4"
         x-data="adPay({ url: '{{ route('advertise.status', $booking) }}', status: '{{ $booking->status }}' })" x-init="poll()">

        <h1 class="text-2xl font-extrabold">ชำระเงินด้วยคริปโต</h1>
        <div class="mt-1 text-[13.5px] text-cream/55">
            รหัสอ้างอิง <b class="text-cream/80">{{ $booking->reference }}</b> ·
            {{ $booking->placement?->name }} · {{ $booking->days }} วัน
            ({{ $booking->starts_at?->format('d/m/y') }}–{{ $booking->ends_at?->format('d/m/y') }})
        </div>

        {{-- Paid state --}}
        <div x-show="paid" x-cloak class="nx-card mt-5 border border-success/30 bg-success/5 p-6 text-center">
            <div class="text-4xl">✅</div>
            <div class="mt-2 text-lg font-bold text-success">ได้รับเงินแล้ว</div>
            <div class="mt-1 text-[13.5px] text-cream/65">
                โฆษณาของคุณเข้าคิวรอทีมงานตรวจอนุมัติแล้ว<br>
                เมื่ออนุมัติจะเริ่มแสดงตามวันที่คุณเลือก
            </div>
            <a href="{{ route('advertise.mine') }}" class="nx-gradient mt-4 inline-block rounded-lg px-5 py-2.5 text-[14px] font-bold text-white">ดูโฆษณาของฉัน</a>
        </div>

        {{-- Awaiting payment --}}
        <div x-show="!paid" x-cloak>
            <div class="nx-card mt-5 p-6">
                <div class="text-center">
                    <div class="text-[13px] text-cream/55">โอนให้ตรงจำนวนนี้เป๊ะ ๆ</div>
                    <div class="mt-1 text-3xl font-extrabold tracking-tight">{{ $pay['amount_usdt'] }}</div>
                    <div class="text-[13px] text-cream/50">USDT — เครือข่าย <b class="text-cream/75">{{ $pay['network'] }}</b></div>
                    <div class="mt-1 text-[12px] text-gold/90">
                        ต้องโอนยอดนี้ให้ตรงทุกทศนิยม ระบบใช้ยอดนี้ระบุว่าเป็นของคุณ
                    </div>
                </div>

                <div class="mx-auto mt-5 w-fit rounded-xl bg-white p-3">
                    <canvas id="nx-ad-qr" width="200" height="200"></canvas>
                </div>
                <div class="mt-2 text-center text-[12px] text-cream/40">สแกนเพื่อรับที่อยู่กระเป๋า</div>

                <div class="mt-4">
                    <div class="mb-1 text-[12.5px] text-cream/55">ที่อยู่กระเป๋า (BEP20)</div>
                    <div class="flex gap-2">
                        <input readonly value="{{ $pay['wallet'] }}" id="nx-ad-wallet"
                               class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2.5 font-mono text-[12.5px]">
                        <button type="button" @click="copy()" class="shrink-0 rounded-lg bg-white/10 px-4 text-[13px] hover:bg-white/15" x-text="copied ? 'คัดลอกแล้ว' : 'คัดลอก'">คัดลอก</button>
                    </div>
                </div>

                <div class="mt-4 rounded-lg border border-brand/30 bg-brand/5 p-3 text-[12.5px] leading-relaxed text-cream/70">
                    ⚠️ ส่งเฉพาะ <b class="text-cream">USDT/USDC บนเครือข่าย BEP20 (BSC)</b> เท่านั้น
                    ส่งผิดเครือข่ายหรือผิดเหรียญ เงินจะสูญและ<b class="text-brand">ไม่มีการคืนเงิน</b>
                </div>

                <div class="mt-4 flex items-center justify-between text-[12.5px] text-cream/45">
                    <span>ระบบตรวจสอบให้อัตโนมัติทุกนาที</span>
                    <button type="button" @click="check(true)" class="rounded-lg bg-white/10 px-3 py-1.5 hover:bg-white/15"
                            x-text="checking ? 'กำลังตรวจ…' : 'ตรวจสอบเดี๋ยวนี้'">ตรวจสอบเดี๋ยวนี้</button>
                </div>
            </div>

            <div class="mt-4 text-center text-[12px] text-cream/35">
                ถ้าปิดหน้านี้ไป กลับมาชำระต่อได้ที่ <a href="{{ route('advertise.mine') }}" class="underline hover:text-cream">โฆษณาของฉัน</a>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script type="module">
import QRCode from 'qrcode';
const el = document.getElementById('nx-ad-qr');
if (el) QRCode.toCanvas(el, @js($pay['qr']), { width: 200, margin: 1 }, () => {});
</script>
<script>
function adPay({ url, status }) {
    return {
        paid: status === 'paid' || status === 'approved',
        checking: false, copied: false, timer: null,
        copy() {
            const i = document.getElementById('nx-ad-wallet');
            i.select(); document.execCommand('copy');
            this.copied = true; setTimeout(() => this.copied = false, 1500);
        },
        // Poll gently: the watcher settles orders every minute anyway, so this is just so the page
        // updates itself while the buyer is still looking at it.
        poll() { if (!this.paid) this.timer = setInterval(() => this.check(false), 15000); },
        async check(manual) {
            if (this.checking) return;
            this.checking = true;
            try {
                const r = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const d = await r.json();
                if (d.paid) { this.paid = true; clearInterval(this.timer); }
            } catch (e) { /* transient — the next tick retries */ }
            this.checking = false;
        },
    };
}
</script>
@endpush
@endsection
