@extends('layouts.app')
@section('title', $content->title.' · เฉพาะสมาชิก Pro')

@section('content')
<div class="relative flex min-h-[100dvh] items-center justify-center overflow-hidden px-4 pb-16 pt-24">
    {{-- blurred backdrop of the title itself --}}
    <div class="absolute inset-0 -z-10" style="background:{{ $content->gradient }}"></div>
    @if ($content->backdrop_url || $content->poster_url)
        <img src="{{ $content->backdrop_url ?: $content->poster_url }}" alt="" aria-hidden="true" referrerpolicy="no-referrer"
             onerror="this.style.display='none'"
             class="absolute inset-0 -z-10 h-full w-full scale-110 object-cover opacity-40 blur-2xl">
    @endif
    <div class="absolute inset-0 -z-10 bg-gradient-to-b from-ink/70 via-ink/85 to-ink"></div>

    <div class="w-full max-w-md text-center">
        <div class="mx-auto mb-5 flex h-20 w-20 items-center justify-center rounded-full bg-gradient-to-br from-gold to-[#ffcf5a] text-4xl shadow-[0_10px_40px_rgba(245,197,66,0.35)]">👑</div>

        <div class="mb-2 inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold">
            <span class="rounded bg-gold/90 px-1.5 py-0.5 text-black">{{ $content->maturity }}</span>
            <span class="text-cream/70">เนื้อหาสำหรับผู้ใหญ่</span>
        </div>

        <h1 class="text-2xl font-extrabold sm:text-3xl">{{ $content->title }}</h1>
        <p class="mt-3 text-[15px] leading-relaxed text-cream/70">
            เรื่องนี้เป็นเรตผู้ใหญ่ ({{ $content->maturity }}) รับชมได้เฉพาะสมาชิก
            <span class="font-semibold text-gold">Pro</span> เท่านั้น
            <span class="mt-1 block text-cream/45">Adult content — available to Pro members only</span>
        </p>

        <div class="mt-7 flex flex-col items-center gap-3">
            <a href="{{ route('account') }}"
               class="flex w-full items-center justify-center gap-2 rounded-lg bg-gradient-to-r from-gold to-[#ffcf5a] px-6 py-3 font-bold text-black transition hover:brightness-95">
                👑 อัปเกรดเป็น Pro
            </a>
            <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('browse') }}"
               class="text-sm text-cream/60 transition hover:text-cream">‹ ย้อนกลับ</a>
        </div>
    </div>
</div>
@endsection
