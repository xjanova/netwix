@extends('layouts.app')
@section('title', $content->title.' · โซน VIP')

@section('content')
<div class="relative flex min-h-[100dvh] items-center justify-center overflow-hidden px-4 pb-16 pt-24"
     x-data="{ busy: false, err: '',
        async unlock() {
            if (this.busy) return;
            this.busy = true; this.err = '';
            try {
                const r = await window.nxPostSoft(@js(route('content.vip.unlock', $content)), {});
                if (r && r.success) { location.reload(); }
                else { this.err = (r && r.error) || 'ปลดล็อกไม่สำเร็จ'; }
            } catch (e) { this.err = 'เกิดข้อผิดพลาด ลองใหม่'; }
            finally { this.busy = false; }
        } }">
    <div class="absolute inset-0 -z-10" style="background:{{ $content->gradient }}"></div>
    @if ($content->backdrop_url || $content->poster_url)
        <img src="{{ $content->backdrop_url ?: $content->poster_url }}" alt="" aria-hidden="true" referrerpolicy="no-referrer"
             onerror="this.style.display='none'"
             class="absolute inset-0 -z-10 h-full w-full scale-110 object-cover opacity-40 blur-2xl">
    @endif
    <div class="absolute inset-0 -z-10 bg-gradient-to-b from-ink/70 via-ink/85 to-ink"></div>

    <div class="w-full max-w-md text-center">
        <div class="mx-auto mb-5 flex h-20 w-20 items-center justify-center rounded-full bg-gradient-to-br from-[#ffd76a] to-[#e0a63a] text-4xl shadow-[0_10px_40px_rgba(245,197,66,0.35)]">⭐</div>

        <div class="mb-2 inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold">
            <span class="rounded px-1.5 py-0.5 text-black" style="background:#ffd76a">VIP</span>
            <span class="text-cream/70">เนื้อหาโซน VIP</span>
        </div>

        <h1 class="text-2xl font-extrabold sm:text-3xl">{{ $content->title }}</h1>
        <p class="mt-3 text-[15px] leading-relaxed text-cream/70">
            เรื่องนี้อยู่ในโซน <span class="font-semibold" style="color:#ffd76a">VIP</span> —
            ปลดล็อกด้วย <span class="font-bold" style="color:#ffd76a">👑 {{ number_format($cost) }} เหรียญทอง</span> หรือเป็นสมาชิก Pro
            <span class="mt-1 block text-[13px] text-cream/45">เหรียญทองของคุณตอนนี้: 👑 {{ number_format($gold) }}</span>
        </p>

        <div class="mt-7 flex flex-col items-center gap-3">
            @if ($gold >= $cost)
                <button type="button" @click="unlock()" :disabled="busy"
                        class="flex w-full items-center justify-center gap-2 rounded-lg bg-gradient-to-r from-[#ffd76a] to-[#e0a63a] px-6 py-3 font-bold text-black transition hover:brightness-95 disabled:opacity-60">
                    <span x-text="busy ? 'กำลังปลดล็อก…' : '⭐ ปลดล็อกด้วย {{ number_format($cost) }} เหรียญทอง'"></span>
                </button>
            @else
                <div class="w-full rounded-lg border border-white/10 bg-white/[0.03] px-4 py-3 text-[13.5px] text-cream/60">เหรียญทองไม่พอ (ต้องการอีก {{ number_format($cost - $gold) }})</div>
            @endif
            <a href="{{ route('account') }}"
               class="flex w-full items-center justify-center gap-2 rounded-lg bg-white/10 px-6 py-3 font-semibold text-cream transition hover:bg-white/15">
                เติมเหรียญทอง / ซื้อ Pro
            </a>
            <p x-show="err" x-cloak class="text-[13px] text-[#ff6b81]" x-text="err"></p>
            <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('browse') }}"
               class="text-sm text-cream/60 transition hover:text-cream">‹ ย้อนกลับ</a>
        </div>
    </div>
</div>
@endsection
