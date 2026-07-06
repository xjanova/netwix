@extends('layouts.admin')
@section('page-title', 'เหรียญทอง / ชำระ USDT')
@section('page-subtitle', 'ราคาเหรียญทอง, การแปลงเงิน→ทอง, โซน VIP และการรับเงิน USDT บน BSC จริง')
@section('action')<span></span>@endsection

@section('content')
@php
    $g = $cfg['gold'];
    $vip = $cfg['vip'];
    $u = $cfg['usdt'];
    $num = fn ($name, $label, $value, $hint = null) => view('admin.membership._num', compact('name', 'label', 'value', 'hint'));
    $dec = function ($name, $label, $value, $step, $hint = null) {
        return '<label class="block text-[13px] text-cream/60">'.e($label).
            '<input type="number" name="'.e($name).'" value="'.e(old($name, $value)).'" min="0" step="'.e($step).'"'.
            ' class="mt-1 w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-sm outline-none focus:border-brand">'.
            ($hint ? '<span class="mt-1 block text-[11px] text-cream/35">'.e($hint).'</span>' : '').'</label>';
    };
@endphp

<form method="POST" action="{{ route('admin.payments.update') }}" class="mx-auto flex max-w-3xl flex-col gap-6">
    @csrf @method('PUT')

    {{-- ============ GOLD PRICE ============ --}}
    <div class="nx-card p-6">
        <h3 class="mb-1 text-base font-bold">🪙 ราคาเหรียญทอง (ซื้อด้วย USDT)</h3>
        <p class="mb-4 text-[13px] text-cream/50">ลูกค้าโอน USDT แล้วได้เหรียญทองตามอัตรานี้ (เหรียญทองใช้ดูโซน VIP และซื้อ Pro)</p>
        <div class="grid gap-4 sm:grid-cols-2">
            {!! $num('per_usdt', 'เหรียญทองต่อ 1 USDT', $g['per_usdt'], 'เช่น 100 = โอน 1 USDT ได้ 100 ทอง') !!}
            {!! $dec('min_usdt', 'ยอดเติมขั้นต่ำ (USDT)', $g['min_usdt'], '0.01') !!}
        </div>
    </div>

    {{-- ============ CONVERT ============ --}}
    <div class="nx-card p-6">
        <div class="mb-1 flex items-center justify-between">
            <h3 class="text-base font-bold">🔁 แปลงเหรียญเงิน → ทอง</h3>
            <label class="flex cursor-pointer items-center gap-2 text-sm text-cream/70">
                <input type="checkbox" name="convert_enabled" value="1" @checked($g['convert_enabled']) class="h-4 w-4 accent-brand"> เปิดใช้งาน
            </label>
        </div>
        <p class="mb-4 text-[13px] text-cream/50">เหรียญเงินได้จากภารกิจ — แปลงเป็นทองได้ตามเงื่อนไข (จำกัดต่อวันเพื่อกันการฟาร์ม)</p>
        <div class="grid gap-4 sm:grid-cols-3">
            {!! $num('convert_rate', 'เงินต่อ 1 ทอง', $g['convert_rate'], 'เช่น 100 เงิน = 1 ทอง') !!}
            {!! $dec('convert_fee_pct', 'ค่าธรรมเนียม (%)', $g['convert_fee_pct'], '0.1', 'คิดเพิ่มจากเงินที่ใช้') !!}
            {!! $num('convert_daily_cap', 'เพดานทอง/วัน', $g['convert_daily_cap'], '0 = ไม่จำกัด') !!}
        </div>
    </div>

    {{-- ============ VIP ============ --}}
    <div class="nx-card p-6">
        <h3 class="mb-1 text-base font-bold">⭐ โซน VIP</h3>
        <p class="mb-4 text-[13px] text-cream/50">ราคากลางในการปลดล็อกเรื่องในโซน VIP ด้วยเหรียญทอง (ตั้งราคาต่อเรื่องได้ที่หน้าแก้ไขคอนเทนต์)</p>
        <div class="grid items-end gap-4 sm:grid-cols-2">
            {!! $num('vip_unlock_cost_gold', 'ปลดล็อก VIP 1 เรื่อง (ทอง)', $vip['unlock_cost_gold']) !!}
            <label class="flex items-center gap-2 pb-2.5 text-sm text-cream/70">
                <input type="checkbox" name="vip_pro_unlocks" value="1" @checked($vip['pro_unlocks']) class="h-4 w-4 accent-brand"> สมาชิก Pro ดูโซน VIP ได้ฟรี
            </label>
        </div>
    </div>

    {{-- ============ USDT PAYMENT ============ --}}
    <div class="nx-card p-6">
        <div class="mb-1 flex items-center justify-between">
            <h3 class="text-base font-bold">💵 รับเงิน USDT (BEP20 / BSC)</h3>
            <label class="flex cursor-pointer items-center gap-2 text-sm text-cream/70">
                <input type="checkbox" name="usdt_enabled" value="1" @checked($u['enabled']) class="h-4 w-4 accent-brand"> เปิดรับชำระ
            </label>
        </div>
        <p class="mb-4 text-[13px] text-cream/50">ระบบรับเงินอย่างเดียว (ไม่เก็บ private key) — เฝ้าเชนอัตโนมัติทุกนาที + ปุ่มตรวจสอบทันทีบนหน้าเติมเงิน</p>

        <label class="block text-[13px] text-cream/60">ที่อยู่กระเป๋ารับเงิน (BSC) *
            <input name="usdt_wallet_address" value="{{ old('usdt_wallet_address', $wallet) }}" placeholder="0x…" spellcheck="false"
                   class="mt-1 w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 font-mono text-[13px] outline-none focus:border-brand">
            <span class="mt-1 block text-[11px] text-cream/35">ต้องรองรับ BEP20 (BSC) — ตรวจให้แน่ใจว่าถูกต้อง เงินที่โอนผิดที่อยู่กู้คืนไม่ได้</span>
        </label>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
                <label class="block text-[13px] text-cream/60">BscScan API Key
                    @if ($hasApiKey)<span class="ml-1 rounded bg-success/15 px-1.5 py-0.5 text-[10px] text-success">ตั้งค่าแล้ว</span>@endif
                    <input name="bscscan_api_key" type="password" autocomplete="new-password" placeholder="{{ $hasApiKey ? '•••••• (เว้นว่าง = ใช้ค่าเดิม)' : 'วางคีย์ที่นี่' }}"
                           class="mt-1 w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-sm outline-none focus:border-brand">
                </label>
                @if ($hasApiKey)
                    <label class="mt-1.5 flex items-center gap-1.5 text-[11px] text-cream/45">
                        <input type="checkbox" name="bscscan_api_key_clear" value="1" class="h-3.5 w-3.5 accent-brand"> ล้างค่าคีย์เดิม
                    </label>
                @endif
            </div>
            <label class="block text-[13px] text-cream/60">BscScan API Base
                <input name="bscscan_api_base" value="{{ old('bscscan_api_base', $apiBase) }}" spellcheck="false"
                       class="mt-1 w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 font-mono text-[12px] outline-none focus:border-brand">
                <span class="mt-1 block text-[11px] text-cream/35">ปกติ https://api.bscscan.com/api</span>
            </label>
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <label class="block text-[13px] text-cream/60">ที่อยู่สัญญา USDT
                <input name="contract" value="{{ old('contract', $u['contract']) }}" spellcheck="false"
                       class="mt-1 w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 font-mono text-[12px] outline-none focus:border-brand">
                <span class="mt-1 block text-[11px] text-cream/35">USDT บน BSC = 0x55d3…7955 (18 decimals)</span>
            </label>
            {!! $num('decimals', 'ทศนิยมของโทเคน', $u['decimals'], 'USDT บน BSC = 18') !!}
            {!! $num('min_confirmations', 'ยืนยัน (confirmations) ก่อนเติม', $u['min_confirmations'], 'ยิ่งมากยิ่งปลอดภัย') !!}
            {!! $num('order_ttl_minutes', 'อายุออเดอร์ (นาที)', $u['order_ttl_minutes'], 'หมดเวลาแล้วยกเลิกอัตโนมัติ') !!}
        </div>
    </div>

    {{-- ============ PRO PRICING ============ --}}
    <div class="nx-card p-6">
        <h3 class="mb-1 text-base font-bold">👑 ซื้อสมาชิก Pro</h3>
        <p class="mb-4 text-[13px] text-cream/50">ซื้อ Pro ได้ทั้งด้วย USDT (โอนจริง) หรือด้วยเหรียญทอง</p>
        <div class="grid gap-4 sm:grid-cols-3">
            {!! $dec('usdt_pro_price_usdt', 'ราคา Pro (USDT)', $u['pro_price_usdt'], '0.01') !!}
            {!! $num('usdt_pro_days', 'ได้ Pro กี่วัน/ครั้ง', $u['pro_days']) !!}
            {!! $num('buy_pro_gold', 'ซื้อ Pro ด้วยทอง (0 = ปิด)', $u['buy_pro_gold']) !!}
        </div>
    </div>

    <div class="flex items-center justify-between rounded-xl border border-white/5 bg-white/[0.02] px-5 py-4">
        <p class="text-[12.5px] text-cream/45">แอปดึงกติกาผ่าน <code class="text-cream/70">/api/app/wallet</code> — เว็บเป็นแหล่งความจริง</p>
        <button class="btn-brand px-8 py-2.5">บันทึก</button>
    </div>
