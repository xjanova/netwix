@extends('layouts.guest')
@section('title', 'ดูหนัง ซีรีส์ และซีรีส์แนวตั้งไม่จำกัด')
@section('meta_description', 'ดูหนัง ซีรีส์ และซีรีส์แนวตั้งออนไลน์ที่ NetWix สตรีมไม่จำกัด ดูฟรี ชวนเพื่อนรับสิทธิ์ Pro และของรางวัลมากมาย รับชมได้ทั้งมือถือ แท็บเล็ต และทีวี')

@section('content')
{{-- no bg-ink here: <html> carries the base ink so the ambient #nx-dream fibers show through --}}
<div class="text-cream">

    {{-- ============ ROTATING BILLBOARD (whole-site หนังตัวอย่าง) ============ --}}
    @include('partials.hero-billboard', [
        'heroSlides' => $heroSlides ?? [],
        'heroSeconds' => $heroSeconds ?? 8,
        'heroVideo' => $heroVideo ?? true,
        'heroPublic' => true,
    ])

    {{-- ================= HERO ================= --}}
    <section class="relative overflow-hidden">
        {{-- base brand glow --}}
        <div class="absolute inset-0 z-0" aria-hidden="true"
             style="background:radial-gradient(ellipse at 18% -10%, rgba(255,45,85,0.20), transparent 50%), radial-gradient(ellipse at 82% 0%, rgba(139,47,240,0.22), transparent 55%), #07050c;"></div>

        {{-- ambient video background --}}
        <video class="absolute inset-0 z-[1] h-full w-full object-cover opacity-[0.14]"
               style="mix-blend-mode:screen" autoplay muted loop playsinline preload="metadata" aria-hidden="true">
            <source src="{{ asset('assets/logomedia2.mp4') }}" type="video/mp4">
        </video>

        {{-- fireflies --}}
        <div class="pointer-events-none absolute inset-0 z-[5] overflow-hidden" aria-hidden="true">
            @for ($i = 0; $i < 32; $i++)
                <span class="nx-firefly" style="
                    --x:{{ rand(1, 97) }}%; --y:{{ rand(3, 90) }}%;
                    --size:{{ rand(3, 7) }}px;
                    --dx:{{ rand(-70, 70) }}px; --dy:{{ rand(-55, 25) }}px;
                    --dur:{{ rand(60, 150) / 10 }}s; --delay:{{ rand(0, 90) / 10 }}s;"></span>
            @endfor
        </div>

        {{-- header --}}
        <header class="relative z-20 flex items-center justify-between px-[5vw] py-5">
            <img src="{{ asset('assets/netwix-wordmark.png') }}" alt="NetWix" class="h-14 w-auto sm:h-16">
            <a href="{{ route('login') }}" class="btn-brand px-5 py-2 text-sm sm:px-6">เข้าสู่ระบบ</a>
        </header>

        {{-- news ticker (admin-editable) --}}
        @php
            $ticker = collect($announcements)->map(fn ($a) => [
                'badge' => $a->badge ?? null,
                'body' => $a->body ?? '',
                'link' => $a->link ?? null,
            ])->values();
        @endphp
        <div class="relative z-20 px-6">
            <div x-data="{ items: {{ \Illuminate\Support\Js::from($ticker) }}, i: 0 }"
                 x-init="items.length > 1 && setInterval(() => i = (i + 1) % items.length, 4500)"
                 class="relative mx-auto flex h-9 max-w-2xl items-center justify-center overflow-hidden rounded-full border border-white/10 bg-white/[0.04] px-4 backdrop-blur">
                <template x-for="(item, idx) in items" :key="idx">
                    <a x-show="i === idx" :href="item.link"
                       x-transition:enter="nx-ticker-enter" x-transition:enter-start="opacity-0 translate-y-3" x-transition:enter-end="opacity-100 translate-y-0"
                       x-transition:leave="nx-ticker-enter" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-3"
                       class="absolute inset-0 flex items-center justify-center gap-2 px-4 text-[13px] font-medium text-cream/85 sm:text-sm">
                        <span x-show="item.badge" x-text="item.badge"
                              class="nx-gradient shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold tracking-wide text-white"></span>
                        <span class="truncate" x-text="item.body"></span>
                    </a>
                </template>
            </div>
        </div>

        {{-- headline + signup --}}
        <div class="relative z-20 mx-auto max-w-3xl px-6 pb-10 pt-7 text-center sm:pt-12">
            <h1 class="text-[clamp(32px,5.5vw,60px)] font-extrabold leading-[1.12]">
                ภาพยนตร์ ซีรีส์ และ<span class="nx-gradient-text">ซีรีส์แนวตั้ง</span><br>ไม่จำกัด
            </h1>
            <p class="mt-4 text-lg font-medium text-cream/85">แค่ชวนเพื่อน รับสิทธิ์ <span class="nx-gradient-text font-bold">Pro ฟรี</span> และของรางวัลอีกมากมาย 🎁</p>
            <p class="mt-6 text-[15px] text-cream/65">พร้อมรับชมแล้วหรือยัง? เริ่มดูฟรีได้เลยวันนี้</p>
            @include('frontend.partials.cta-start')
        </div>

        {{-- auto-scrolling poster marquee (real covers, drifting left) --}}
        @if ($marquee->isNotEmpty())
            <div class="nx-marquee relative z-10 py-4" aria-hidden="true">
                <div class="nx-marquee-track px-2" style="--speed:80s">
                    @foreach ($marquee->concat($marquee) as $c)
                        <div class="relative aspect-[2/3] w-28 shrink-0 overflow-hidden rounded-lg shadow-2xl ring-1 ring-white/10 sm:w-36 md:w-40"
                             style="background:{{ $c->gradient }}">
                            @if ($c->poster_url)
                                {{-- scraped posters (rongyok/wow-drama) block hotlinking by Referer → no-referrer (brain: FIX v0.2.4) --}}
                                <img src="{{ $c->poster_url }}" alt="" loading="lazy"
                                     referrerpolicy="no-referrer" onerror="this.style.display='none'"
                                     class="h-full w-full object-cover">
                            @endif
                            @if ($c->is_original)
                                <span class="nx-gradient absolute left-2 top-2 rounded px-1.5 py-0.5 text-[8px] font-bold tracking-widest">NETWIX</span>
                            @endif
                            <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/80 to-transparent p-2.5">
                                <div class="truncate text-[11px] font-semibold sm:text-[12.5px]">{{ $c->title }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-ink via-ink/20 to-transparent"></div>
            </div>
        @endif

        {{-- glowing curved divider --}}
        <div class="relative z-10 -mt-8 h-24 overflow-hidden sm:-mt-12" aria-hidden="true">
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
                    <a href="{{ route('title.show', $c) }}" class="group relative w-[168px] shrink-0 pl-10 sm:w-[196px] sm:pl-12">
                        <span class="pointer-events-none absolute -bottom-2 left-0 z-0 text-[88px] font-black leading-none text-transparent sm:text-[104px]"
                              style="-webkit-text-stroke:2px rgba(244,241,248,0.35)">{{ $i + 1 }}</span>
                        <div class="relative z-10 aspect-[2/3] overflow-hidden rounded-lg shadow-xl ring-1 ring-white/10 transition duration-200 group-hover:scale-105 group-hover:ring-white/30"
                             style="background:{{ $c->gradient }}">
                            @if ($c->poster_url)
                                <img src="{{ $c->poster_url }}" alt="{{ $c->title }}" loading="lazy"
                                     referrerpolicy="no-referrer" onerror="this.style.display='none'"
                                     class="h-full w-full object-cover">
                            @endif
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

    {{-- ================= LIVE STATS + DOWNLOAD APP ================= --}}
    @php
        // Real DB totals (from HomeController). Each card animates 0→value on scroll,
        // then keeps ticking up: views drift the fastest (most alive), members creep
        // up occasionally, the catalogue count stays put (drift 0) — a title count that
        // randomly jumps would read as broken, not impressive.
        $statCards = [
            ['value' => $stats['titles'], 'label' => 'หนังและซีรีส์', 'suffix' => '+', 'drift' => 0, 'every' => 0,
             'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M8 4v5M16 4v5M8 20v-5M16 20v-5"/></svg>'],
            ['value' => $stats['members'], 'label' => 'สมาชิกที่ร่วมสนุก', 'suffix' => '+', 'drift' => 1, 'every' => 9000,
             'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>'],
            ['value' => $stats['views'], 'label' => 'ครั้งการรับชม', 'suffix' => '', 'drift' => 6, 'every' => 1300,
             'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>'],
        ];
    @endphp
    <section class="px-[5vw] py-10">
        <div class="relative overflow-hidden rounded-3xl border border-white/[0.07] px-6 py-10 sm:px-12"
             style="background:linear-gradient(135deg,#1c0f33 0%,#140b26 55%,#0c0817 100%)">
            <div class="pointer-events-none absolute -right-10 -top-16 h-64 w-64 rounded-full opacity-40 blur-3xl" style="background:radial-gradient(circle,#b026ff,transparent 70%)"></div>
            <div class="pointer-events-none absolute -left-12 top-24 h-56 w-56 rounded-full opacity-30 blur-3xl" style="background:radial-gradient(circle,#ff2d55,transparent 70%)"></div>

            {{-- live social-proof stats (real totals · animated count-up + gentle live growth) --}}
            <div class="relative grid gap-3 sm:grid-cols-3">
                @foreach ($statCards as $s)
                    <div x-data="nxCounter({{ $s['value'] }}, { drift: {{ $s['drift'] }}, every: {{ $s['every'] }} })"
                         class="group relative overflow-hidden rounded-2xl border border-white/[0.08] bg-white/[0.03] p-5 text-center backdrop-blur transition hover:border-white/20 hover:bg-white/[0.05]">
                        <div class="nx-gradient mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-xl text-white" style="box-shadow:0 8px 22px rgba(176,38,255,0.35)">{!! $s['icon'] !!}</div>
                        <div class="flex items-baseline justify-center gap-0.5">
                            <span class="text-3xl font-extrabold tabular-nums tracking-tight sm:text-4xl" x-text="formatted">{{ number_format($s['value']) }}</span>
                            @if ($s['suffix'] && $s['value'] > 0)<span class="nx-gradient-text text-2xl font-extrabold sm:text-3xl">{{ $s['suffix'] }}</span>@endif
                        </div>
                        <div class="mt-1.5 text-[13px] font-medium text-cream/60">{{ $s['label'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="relative my-9 h-px bg-gradient-to-r from-transparent via-white/15 to-transparent"></div>

            {{-- download app --}}
            <div class="relative grid items-center gap-10 lg:grid-cols-[1.3fr_1fr]">
                <div>
                    <span class="nx-gradient inline-flex items-center gap-2 rounded-full px-3 py-1 text-[11px] font-bold tracking-widest">
                        @if ($app)
                            <span class="relative flex h-1.5 w-1.5"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-white/80"></span><span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-white"></span></span>
                            พร้อมโหลดแล้ววันนี้
                        @else
                            แอป NETWIX
                        @endif
                    </span>
                    <h2 class="mt-4 text-2xl font-extrabold sm:text-3xl">พก NetWix ไปได้ทุกที่</h2>
                    <p class="mt-3 max-w-md text-[15px] leading-relaxed text-cream/70">
                        ดาวน์โหลดตอนไว้ดูออฟไลน์ ปัดชมซีรีส์แนวตั้งลื่นไหล และดูต่อจากที่ค้างไว้ —
                        บนมือถือ <span class="font-semibold text-cream/90">แท็บเล็ต</span> และเว็บ
                        @if ($app)
                            แอป Android <span class="font-semibold text-cream/90">พร้อมให้โหลดแล้ววันนี้</span> (iOS เร็ว ๆ นี้)
                        @else
                            แอป NetWix กำลังจะมาเร็ว ๆ นี้
                        @endif
                    </p>

                    <div class="mt-6 flex flex-wrap items-center gap-3">
                        @if ($app)
                            {{-- APK is live → direct, prominent download (page carries the install guide) --}}
                            <a href="{{ route('download') }}" class="inline-flex items-center gap-3 rounded-xl bg-cream px-5 py-3 font-bold text-ink shadow-lg transition hover:brightness-90">
                                <svg viewBox="0 0 24 24" fill="currentColor" class="h-6 w-6"><path d="M12 16l-5-5h3V4h4v7h3l-5 5zM5 18h14v2H5z"/></svg>
                                <span>ดาวน์โหลดแอป Android<span class="block text-[11px] font-normal opacity-60">เวอร์ชัน {{ $app['version'] }} · ไฟล์ APK</span></span>
                            </a>
                            <span class="flex items-center gap-2.5 rounded-xl border border-white/15 bg-black/40 px-4 py-2.5">
                                <svg viewBox="0 0 24 24" fill="currentColor" class="h-6 w-6"><path d="M16.4 12.7c0-2.3 1.9-3.4 2-3.5-1.1-1.6-2.8-1.8-3.4-1.8-1.4-.1-2.8.9-3.5.9s-1.8-.9-3-.8c-1.5 0-3 .9-3.8 2.3-1.6 2.8-.4 7 1.2 9.3.8 1.1 1.7 2.4 2.9 2.3 1.2 0 1.6-.7 3-.7s1.8.7 3 .7 2-1.1 2.8-2.2c.9-1.3 1.2-2.5 1.3-2.6-.1 0-2.4-1-2.4-3.6zM14.2 5.9c.6-.8 1.1-1.9 1-3-1 0-2.1.6-2.8 1.4-.6.7-1.1 1.8-1 2.9 1.1.1 2.2-.5 2.8-1.3z"/></svg>
                                <span><span class="block text-[10px] text-cream/50">เร็ว ๆ นี้บน</span><span class="block text-sm font-bold">App Store</span></span>
                            </span>
                        @else
                            <a href="{{ route('download') }}" class="flex items-center gap-2.5 rounded-xl border border-white/15 bg-black/40 px-4 py-2.5 transition hover:border-white/35">
                                <svg viewBox="0 0 24 24" fill="currentColor" class="h-6 w-6"><path d="M3.6 2.3 13 12l-9.4 9.7c-.4-.3-.6-.8-.6-1.4V3.7c0-.6.2-1.1.6-1.4zm11 10.8 2.6 2.6-9 5.2 6.4-7.8zm3.9-2.2 2.3 1.3c.9.5.9 1.9 0 2.4l-2.3 1.3-2.9-2.5 2.9-2.5zM5.6 2 15 7.4l-2.6 2.7L5.6 2z"/></svg>
                                <span><span class="block text-[10px] text-cream/50">เร็ว ๆ นี้บน</span><span class="block text-sm font-bold">Google Play</span></span>
                            </a>
                            <span class="flex items-center gap-2.5 rounded-xl border border-white/15 bg-black/40 px-4 py-2.5">
                                <svg viewBox="0 0 24 24" fill="currentColor" class="h-6 w-6"><path d="M16.4 12.7c0-2.3 1.9-3.4 2-3.5-1.1-1.6-2.8-1.8-3.4-1.8-1.4-.1-2.8.9-3.5.9s-1.8-.9-3-.8c-1.5 0-3 .9-3.8 2.3-1.6 2.8-.4 7 1.2 9.3.8 1.1 1.7 2.4 2.9 2.3 1.2 0 1.6-.7 3-.7s1.8.7 3 .7 2-1.1 2.8-2.2c.9-1.3 1.2-2.5 1.3-2.6-.1 0-2.4-1-2.4-3.6zM14.2 5.9c.6-.8 1.1-1.9 1-3-1 0-2.1.6-2.8 1.4-.6.7-1.1 1.8-1 2.9 1.1.1 2.2-.5 2.8-1.3z"/></svg>
                                <span><span class="block text-[10px] text-cream/50">เร็ว ๆ นี้บน</span><span class="block text-sm font-bold">App Store</span></span>
                            </span>
                        @endif
                    </div>
                    <a href="{{ route('download') }}" class="mt-4 inline-block text-sm text-cream/60 underline-offset-4 hover:text-cream hover:underline">ดูรายละเอียดแอป ›</a>
                </div>

                {{-- device mockups: tablet + phone (works on both) --}}
                <div class="flex justify-center lg:justify-end">
                    <div class="relative h-[300px] w-[300px] sm:h-[340px] sm:w-[340px]">
                        {{-- tablet --}}
                        <div class="absolute left-0 top-3 h-[268px] w-[210px] rotate-[-5deg] rounded-[1.5rem] border-[7px] border-black/70 bg-ink shadow-2xl ring-1 ring-white/10 sm:h-[300px] sm:w-[234px]">
                            <div class="flex h-full w-full flex-col items-center justify-center gap-2.5 overflow-hidden rounded-[1rem]"
                                 style="background:radial-gradient(ellipse at 50% 0%, rgba(255,45,85,0.30), transparent 60%), linear-gradient(160deg,#160c28,#0a0713)">
                                <img src="{{ asset('assets/netwix-icon.png') }}" alt="" class="h-14 w-14 rounded-2xl shadow-lg">
                                <span class="text-[11px] font-bold tracking-widest text-cream/70">แท็บเล็ต</span>
                            </div>
                        </div>
                        {{-- phone (overlapping, front) --}}
                        <div class="absolute bottom-0 right-0 h-[224px] w-[112px] rotate-[7deg] rounded-[1.5rem] border-[5px] border-black/80 bg-ink shadow-2xl ring-1 ring-white/10 sm:h-[248px] sm:w-[124px]">
                            <div class="absolute left-1/2 top-1.5 z-10 h-1 w-10 -translate-x-1/2 rounded-full bg-black/70"></div>
                            <div class="flex h-full w-full flex-col items-center justify-center gap-2 overflow-hidden rounded-[1.05rem]"
                                 style="background:radial-gradient(ellipse at 50% 0%, rgba(176,38,255,0.40), transparent 60%), linear-gradient(160deg,#160c28,#0a0713)">
                                <img src="{{ asset('assets/netwix-icon.png') }}" alt="NetWix" class="h-11 w-11 rounded-xl shadow-lg">
                                <span class="text-[11px] font-bold tracking-wide">NetWix</span>
                                <span class="rounded-full border px-2.5 py-0.5 text-[10px] {{ $app ? 'border-emerald-400/40 text-emerald-300' : 'border-white/15 text-cream/60' }}">{{ $app ? 'โหลดได้แล้ว' : 'เร็ว ๆ นี้' }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

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
            ['ชวนเพื่อนได้สิทธิ์อะไร?', 'ทุกครั้งที่เพื่อนสมัครด้วยโค้ดแนะนำของคุณ ทั้งคุณและเพื่อนรับสิทธิ์ Pro ฟรี พร้อมเหรียญสะสมไว้แลกของรางวัล — ยิ่งชวนมาก ยิ่งได้มาก!'],
            ['ดูได้ที่ไหนบ้าง?', 'ดูได้ทุกที่ทุกเวลา — เข้าสู่ระบบผ่านเว็บ netwix.online บนอุปกรณ์ใดก็ได้ รองรับทั้งวิดีโอแบบปกติและ HLS สตรีมมิ่ง'],
            ['ซีรีส์แนวตั้งคืออะไร?', 'ซีรีส์สั้นแบบจอตั้ง (9:16) ตอนละไม่กี่นาที ปัดขึ้น–ลงเพื่อดูตอนถัดไปได้ทันทีเหมือนเล่นโซเชียล เหมาะกับการดูระหว่างเดินทาง'],
            ['ต้องเสียเงินไหม?', 'เริ่มดูได้ฟรี ไม่ต้องผูกบัตร และปลดล็อกสิทธิ์ Pro เพิ่มได้ฟรี ๆ แค่ชวนเพื่อนหรือร่วมกิจกรรมสะสมเหรียญ'],
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
            <p class="text-[15px] text-cream/65">ชวนเพื่อนวันนี้ รับสิทธิ์ Pro ฟรีและของรางวัลมากมาย</p>
            @include('frontend.partials.cta-start')
        </div>
    </section>

    {{-- ================= FOOTER ================= --}}
    @include('partials.site-footer')
</div>
@endsection
