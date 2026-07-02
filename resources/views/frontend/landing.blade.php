@extends('layouts.guest')
@section('title', 'ดูหนัง ซีรีส์ และซีรีส์แนวตั้งไม่จำกัด')

@section('content')
<div class="bg-ink text-cream">

    {{-- ================= HERO ================= --}}
    <section class="relative overflow-hidden">
        <div class="absolute inset-0" aria-hidden="true"
             style="background:radial-gradient(ellipse at 18% -10%, rgba(255,45,85,0.20), transparent 50%), radial-gradient(ellipse at 82% 0%, rgba(139,47,240,0.22), transparent 55%), #07050c;"></div>

        <header class="relative z-20 flex items-center justify-between px-[5vw] py-5">
            <img src="{{ asset('assets/netwix-wordmark.png') }}" alt="NetWix" class="h-10 w-auto sm:h-12">
            <a href="{{ route('login') }}" class="btn-brand px-5 py-2 text-sm sm:px-6">เข้าสู่ระบบ</a>
        </header>

        <div class="relative z-20 mx-auto max-w-3xl px-6 pb-10 pt-8 text-center sm:pt-16">
            <h1 class="text-[clamp(32px,5.5vw,60px)] font-extrabold leading-[1.12]">
                ภาพยนตร์ ซีรีส์ และ<span class="nx-gradient-text">ซีรีส์แนวตั้ง</span><br>ไม่จำกัด
            </h1>
            <p class="mt-4 text-lg font-medium text-cream/85">เริ่มต้นที่ 99 บาท/เดือน ยกเลิกได้ทุกเมื่อ</p>
            <p class="mt-6 text-[15px] text-cream/65">พร้อมรับชมแล้วหรือยัง? กรอกอีเมลเพื่อสมัครสมาชิก</p>
            <form method="GET" action="{{ route('register') }}" class="mx-auto mt-4 flex max-w-xl flex-col gap-3 sm:flex-row">
                <input type="email" name="email" required placeholder="ที่อยู่อีเมล" class="nx-input flex-1 py-4 text-base">
                <button type="submit" class="btn-brand whitespace-nowrap px-8 py-4 text-lg">เริ่มกันเลย ›</button>
            </form>
        </div>

        {{-- 3D poster wall --}}
        @if ($trending->isNotEmpty())
            <div class="relative z-10" aria-hidden="true" style="perspective:1400px">
                <div class="flex justify-center gap-3 px-2 sm:gap-4"
                     style="transform:rotateX(16deg) scale(1.04);transform-origin:center bottom">
                    @foreach ($trending->take(9) as $c)
                        <div class="relative aspect-[2/3] w-28 shrink-0 overflow-hidden rounded-lg shadow-2xl ring-1 ring-white/10 sm:w-36 md:w-44 {{ $loop->index % 2 ? 'translate-y-5' : '' }}"
                             style="background:{{ $c->gradient }}">
                            @if ($c->is_original)
                                <span class="nx-gradient absolute left-2 top-2 rounded px-1.5 py-0.5 text-[8px] font-bold tracking-widest">NETWIX</span>
                            @endif
                            <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/80 to-transparent p-2.5">
                                <div class="truncate text-[11px] font-semibold sm:text-[12.5px]">{{ $c->title }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-ink via-ink/25 to-transparent"></div>
            </div>
        @endif

        {{-- glowing curved divider --}}
        <div class="relative z-10 -mt-10 h-24 overflow-hidden sm:-mt-14" aria-hidden="true">
            <div class="absolute left-1/2 top-7 h-[240px] w-[160vw] -translate-x-1/2 rounded-t-[100%]"
                 style="background:linear-gradient(180deg,#130b21 0%,#07050c 70%);box-shadow:0 -5px 34px 6px rgba(176,38,255,0.38), inset 0 3px 0 0 rgba(255,45,85,0.55);"></div>
        </div>
    </section>

    {{-- ================= TOP 10 ================= --}}
    @if ($trending->isNotEmpty())
        <section class="px-[5vw] pb-4 pt-6">
            <h2 class="mb-5 text-xl font-bold sm:text-2xl">มาแรงตอนนี้</h2>
            <div class="nx-rail pb-3">
                @foreach ($trending as $i => $c)
                    <a href="{{ route('login') }}" class="group relative w-[168px] shrink-0 pl-10 sm:w-[196px] sm:pl-12">
                        <span class="pointer-events-none absolute -bottom-2 left-0 z-0 text-[88px] font-black leading-none text-transparent sm:text-[104px]"
                              style="-webkit-text-stroke:2px rgba(244,241,248,0.35)">{{ $i + 1 }}</span>
                        <div class="relative z-10 aspect-[2/3] overflow-hidden rounded-lg shadow-xl ring-1 ring-white/10 transition duration-200 group-hover:scale-105 group-hover:ring-white/30"
                             style="background:{{ $c->gradient }}">
                            @if ($c->is_original)
                                <span class="nx-gradient absolute left-2 top-2 rounded px-1.5 py-0.5 text-[9px] font-bold tracking-widest">NETWIX</span>
                            @endif
                            <span class="absolute right-2 top-2 rounded bg-black/55 px-1.5 py-0.5 text-[10px] font-semibold">{{ $c->maturity }}</span>
                            <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/85 to-transparent p-2.5">
                                <div class="truncate text-[12.5px] font-semibold">{{ $c->title }}</div>
                                <div class="text-[10.5px] text-cream/55">{{ $c->primaryGenre()?->name }}</div>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ================= REASONS ================= --}}
    @php
        $features = [
            ['เพลิดเพลินบนทีวีของคุณ', 'ดูบนสมาร์ททีวี, PlayStation, Chromecast, Apple TV และอื่นๆ อีกมากมาย',
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="h-7 w-7"><rect x="2.5" y="4" width="19" height="13" rx="2"/><path d="M8 21h8M12 17v4"/></svg>'],
            ['ดูได้ทุกที่ทุกเวลา', 'สตรีมได้ไม่จำกัดทั้งบนมือถือ แท็บเล็ต แล็ปท็อป และทีวี',
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="h-7 w-7"><rect x="3" y="4" width="13" height="12" rx="2"/><rect x="16.5" y="9" width="5" height="11" rx="1.5"/><path d="M7 20h6"/></svg>'],
            ['ซีรีส์แนวตั้ง ดูจบไว', 'ปัดขึ้น–ลงเหมือนโซเชียล ตอนสั้นๆ ดูจบในไม่กี่นาที',
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="h-7 w-7"><rect x="7" y="2.5" width="10" height="19" rx="2"/><path d="M10.5 9.5v5l4-2.5z" fill="currentColor" stroke="none"/></svg>'],
            ['สร้างโปรไฟล์ให้เด็ก', 'ให้เด็กๆ สนุกกับคอนเทนต์ที่เหมาะกับวัย ในพื้นที่ของตัวเอง — ฟรี',
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="h-7 w-7"><circle cx="12" cy="12" r="9"/><path d="M9 10h.01M15 10h.01M8.5 14.5s1.2 1.8 3.5 1.8 3.5-1.8 3.5-1.8"/></svg>'],
        ];
    @endphp
    <section class="px-[5vw] py-10">
        <h2 class="mb-5 text-xl font-bold sm:text-2xl">เหตุผลดีๆ ที่ต้องสมัคร</h2>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($features as [$title, $desc, $icon])
                <div class="relative overflow-hidden rounded-2xl border border-white/[0.06] p-6 pb-20"
                     style="background:linear-gradient(150deg,#221037 0%,#150b26 55%,#0e0a17 100%)">
                    <h3 class="text-lg font-bold">{{ $title }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-cream/60">{{ $desc }}</p>
                    <div class="nx-gradient absolute bottom-4 right-4 flex h-12 w-12 items-center justify-center rounded-full text-white"
                         style="box-shadow:0 8px 22px rgba(176,38,255,0.4)">{!! $icon !!}</div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- ================= FAQ ================= --}}
    @php
        $faqs = [
            ['NetWix คืออะไร?', 'NetWix คือบริการสตรีมมิ่งที่รวมภาพยนตร์ ซีรีส์ และซีรีส์แนวตั้งหลากหลายแนว ดูได้ไม่จำกัดบนอุปกรณ์ที่เชื่อมต่ออินเทอร์เน็ต ทั้งทีวี มือถือ แท็บเล็ต และคอมพิวเตอร์'],
            ['NetWix ราคาเท่าไหร่?', 'เริ่มต้นเพียง 99 บาท/เดือน (Basic) — Standard 199 บาท และ Premium 349 บาท ไม่มีค่าธรรมเนียมเพิ่มเติม ไม่มีสัญญาผูกมัด'],
            ['ดูได้ที่ไหนบ้าง?', 'ดูได้ทุกที่ทุกเวลา — เข้าสู่ระบบผ่านเว็บ netwix.online บนอุปกรณ์ใดก็ได้ รองรับทั้งวิดีโอแบบปกติและ HLS สตรีมมิ่ง'],
            ['ซีรีส์แนวตั้งคืออะไร?', 'ซีรีส์สั้นแบบจอตั้ง (9:16) ตอนละไม่กี่นาที ปัดขึ้น–ลงเพื่อดูตอนถัดไปได้ทันทีเหมือนเล่นโซเชียล เหมาะกับการดูระหว่างเดินทาง'],
            ['ยกเลิกได้อย่างไร?', 'ยกเลิกได้ทุกเมื่อด้วยการคลิกเดียว ไม่มีค่าปรับ ไม่ต้องโทรหาใคร — สมัครใหม่เมื่อไหร่ก็ได้'],
            ['เหมาะกับเด็กไหม?', 'สร้างโปรไฟล์ KIDS แยกให้เด็กได้ฟรี พร้อมเรตอายุกำกับทุกเรื่อง เพื่อให้ผู้ปกครองมั่นใจ'],
        ];
    @endphp
    <section class="px-[5vw] py-10">
        <h2 class="mb-5 text-xl font-bold sm:text-2xl">คำถามที่พบบ่อย</h2>
        <div x-data="{ open: null }" class="mx-auto flex max-w-4xl flex-col gap-2">
            @foreach ($faqs as $i => [$q, $a])
                <div class="overflow-hidden rounded-lg bg-white/[0.06]">
                    <button type="button" @click="open = open === {{ $i }} ? null : {{ $i }}"
                            class="flex w-full items-center justify-between gap-4 px-6 py-5 text-left text-base font-medium transition hover:bg-white/[0.05] sm:text-lg">
                        <span>{{ $q }}</span>
                        <span class="text-3xl font-light leading-none transition-transform duration-200" :class="open === {{ $i }} ? 'rotate-45' : ''">+</span>
                    </button>
                    <div x-show="open === {{ $i }}" x-cloak class="border-t-2 border-ink px-6 py-5 text-[15px] leading-relaxed text-cream/75">{{ $a }}</div>
                </div>
            @endforeach
        </div>

        {{-- closing CTA --}}
        <div class="mx-auto mt-10 max-w-xl text-center">
            <p class="text-[15px] text-cream/65">พร้อมรับชมแล้วหรือยัง? กรอกอีเมลเพื่อสมัครสมาชิก</p>
            <form method="GET" action="{{ route('register') }}" class="mt-4 flex flex-col gap-3 sm:flex-row">
                <input type="email" name="email" required placeholder="ที่อยู่อีเมล" class="nx-input flex-1 py-4 text-base">
                <button type="submit" class="btn-brand whitespace-nowrap px-8 py-4 text-lg">เริ่มกันเลย ›</button>
            </form>
        </div>
    </section>

    {{-- ================= FOOTER ================= --}}
    <footer class="border-t border-white/5 px-[5vw] py-10 text-cream/45">
        <img src="{{ asset('assets/netwix-wordmark.png') }}" alt="NetWix" class="mb-5 h-8 opacity-70">
        <div class="grid max-w-3xl grid-cols-2 gap-3 text-[13px] sm:grid-cols-4">
            <a href="{{ route('login') }}" class="hover:text-cream">เข้าสู่ระบบ</a>
            <a href="{{ route('register') }}" class="hover:text-cream">สมัครสมาชิก</a>
            <span>ศูนย์ช่วยเหลือ</span>
            <span>เงื่อนไขการใช้งาน</span>
            <span>ความเป็นส่วนตัว</span>
            <span>ติดต่อเรา</span>
        </div>
        <p class="mt-6 text-xs text-cream/35">© {{ date('Y') }} NetWix — บริการสตรีมมิ่งภาพยนตร์และซีรีส์</p>
    </footer>
</div>
@endsection