</form>

{{-- ============ RECENT ORDERS ============ --}}
<div class="mx-auto mt-8 max-w-3xl">
    <div class="mb-3 flex flex-wrap items-center gap-4 text-[13px] text-cream/60">
        <span>ชำระสำเร็จ <b class="text-success">{{ number_format($stats['paid']) }}</b></span>
        <span>รอชำระ <b class="text-cream">{{ number_format($stats['pending']) }}</b></span>
        <span>รับ USDT รวม <b class="text-gold">{{ number_format($stats['usdt_in'], 2) }}</b></span>
    </div>
    <div class="nx-card overflow-x-auto p-0">
        <table class="w-full text-left text-[13px]">
            <thead class="border-b border-white/10 text-cream/50">
                <tr>
                    <th class="px-4 py-3 font-medium">ออเดอร์</th>
                    <th class="px-4 py-3 font-medium">สมาชิก</th>
                    <th class="px-4 py-3 font-medium">รายการ</th>
                    <th class="px-4 py-3 font-medium">ยอด (USDT)</th>
                    <th class="px-4 py-3 font-medium">สถานะ</th>
                    <th class="px-4 py-3 font-medium">เมื่อ</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($orders as $o)
                    @php
                        $badge = match ($o->status) {
                            'paid' => 'bg-success/15 text-success',
                            'pending' => 'bg-white/10 text-cream/70',
                            default => 'bg-[#e5484d]/15 text-[#ff6b81]',
                        };
                    @endphp
                    <tr class="border-b border-white/[0.04]">
                        <td class="px-4 py-3 font-mono text-[12px]">
                            {{ $o->reference }}
                            @if ($o->tx_hash)
                                <a href="https://bscscan.com/tx/{{ $o->tx_hash }}" target="_blank" rel="noopener" class="ml-1 text-brand">tx↗</a>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-cream/70">{{ $o->user->name ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $o->purpose === 'pro' ? 'Pro '.$o->pro_days.' วัน' : number_format($o->credited_gold).' ทอง' }}</td>
                        <td class="px-4 py-3 font-mono">{{ number_format((float) $o->amount_usdt, 6) }}</td>
                        <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-[11px] {{ $badge }}">{{ $o->status }}</span></td>
                        <td class="px-4 py-3 text-cream/45">{{ $o->created_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-cream/40">ยังไม่มีออเดอร์</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
