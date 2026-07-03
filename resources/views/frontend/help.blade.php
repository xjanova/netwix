@extends('layouts.guest')
@section('title', 'ศูนย์ช่วยเหลือ')

@section('content')
<div class="min-h-screen bg-ink text-cream">
    @include('partials.site-header')

    <section class="relative overflow-hidden px-[5vw] pb-8 pt-14 text-center">
        <div class="pointer-events-none absolute left-1/2 top-0 h-72 w-72 -translate-x-1/2 rounded-full opacity-40 blur-3xl" style="background:radial-gradient(circle,#06c755,transparent 70%)"></div>
        <div class="relative mx-auto max-w-2xl">
            <h1 class="text-[clamp(28px,5vw,46px)] font-extrabold leading-tight">ศูนย์ช่วยเหลือ NetWix</h1>
            <p class="mt-4 text-[15px] leading-relaxed text-cream/70">
                มีคำถามหรือต้องการความช่วยเหลือ? แชทกับทีมงานของเราได้โดยตรงผ่าน LINE
                ทักมาได้เลย เราพร้อมดูแลคุณทุกวัน
            </p>

            {{-- LINE chat CTA --}}
            <a href="{{ $lineUrl }}" target="_blank" rel="noopener"
               class="mt-8 inline-flex items-center gap-3 rounded-2xl px-8 py-4 text-lg font-bold text-white shadow-xl transition hover:brightness-110"
               style="background:#06c755;box-shadow:0 12px 30px rgba(6,199,85,0.35)">
                <svg viewBox="0 0 24 24" fill="currentColor" class="h-7 w-7"><path d="M12 3C6.9 3 2.8 6.4 2.8 10.6c0 3.8 3.3 7 7.8 7.6.3.1.7.2.8.5.1.3 0 .7 0 .9l-.1.8c0 .3-.2 1 .9.5s5.7-3.4 7.8-5.8c1.4-1.5 2.1-3.1 2.1-5C22 6.4 17.9 3 12 3z"/></svg>
                แชทกับแอดมินทาง LINE
            </a>
            <p class="mt-4 text-[13px] text-cream/50">หรืออีเมลหาเราที่ <a href="mailto:{{ $email }}" class="text-cream/80 underline underline-offset-4">{{ $email }}</a></p>
        </div>
    </section>

    {{-- quick help --}}
    <section class="px-[5vw] py-8">
        @php
            $topics = [
                ['เริ่มต้นใช้งาน', 'วิธีสมัครสมาชิก เลือกแพ็กเกจ และสร้างโปรไฟล์ผู้ชม'],
                ['การชำระเงินและแพ็กเกจ', 'ราคาแพ็กเกจ วิธีชำระเงิน และการต่ออายุสมาชิก'],
                ['ปัญหาการรับชม', 'วิดีโอสะดุด ภาพไม่ชัด หรือเข้าดูไม่ได้ ทำอย่างไร'],
                ['บัญชีและความปลอดภัย', 'ลืมรหัสผ่าน เปลี่ยนอีเมล และจัดการอุปกรณ์'],
            ];
        @endphp
        <div class="mx-auto grid max-w-4xl gap-4 sm:grid-cols-2">
            @foreach ($topics as [$t, $d])
                <div class="rounded-2xl border border-white/[0.06] p-6" style="background:linear-gradient(150deg,#1a1030 0%,#120b22 100%)">
                    <h3 class="text-base font-bold">{{ $t }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-cream/60">{{ $d }}</p>
                    <a href="{{ $lineUrl }}" target="_blank" rel="noopener" class="mt-3 inline-block text-sm text-cream/70 underline-offset-4 hover:text-cream hover:underline">สอบถามเรื่องนี้ ›</a>
                </div>
            @endforeach
        </div>
    </section>

    @include('partials.site-footer')
</div>
@endsection
