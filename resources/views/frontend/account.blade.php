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
            <div class="text-[13px] text-cream/50">เหรียญของฉัน</div>
            <div class="mt-1 flex items-center gap-2 text-2xl font-extrabold text-gold">🪙 {{ number_format($state['coins']) }}</div>
            <div class="mt-1 text-[13px] text-cream/55">ใช้ปลดล็อกตอน หรือรับเพิ่มจากกิจกรรมในแอป</div>
        </div>
    </div>

    {{-- Referral code + share --}}
    <div class="nx-card mt-4 p-6"
         x-data="{ copied: '', copy(t, tag) { navigator.clipboard.writeText(t).then(() => { this.copied = tag; setTimeout(() => this.copied = '', 1500); }); } }">
        <h2 class="text-lg font-bold">โค้ดแนะนำของคุณ</h2>
        <p class="mt-1 text-[13.5px] text-cream/60">
            เพื่อนใช้โค้ดนี้ตอนสมัคร → เพื่อนได้ Pro ฟรี {{ $ref['referee_pro_days'] }} วัน
            @if ($ref['referrer_pro_days'] > 0 || $ref['referrer_coins'] > 0)
                · คุณได้{{ $ref['referrer_pro_days'] > 0 ? ' Pro +'.$ref['referrer_pro_days'].' วัน' : '' }}{{ $ref['referrer_coins'] > 0 ? ' 🪙'.$ref['referrer_coins'] : '' }} ต่อ 1 คน
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
