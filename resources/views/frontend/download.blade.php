@extends('layouts.guest')
@section('title', 'ดาวน์โหลดแอป NetWix')

@section('content')
<div class="min-h-screen bg-ink text-cream">
    @include('partials.site-header')

    {{-- hero --}}
    <section class="relative overflow-hidden px-[5vw] pb-6 pt-12 sm:pt-16">
        <div class="pointer-events-none absolute -left-20 -top-24 h-72 w-72 rounded-full opacity-40 blur-3xl" style="background:radial-gradient(circle,#ff2d55,transparent 70%)"></div>
        <div class="pointer-events-none absolute -right-16 top-10 h-72 w-72 rounded-full opacity-40 blur-3xl" style="background:radial-gradient(circle,#b026ff,transparent 70%)"></div>

        <div class="relative mx-auto grid max-w-6xl items-center gap-10 lg:grid-cols-[1.2fr_1fr]">
            <div>
                <span class="nx-gradient inline-block rounded-full px-3 py-1 text-[11px] font-bold tracking-widest">แอป NETWIX</span>
                <h1 class="mt-4 text-[clamp(30px,5vw,52px)] font-extrabold leading-[1.1]">
                    ดูหนังและซีรีส์<br><span class="nx-gradient-text">ได้ทุกที่ทุกเวลา</span>
                </h1>
                <p class="mt-5 max-w-lg text-[15px] leading-relaxed text-cream/70">
                    แอป NetWix ให้คุณสตรีมภาพยนตร์ ซีรีส์ และซีรีส์แนวตั้งได้ลื่นไหลบนมือถือและแท็บเล็ต
                    ดาวน์โหลดตอนไว้ดูออฟไลน์ ดูต่อจากที่ค้างไว้ข้ามอุปกรณ์ และรับการแจ้งเตือนเมื่อมีตอนใหม่
                </p>

                {{-- APK download (Android) — appears once a GitHub release with an .apk exists --}}
                @isset($release)
                    <div class="mt-7 flex flex-col items-start gap-3">
                        <a href="{{ route('download.apk') }}"
                           class="inline-flex items-center gap-3 rounded-xl bg-cream px-6 py-3.5 font-bold text-ink shadow-lg transition hover:brightness-90">
                            <svg viewBox="0 0 24 24" fill="currentColor" class="h-6 w-6"><path d="M12 16l-5-5h3V4h4v7h3l-5 5zM5 18h14v2H5z"/></svg>
                            <span>ดาวน์โหลดแอป Android
                                <span class="block text-[11px] font-normal opacity-60">เวอร์ชัน {{ $release['version'] }} · {{ number_format($release['size'] / 1048576, 1) }} MB · ไฟล์ APK</span>
                            </span>
                        </a>
                        <a href="#install" class="text-[13px] text-cream/60 underline-offset-4 hover:text-cream hover:underline">วิธีติดตั้งไฟล์ APK (นอก Play Store) ›</a>
                    </div>
                @endisset

                {{-- store buttons (coming soon) --}}
                <div class="mt-5 flex flex-wrap items-center gap-3">
                    <span class="flex items-center gap-3 rounded-xl border border-white/15 bg-black/40 px-5 py-3">
                        <svg viewBox="0 0 24 24" fill="currentColor" class="h-7 w-7"><path d="M3.6 2.3 13 12l-9.4 9.7c-.4-.3-.6-.8-.6-1.4V3.7c0-.6.2-1.1.6-1.4zm11 10.8 2.6 2.6-9 5.2 6.4-7.8zm3.9-2.2 2.3 1.3c.9.5.9 1.9 0 2.4l-2.3 1.3-2.9-2.5 2.9-2.5zM5.6 2 15 7.4l-2.6 2.7L5.6 2z"/></svg>
                        <span><span class="block text-[10px] text-cream/50">เร็ว ๆ นี้บน</span><span class="block text-base font-bold">Google Play</span></span>
                    </span>
                    <span class="flex items-center gap-3 rounded-xl border border-white/15 bg-black/40 px-5 py-3">
                        <svg viewBox="0 0 24 24" fill="currentColor" class="h-7 w-7"><path d="M16.4 12.7c0-2.3 1.9-3.4 2-3.5-1.1-1.6-2.8-1.8-3.4-1.8-1.4-.1-2.8.9-3.5.9s-1.8-.9-3-.8c-1.5 0-3 .9-3.8 2.3-1.6 2.8-.4 7 1.2 9.3.8 1.1 1.7 2.4 2.9 2.3 1.2 0 1.6-.7 3-.7s1.8.7 3 .7 2-1.1 2.8-2.2c.9-1.3 1.2-2.5 1.3-2.6-.1 0-2.4-1-2.4-3.6zM14.2 5.9c.6-.8 1.1-1.9 1-3-1 0-2.1.6-2.8 1.4-.6.7-1.1 1.8-1 2.9 1.1.1 2.2-.5 2.8-1.3z"/></svg>
                        <span><span class="block text-[10px] text-cream/50">เร็ว ๆ นี้บน</span><span class="block text-base font-bold">App Store</span></span>
                    </span>
                </div>
                <p class="mt-4 text-[13px] text-cream/50">iPhone/iPad และ Play Store เร็ว ๆ นี้ — ระหว่างนี้รับชมผ่านเว็บได้เลยที่ <a href="{{ route('home') }}" class="text-cream/80 underline underline-offset-4">netwix.online</a></p>
            </div>

            {{-- app screenshot slider (placeholder frames — swap with real captures) --}}
            <div class="flex justify-center lg:justify-end">
                <div x-data="{ i: 0, n: 3 }" x-init="setInterval(() => i = (i + 1) % n, 3000)" class="relative">
                    <div class="relative h-[430px] w-[210px] rounded-[2.4rem] border-[7px] border-black/70 bg-ink shadow-2xl ring-1 ring-white/10">
                        <div class="absolute left-1/2 top-2.5 z-10 h-1.5 w-20 -translate-x-1/2 rounded-full bg-black/70"></div>
                        <div class="h-full w-full overflow-hidden rounded-[1.9rem]">
                            @php
                                $shots = [
                                    ['หน้าแรก', 'radial-gradient(ellipse at 50% 0%, rgba(176,38,255,0.4), transparent 60%), linear-gradient(160deg,#160c28,#0a0713)'],
                                    ['ซีรีส์แนวตั้ง', 'radial-gradient(ellipse at 50% 100%, rgba(255,45,85,0.4), transparent 60%), linear-gradient(160deg,#1a0c1e,#0a0713)'],
                                    ['กำลังเล่น', 'radial-gradient(ellipse at 50% 50%, rgba(70,211,105,0.25), transparent 60%), linear-gradient(160deg,#101425,#0a0713)'],
                                ];
                            @endphp
                            @foreach ($shots as $idx => [$label, $bg])
                                <div x-show="i === {{ $idx }}" x-transition:enter="transition duration-500" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                                     class="absolute inset-0 flex flex-col items-center justify-center gap-3" style="background:{{ $bg }}">
                                    <img src="{{ asset('assets/netwix-icon.png') }}" alt="" class="h-14 w-14 rounded-2xl shadow-lg">
                                    <span class="text-sm font-bold">{{ $label }}</span>
                                    <span class="text-[11px] text-cream/45">ตัวอย่างหน้าจอแอป</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <div class="mt-4 flex justify-center gap-2">
                        <template x-for="d in n" :key="d">
                            <button @click="i = d - 1" class="h-2 rounded-full transition-all" :class="i === d - 1 ? 'w-6 nx-gradient' : 'w-2 bg-white/25'"></button>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- features --}}
    <section class="px-[5vw] py-10">
        <div class="mx-auto grid max-w-6xl gap-4 sm:grid-cols-3">
            @php
                $appFeatures = [
                    ['ดาวน์โหลดดูออฟไลน์', 'บันทึกตอนที่ชอบไว้ดูตอนไม่มีเน็ต ไม่ว่าจะบนเครื่องบินหรือระหว่างเดินทาง'],
                    ['ดูต่อข้ามอุปกรณ์', 'เริ่มดูบนมือถือ แล้วดูต่อบนเว็บได้ทันที ระบบจำตำแหน่งล่าสุดให้'],
                    ['ปัดชมซีรีส์แนวตั้ง', 'ประสบการณ์จอตั้งเต็มรูปแบบ ปัดขึ้น–ลงลื่นไหลเหมือนเล่นโซเชียล'],
                ];
            @endphp
            @foreach ($appFeatures as [$t, $d])
                <div class="rounded-2xl border border-white/[0.06] p-6" style="background:linear-gradient(150deg,#1a1030 0%,#120b22 100%)">
                    <h3 class="text-base font-bold">{{ $t }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-cream/60">{{ $d }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- install guide (sideload APK) --}}
    @isset($release)
    <section id="install" class="px-[5vw] py-10">
        <div class="mx-auto max-w-3xl">
            <div class="flex items-center gap-2.5">
                <span class="nx-gradient inline-block rounded-full px-3 py-1 text-[11px] font-bold tracking-widest">ติดตั้ง</span>
                <span class="text-[12px] text-cream/45">เวอร์ชัน {{ $release['version'] }}</span>
            </div>
            <h2 class="mt-4 text-xl font-bold sm:text-2xl">วิธีติดตั้งแอป NetWix บน Android</h2>
            <p class="mt-2 text-sm text-cream/60">แอปยังไม่อยู่บน Play Store จึงติดตั้งจากไฟล์ APK โดยตรง — ปลอดภัย ดาวน์โหลดจาก netwix.online เท่านั้น ใช้เวลาไม่ถึงนาที</p>
            @php
                $steps = [
                    ['กดปุ่ม “ดาวน์โหลดแอป Android”', 'แตะปุ่มดาวน์โหลดด้านบนของหน้านี้ รอไฟล์ .apk ดาวน์โหลดจนเสร็จ'],
                    ['เปิดไฟล์ที่ดาวน์โหลด', 'แตะที่แถบแจ้งเตือนการดาวน์โหลด หรือเปิดแอป “ไฟล์/Files” → โฟลเดอร์ Downloads แล้วแตะไฟล์ NetWix .apk'],
                    ['อนุญาตการติดตั้ง (ครั้งแรกเท่านั้น)', 'Android จะถามเรื่องความปลอดภัย — แตะ “ตั้งค่า” แล้วเปิด “อนุญาตจากแหล่งนี้” (Allow from this source) ให้เบราว์เซอร์หรือแอปไฟล์ที่ใช้อยู่'],
                    ['แตะ “ติดตั้ง” (Install)', 'ย้อนกลับมาแล้วแตะติดตั้ง รอสักครู่จนเสร็จ — หาก Play Protect เตือน แตะ “ติดตั้งต่อไป” ได้เลย ไฟล์มาจาก netwix.online'],
                    ['เปิดแอปแล้วเข้าสู่ระบบ', 'แตะ “เปิด” เข้าสู่ระบบด้วยบัญชี NetWix (หรือ Google/LINE) แล้วเริ่มดูได้ทันที'],
                ];
            @endphp
            <ol class="mt-6 flex flex-col gap-3">
                @foreach ($steps as $i => [$t, $d])
                    <li class="flex gap-4 rounded-2xl border border-white/[0.06] p-4" style="background:linear-gradient(150deg,#160d29,#0d0918)">
                        <span class="nx-gradient flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-bold">{{ $i + 1 }}</span>
                        <div>
                            <div class="font-semibold">{{ $t }}</div>
                            <p class="mt-1 text-sm leading-relaxed text-cream/60">{{ $d }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
            <p class="mt-5 text-[12px] text-cream/40">* iPhone/iPad ยังไม่รองรับการติดตั้งนอก App Store — ระหว่างนี้รับชมผ่านเว็บ netwix.online ได้เลย · เมื่อมีเวอร์ชันใหม่ ปุ่มดาวน์โหลดจะอัปเดตให้อัตโนมัติ</p>
        </div>
    </section>
    @endisset

    {{-- supported devices --}}
    <section id="devices" class="px-[5vw] py-10">
        <div class="mx-auto max-w-6xl rounded-3xl border border-white/[0.06] p-8 text-center" style="background:linear-gradient(150deg,#160d29,#0d0918)">
            <h2 class="text-xl font-bold sm:text-2xl">รองรับหลากหลายอุปกรณ์</h2>
            <p class="mx-auto mt-2 max-w-xl text-sm text-cream/60">Android, iOS, เว็บเบราว์เซอร์ และสมาร์ททีวี — บัญชีเดียวดูได้ทุกจอ</p>
            <div class="mt-6 flex flex-wrap items-center justify-center gap-3 text-sm text-cream/70">
                @foreach (['Android', 'iPhone / iPad', 'เว็บเบราว์เซอร์', 'สมาร์ททีวี', 'แท็บเล็ต'] as $device)
                    <span class="rounded-full border border-white/12 bg-white/[0.04] px-4 py-2">{{ $device }}</span>
                @endforeach
            </div>
        </div>
    </section>

    @include('partials.site-footer')
</div>
@endsection
