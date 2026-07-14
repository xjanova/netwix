@extends('layouts.app')
@section('title', 'บัญชีของฉัน')

@section('content')
@php
    $code = $state['referral_code'];
    $refUrl = url('/register?ref='.$code);
    $shareText = 'มาดูหนังและซีรีส์กับ NetWix — ใช้โค้ดแนะนำ '.$code.' รับ Pro ฟรี! '.$refUrl;
    $ref = $cfg['referral'];
    $proUntil = $state['pro_until'] ? \Illuminate\Support\Carbon::parse($state['pro_until']) : null;
@endphp

<div class="mx-auto max-w-3xl px-4 pb-16 pt-24">
    <h1 class="text-2xl font-bold sm:text-3xl">บัญชีของฉัน</h1>

    @if (session('status'))
        <div class="mt-4 rounded-xl border border-success/30 bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="mt-4 rounded-xl border border-[#e5484d]/30 bg-[#e5484d]/10 px-4 py-3 text-sm text-[#ff6b81]">{{ session('error') }}</div>
    @endif

    {{-- Pro + coins --}}
    <div class="mt-6 grid gap-4 sm:grid-cols-2">
        <div class="nx-card p-5">
            <div class="text-[13px] text-cream/50">สถานะสมาชิก</div>
            @if ($state['is_pro'])
                <div class="mt-1 flex items-center gap-2 text-xl font-bold"><span class="nx-gradient-text">PRO</span> <span class="text-success">● ใช้งานอยู่</span></div>
                @if ($proUntil)
                    <div class="mt-1 text-[13px] text-cream/55">หมดอายุ {{ $proUntil->format('d/m/Y') }} ({{ (int) now()->diffInDays($proUntil, false) }} วัน)</div>
                @else
                    <div class="mt-1 text-[13px] text-cream/55">แพ็กเกจ {{ strtoupper($state['plan']) }}</div>
                @endif
            @else
                <div class="mt-1 text-xl font-bold text-cream/80">ฟรี</div>
                <div class="mt-1 text-[13px] text-cream/55">ชวนเพื่อนด้วยโค้ดด้านล่างเพื่อรับ Pro ฟรี</div>
            @endif
        </div>
        <div class="nx-card p-5">
            <div class="text-[13px] text-cream/50">เหรียญเงิน</div>
            <div class="mt-1 flex items-center gap-2 text-2xl font-extrabold" style="color:#d6dce6">
                <svg class="h-6 w-6" viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="12" cy="12" r="10" fill="#c8cfda"/>
                    <circle cx="12" cy="12" r="10" fill="none" stroke="#9aa4b4" stroke-width="1.3"/>
                    <circle cx="12" cy="12" r="6.3" fill="none" stroke="#eef2f7" stroke-width="1.4" opacity="0.75"/>
                </svg>
                {{ number_format($state['coins']) }}
            </div>
            <div class="mt-1 text-[13px] text-cream/55">ได้จากภารกิจ — ใช้ปลดล็อกตอน</div>
        </div>
        <div class="nx-card p-5">
            <div class="text-[13px] text-cream/50">เหรียญทอง</div>
            <div class="mt-1 flex items-center gap-2 text-2xl font-extrabold" style="color:#ffd76a">👑 {{ number_format($state['gold_coins']) }}</div>
            <div class="mt-1 text-[13px] text-cream/55">ใช้ดูโซน VIP และซื้อ Pro</div>
        </div>
    </div>

    {{-- ============ เติมเหรียญทอง / สมาชิก (USDT) + แปลงเหรียญ ============ --}}
    @php
        $g = $cfg['gold'];
        $usd = $cfg['usdt'];
        $walletCfg = [
            'orderUrl' => route('account.usdt.order'),
            'statusUrl' => route('account.usdt.status', ['order' => '__REF__']),
            'convertUrl' => route('account.gold.convert'),
            'buyProGoldUrl' => route('account.pro.buy-gold'),
            'min_usdt' => (float) ($g['min_usdt'] ?? 1),
            'per_usdt' => (float) ($g['per_usdt'] ?? 0),
            'convert_rate' => (int) ($g['convert_rate'] ?? 100),
            'convert_fee_pct' => (float) ($g['convert_fee_pct'] ?? 0),
        ];
    @endphp

    <div class="nx-card mt-4 p-6" x-data="nxWallet(@js($walletCfg))">
        <h2 class="text-lg font-bold">เติมเหรียญทอง / สมาชิก Pro ด้วย USDT</h2>

        @unless ($usdtEnabled)
            <p class="mt-2 rounded-xl border border-white/10 bg-white/[0.03] px-4 py-3 text-[13.5px] text-cream/55">ยังไม่เปิดรับชำระ USDT ในตอนนี้ — ลองใหม่อีกครั้งภายหลัง</p>
        @else
            <p class="mt-1 text-[13px] text-cream/55">โอน USDT บนเครือข่าย <span class="font-semibold text-cream/80">BEP20 (BSC)</span> — ระบบตรวจสอบยอดจากเชนจริงและเติมให้อัตโนมัติ</p>

            {{-- create order --}}
            <div x-show="!order" class="mt-4">
                <div class="mb-4 inline-flex rounded-xl bg-surface-2 p-1 text-sm">
                    <button type="button" @click="tab='gold'" :class="tab==='gold' ? 'bg-brand text-white' : 'text-cream/60'" class="rounded-lg px-4 py-1.5 font-semibold transition">เติมเหรียญทอง</button>
                    <button type="button" @click="tab='pro'" :class="tab==='pro' ? 'bg-brand text-white' : 'text-cream/60'" class="rounded-lg px-4 py-1.5 font-semibold transition">ซื้อ Pro</button>
                </div>

                {{-- gold --}}
                <div x-show="tab==='gold'" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <label class="flex-1 text-[13px] text-cream/60">จำนวน USDT
                        <input type="number" x-model="usdtAmount" min="{{ $walletCfg['min_usdt'] }}" step="0.01"
                               class="mt-1 w-full rounded-lg border border-white/10 bg-surface-2 px-4 py-3 text-sm outline-none focus:border-brand">
                        <span class="mt-1 block text-[12px] text-cream/45">จะได้ <span class="font-bold" style="color:#ffd76a" x-text="goldPreview"></span> เหรียญทอง · ขั้นต่ำ {{ rtrim(rtrim(number_format($walletCfg['min_usdt'], 2), '0'), '.') }} USDT</span>
                    </label>
                    <button type="button" @click="createOrder('gold')" :disabled="creating || goldPreview < 1" class="btn-brand whitespace-nowrap px-6 py-3 disabled:opacity-50" x-text="creating ? 'กำลังสร้าง…' : 'สร้างรายการชำระ'"></button>
                </div>

                {{-- pro --}}
                <div x-show="tab==='pro'" class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="text-[13.5px] text-cream/70">สมาชิก Pro <span class="font-bold text-cream">{{ (int) ($usd['pro_days'] ?? 30) }} วัน</span> — ราคา <span class="font-bold text-gold">{{ rtrim(rtrim(number_format((float) ($usd['pro_price_usdt'] ?? 0), 2), '0'), '.') }} USDT</span></div>
                    <button type="button" @click="createOrder('pro')" :disabled="creating" class="btn-brand whitespace-nowrap px-6 py-3 disabled:opacity-50" x-text="creating ? 'กำลังสร้าง…' : 'ซื้อ Pro ด้วย USDT'"></button>
                </div>
            </div>

            {{-- order / pay --}}
            <div x-show="order" x-cloak class="mt-4">
                {{-- paid --}}
                <div x-show="done" class="rounded-xl border border-success/30 bg-success/10 p-6 text-center">
                    <div class="text-3xl">✅</div>
                    <div class="mt-2 text-lg font-bold text-success">ชำระเงินสำเร็จ!</div>
                    <div class="mt-1 text-[13px] text-cream/60">กำลังอัปเดตยอด…</div>
                </div>

                <div x-show="!done" class="grid gap-5 sm:grid-cols-[auto_1fr]">
                    <div class="flex flex-col items-center gap-2">
                        <canvas x-ref="qr" width="190" height="190" class="rounded-xl bg-white p-2"></canvas>
                        <span class="text-[11px] text-cream/40">สแกนที่อยู่กระเป๋า</span>
                    </div>
                    <div class="min-w-0">
                        <div class="rounded-xl border border-gold/30 bg-gold/[0.06] px-4 py-3">
                            <div class="text-[12px] text-cream/55">โอนยอดนี้เป๊ะๆ (ยอดเฉพาะของรายการนี้)</div>
                            <div class="mt-0.5 flex items-center gap-2">
                                <span class="text-xl font-extrabold text-gold" x-text="order.amount_usdt + ' USDT'"></span>
                                <button type="button" @click="copy(order.amount_usdt, 'amt')" class="rounded bg-white/10 px-2 py-1 text-[11px] hover:bg-white/15"><span x-text="copied==='amt' ? '✓' : 'คัดลอก'"></span></button>
                            </div>
                            <div class="mt-0.5 text-[11px] text-[#ffb84d]">ต้องตรงทุกหลัก ระบบจึงจับคู่รายการได้ · เครือข่าย <span x-text="order.network"></span></div>
                        </div>

                        <div class="mt-3 text-[12px] text-cream/55">ที่อยู่กระเป๋า (BEP20)</div>
                        <div class="mt-1 flex items-center gap-2 rounded-lg border border-white/10 bg-surface-2 px-3 py-2">
                            <span class="min-w-0 flex-1 break-all font-mono text-[12px]" x-text="order.wallet"></span>
                            <button type="button" @click="copy(order.wallet, 'addr')" class="shrink-0 rounded bg-white/10 px-2 py-1 text-[11px] hover:bg-white/15"><span x-text="copied==='addr' ? '✓' : 'คัดลอก'"></span></button>
                        </div>

                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <button type="button" @click="check(true)" :disabled="checking" class="btn-brand px-5 py-2 text-sm disabled:opacity-50" x-text="checking ? 'กำลังตรวจสอบ…' : 'ตรวจสอบเดี๋ยวนี้'"></button>
                            <button type="button" @click="cancelOrder()" class="rounded-lg border border-white/15 px-4 py-2 text-sm hover:bg-white/5">ยกเลิก</button>
                            <span class="text-[12px] text-cream/45">ระบบตรวจอัตโนมัติทุกนาที · รหัส <span class="font-mono" x-text="order.reference"></span></span>
                        </div>
                    </div>
                </div>
            </div>

            <p x-show="error" x-cloak class="mt-3 text-[13px] text-[#ff6b81]" x-text="error"></p>
        @endunless

        {{-- convert silver → gold --}}
        @if ($g['convert_enabled'] ?? false)
            <div class="mt-6 border-t border-white/[0.06] pt-5">
                <h3 class="text-base font-bold">แปลงเหรียญเงิน → ทอง</h3>
                <p class="mt-1 text-[13px] text-cream/55">
                    อัตรา {{ (int) $g['convert_rate'] }} เงิน = 1 ทอง
                    @if (($g['convert_fee_pct'] ?? 0) > 0) · ค่าธรรมเนียม {{ rtrim(rtrim(number_format((float) $g['convert_fee_pct'], 2), '0'), '.') }}% @endif
                    @if (($g['convert_daily_cap'] ?? 0) > 0) · วันนี้แปลงได้อีก {{ (int) $gold['convert_remaining_today'] }} ทอง @endif
                </p>
                <div class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-end">
                    <label class="flex-1 text-[13px] text-cream/60">จำนวนเหรียญทองที่ต้องการ
                        <input type="number" x-model="convertGold" min="1" step="1"
                               class="mt-1 w-full rounded-lg border border-white/10 bg-surface-2 px-4 py-3 text-sm outline-none focus:border-brand">
                        <span class="mt-1 block text-[12px] text-cream/45">ใช้เหรียญเงิน <span class="font-bold text-cream" x-text="silverForConvert"></span> (มี {{ number_format($state['coins']) }})</span>
                    </label>
                    <button type="button" @click="convert()" :disabled="converting" class="rounded-lg bg-white/10 px-6 py-3 text-sm font-semibold hover:bg-white/15 disabled:opacity-50" x-text="converting ? 'กำลังแปลง…' : 'แปลงเป็นทอง'"></button>
                </div>
                <p x-show="convertMsg" x-cloak class="mt-2 text-[13px] text-success" x-text="convertMsg"></p>
                <p x-show="convertErr" x-cloak class="mt-2 text-[13px] text-[#ff6b81]" x-text="convertErr"></p>
            </div>
        @endif

        {{-- buy Pro with gold --}}
        @if (($usd['buy_pro_gold'] ?? 0) > 0 && ! $state['is_pro'])
            <div class="mt-6 border-t border-white/[0.06] pt-5">
                <h3 class="text-base font-bold">ซื้อ Pro ด้วยเหรียญทอง</h3>
                <div class="mt-2 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="text-[13.5px] text-cream/70">Pro {{ (int) ($usd['pro_days'] ?? 30) }} วัน — <span class="font-bold" style="color:#ffd76a">👑 {{ number_format((int) $usd['buy_pro_gold']) }} ทอง</span></div>
                    <button type="button" @click="buyProGold()" :disabled="buyingPro" class="btn-brand px-6 py-2.5 disabled:opacity-50" x-text="buyingPro ? 'กำลังดำเนินการ…' : 'ใช้เหรียญทองซื้อ Pro'"></button>
                </div>
            </div>
        @endif
    </div>

    {{-- Referral code + share --}}
    <div class="nx-card mt-4 p-6"
         x-data="{ copied: '', copy(t, tag) { navigator.clipboard.writeText(t).then(() => { this.copied = tag; setTimeout(() => this.copied = '', 1500); }); } }">
        <h2 class="text-lg font-bold">โค้ดแนะนำของคุณ</h2>
        <p class="mt-1 text-[13.5px] text-cream/60">
            เพื่อนใช้โค้ดนี้ตอนสมัคร → เพื่อนได้ Pro ฟรี {{ $ref['referee_pro_days'] }} วัน
            @if ($ref['referrer_pro_days'] > 0 || $ref['referrer_coins'] > 0)
                · คุณได้{{ $ref['referrer_pro_days'] > 0 ? ' Pro +'.$ref['referrer_pro_days'].' วัน' : '' }}{{ $ref['referrer_coins'] > 0 ? ' +'.$ref['referrer_coins'].' เหรียญเงิน' : '' }} ต่อ 1 คน
            @endif
        </p>

        <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center">
            <div class="flex flex-1 items-center justify-between gap-3 rounded-xl border border-white/10 bg-surface-2 px-4 py-3">
                <span class="text-2xl font-extrabold tracking-[0.2em]">{{ $code }}</span>
                <button type="button" @click="copy('{{ $code }}', 'code')" class="rounded-lg bg-white/10 px-3 py-1.5 text-xs hover:bg-white/15">
                    <span x-text="copied === 'code' ? 'คัดลอกแล้ว ✓' : 'คัดลอกโค้ด'"></span>
                </button>
            </div>
        </div>

        {{-- share --}}
        <div class="mt-4 flex flex-wrap items-center gap-2.5">
            <span class="text-[13px] text-cream/50">แชร์:</span>
            <a href="https://social-plugins.line.me/lineit/share?url={{ urlencode($refUrl) }}" target="_blank" rel="noopener"
               class="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-white" style="background:#06C755">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M12 3C6.48 3 2 6.63 2 11.02c0 3.93 3.32 7.22 7.8 7.85.3.06.72.2.82.46.09.24.06.6.03.85l-.13.79c-.04.24-.19.94.83.51 1.02-.43 5.5-3.24 7.5-5.55C20.5 14.42 22 12.86 22 11.02 22 6.63 17.52 3 12 3z"/></svg>
                LINE
            </a>
            <a href="https://www.facebook.com/sharer/sharer.php?u={{ urlencode($refUrl) }}" target="_blank" rel="noopener"
               class="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-white" style="background:#1877F2">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.49-3.89 3.78-3.89 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.44 2.89h-2.34v6.99A10 10 0 0 0 22 12z"/></svg>
                Facebook
            </a>
            <button type="button" @click="copy('{{ $shareText }}', 'link')"
                    class="flex items-center gap-2 rounded-lg border border-white/15 px-4 py-2 text-sm hover:bg-white/5">
                <span x-text="copied === 'link' ? 'คัดลอกลิงก์แล้ว ✓' : 'คัดลอกลิงก์ชวนเพื่อน'"></span>
            </button>
        </div>

        @if ($state['referrals_count'] > 0)
            <p class="mt-4 text-[13px] text-cream/55">มีเพื่อนใช้โค้ดของคุณแล้ว <span class="font-bold text-cream">{{ $state['referrals_count'] }}</span> คน 🎉</p>
        @endif
    </div>

    {{-- Redeem a friend's code --}}
    @unless ($state['referred'])
        <div class="nx-card mt-4 p-6">
            <h2 class="text-lg font-bold">มีโค้ดเพื่อน? กรอกเพื่อรับ Pro ฟรี</h2>
            <p class="mt-1 text-[13.5px] text-cream/60">กรอกโค้ดแนะนำได้ครั้งเดียว รับ Pro ฟรี {{ $ref['referee_pro_days'] }} วันทันที</p>
            <form method="POST" action="{{ route('account.redeem') }}" class="mt-4 flex flex-col gap-2 sm:flex-row">
                @csrf
                <input name="code" value="{{ old('code') }}" placeholder="กรอกโค้ดเพื่อน เช่น ABCDE12" maxlength="16"
                       class="flex-1 rounded-lg border border-white/10 bg-surface-2 px-4 py-3 text-sm uppercase tracking-widest outline-none focus:border-brand" required>
                <button class="btn-brand whitespace-nowrap px-8 py-3">ใช้โค้ด</button>
            </form>
            @error('code')<div class="mt-2 text-[13px] text-[#ff6b81]">{{ $message }}</div>@enderror
        </div>
    @else
        <div class="mt-4 rounded-xl border border-white/[0.06] bg-white/[0.02] px-5 py-4 text-[13px] text-cream/50">คุณใช้โค้ดแนะนำไปแล้ว ✓</div>
    @endunless
</div>
@endsection
