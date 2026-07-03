@extends('layouts.admin')
@section('page-title', 'สมาชิก / โปรโมชัน')
@section('page-subtitle', 'ตั้งกติกาโค้ดแนะนำ, Pro, เหรียญ และการดูฟรี — ใช้ทั้งเว็บและแอป')
@section('action')<span></span>@endsection

@section('content')
@php
    $ref = $cfg['referral'];
    $pro = $cfg['pro'];
    $earn = $cfg['earn'];
    // reusable number field
    $num = fn ($name, $label, $value, $hint = null) => view('admin.membership._num', compact('name', 'label', 'value', 'hint'));
@endphp

<form method="POST" action="{{ route('admin.membership.update') }}" class="mx-auto flex max-w-3xl flex-col gap-6">
    @csrf @method('PUT')

    {{-- ============ REFERRAL PROMO ============ --}}
    <div class="nx-card p-6">
        <div class="mb-1 flex items-center justify-between">
            <h3 class="text-base font-bold">โค้ดแนะนำ → รางวัล</h3>
            <label class="flex cursor-pointer items-center gap-2 text-sm text-cream/70">
                <input type="checkbox" name="referral_enabled" value="1" @checked($ref['enabled']) class="h-4 w-4 accent-brand"> เปิดใช้งาน
            </label>
        </div>
        <p class="mb-4 text-[13px] text-cream/50">เมื่อสมาชิกใหม่กรอกโค้ดของเพื่อน ทั้งสองฝ่ายจะได้รางวัลตามนี้</p>
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="rounded-xl border border-white/[0.06] p-4">
                <div class="mb-2 text-sm font-semibold text-cream/80">คนที่ใช้โค้ด (เพื่อนใหม่)</div>
                {!! $num('referee_pro_days', 'Pro ฟรี (วัน)', $ref['referee_pro_days'], '0 = ไม่ให้ Pro') !!}
                {!! $num('referee_coins', 'เหรียญโบนัส', $ref['referee_coins']) !!}
            </div>
            <div class="rounded-xl border border-white/[0.06] p-4">
                <div class="mb-2 text-sm font-semibold text-cream/80">คนแนะนำ (เจ้าของโค้ด) ต่อ 1 คน</div>
                {!! $num('referrer_pro_days', 'Pro เพิ่ม (วัน)', $ref['referrer_pro_days']) !!}
                {!! $num('referrer_coins', 'เหรียญ', $ref['referrer_coins']) !!}
            </div>
        </div>
        <div class="mt-4">
            {!! $num('max_referrals', 'จำกัดจำนวนคนที่ใช้โค้ดเดียวได้', $ref['max_referrals'], '0 = ไม่จำกัด') !!}
        </div>
    </div>

    {{-- ============ FREE / UNLOCK ============ --}}
    <div class="nx-card p-6">
        <h3 class="mb-1 text-base font-bold">ดูฟรี & ปลดล็อกด้วยเหรียญ</h3>
        <p class="mb-4 text-[13px] text-cream/50">กติกาที่แอปใช้ล็อกตอน (เว็บส่งค่าให้ แอปอ่านไปบังคับใช้)</p>
        <div class="grid gap-4 sm:grid-cols-3">
            {!! $num('free_episodes', 'ดูฟรีกี่ตอนแรก', $cfg['free_episodes']) !!}
            {!! $num('unlock_cost_coins', 'ปลดล็อก 1 ตอน (เหรียญ)', $cfg['unlock_cost_coins']) !!}
            {!! $num('signup_bonus_coins', 'โบนัสสมัครใหม่ (เหรียญ)', $cfg['signup_bonus_coins']) !!}
        </div>
    </div>

    {{-- ============ PRO ============ --}}
    <div class="nx-card p-6">
        <h3 class="mb-1 text-base font-bold">สิทธิ์ Pro</h3>
        <p class="mb-4 text-[13px] text-cream/50">Pro ได้จากการเติมเงิน หรือจากโค้ดแนะนำ/โปรโมชัน</p>
        <div class="grid gap-4 sm:grid-cols-3">
            {!! $num('pro_price_thb', 'ราคา Pro (บาท/เดือน)', $pro['price_thb']) !!}
            <label class="flex items-end gap-2 pb-2.5 text-sm text-cream/70">
                <input type="checkbox" name="pro_removes_ads" value="1" @checked($pro['removes_ads']) class="h-4 w-4 accent-brand"> ไม่มีโฆษณา
            </label>
            <label class="flex items-end gap-2 pb-2.5 text-sm text-cream/70">
                <input type="checkbox" name="pro_unlocks_all" value="1" @checked($pro['unlocks_all']) class="h-4 w-4 accent-brand"> ปลดล็อกทุกตอน
            </label>
        </div>
    </div>

    {{-- ============ EARN COINS ============ --}}
    <div class="nx-card p-6">
        <h3 class="mb-1 text-base font-bold">รับเหรียญจากกิจกรรม</h3>
        <p class="mb-4 text-[13px] text-cream/50">แอปเรียกใช้ผ่าน API เพื่อให้เหรียญ (เช็คอิน / ดูคลิปรับรางวัล)</p>
        <div class="grid gap-4 sm:grid-cols-3">
            {!! $num('daily_checkin_coins', 'เช็คอินรายวัน', $earn['daily_checkin_coins']) !!}
            {!! $num('watch_reward_coins', 'ดูคลิปรับรางวัล (ต่อครั้ง)', $earn['watch_reward_coins']) !!}
            {!! $num('watch_reward_daily_cap', 'จำกัดรับรางวัล/วัน', $earn['watch_reward_daily_cap']) !!}
        </div>
    </div>

    <div class="flex items-center justify-between rounded-xl border border-white/5 bg-white/[0.02] px-5 py-4">
        <p class="text-[12.5px] text-cream/45">กติกานี้เก็บที่เว็บ — แอปดึงผ่าน <code class="text-cream/70">/api/app/membership/config</code></p>
        <button class="btn-brand px-8 py-2.5">บันทึกกติกา</button>
    </div>
</form>
@endsection
