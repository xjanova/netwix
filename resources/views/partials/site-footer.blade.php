{{-- Public footer — shared by the landing page and the info pages.
     "Millennium 3D" / Frutiger-Aero treatment: a floating frosted-glass slab with a beveled top
     highlight + outer brand glow (depth), a Y2K chrome accent edge, glossy beveled section chips,
     floating aero orbs, and a reflected wordmark. Fireflies kept (see nxFireflies in app.js). --}}
<footer class="relative mt-16 overflow-hidden px-[5vw] pb-10 pt-px text-cream/55">
    {{-- chrome accent edge: the classic glossy aqua→brand hairline across the very top --}}
    <div aria-hidden="true" class="absolute inset-x-0 top-0 h-px"
         style="background:linear-gradient(90deg,transparent 0%,rgba(0,229,255,.55) 22%,rgba(255,45,85,.65) 50%,rgba(176,38,255,.65) 74%,transparent 100%)"></div>

    {{-- floating fireflies (see nxFireflies in app.js) --}}
    <canvas id="nx-fireflies" aria-hidden="true" class="pointer-events-none absolute inset-0"></canvas>

    {{-- glossy aero orbs — 3D spheres peeking behind the glass slab for depth --}}
    <div aria-hidden="true" class="pointer-events-none absolute -left-12 top-10 h-44 w-44 rounded-full opacity-50 blur-[1px]"
         style="background:radial-gradient(circle at 34% 26%, #ffffff 0%, rgba(255,45,85,.85) 18%, rgba(255,45,85,.28) 52%, transparent 72%)"></div>
    <div aria-hidden="true" class="pointer-events-none absolute -right-16 -top-6 h-52 w-52 rounded-full opacity-45 blur-[1px]"
         style="background:radial-gradient(circle at 30% 24%, #ffffff 0%, rgba(0,229,255,.6) 16%, rgba(176,38,255,.5) 48%, transparent 72%)"></div>

    {{-- the frosted-glass slab --}}
    <div class="relative z-10 mx-auto mt-9 max-w-6xl rounded-[26px] p-8 sm:p-10"
         style="background:linear-gradient(180deg, rgba(255,255,255,.075) 0%, rgba(255,255,255,.02) 42%, rgba(7,5,12,.22) 100%);
                -webkit-backdrop-filter:blur(16px) saturate(150%); backdrop-filter:blur(16px) saturate(150%);
                box-shadow: inset 0 1px 0 0 rgba(255,255,255,.4), inset 0 0 0 1px rgba(255,255,255,.07), inset 0 -22px 40px -30px rgba(0,0,0,.7), 0 26px 64px -22px rgba(176,38,255,.42), 0 10px 34px -14px rgba(0,0,0,.65);">

        {{-- top sheen: the wet-glass highlight sweeping the upper edge --}}
        <div aria-hidden="true" class="pointer-events-none absolute inset-x-5 top-0 h-20 rounded-b-[50px] opacity-70"
             style="background:linear-gradient(180deg, rgba(255,255,255,.22) 0%, rgba(255,255,255,.05) 45%, transparent 100%)"></div>

        <div class="relative">
            {{-- wordmark on a gloss plate, with a real reflection under it --}}
            <img src="{{ asset('assets/netwix-wordmark.png') }}" alt="NetWix"
                 class="mb-7 h-9 opacity-85"
                 style="filter:drop-shadow(0 2px 6px rgba(255,45,85,.35)) drop-shadow(0 0 18px rgba(176,38,255,.25));
                        -webkit-box-reflect: below 1px linear-gradient(transparent 58%, rgba(255,255,255,.16));">

            <div class="grid grid-cols-2 gap-x-6 gap-y-3 text-[13px] sm:grid-cols-4">
                @php
                    $cols = [
                        ['NetWix', auth()->guest()
                            ? [['หน้าแรก', route('home')], ['เข้าสู่ระบบ', route('login')], ['สมัครสมาชิก', route('register')]]
                            : [['หน้าแรก', route('home')], ['เข้าชมคลังหนัง', route('browse')], ['บัญชีของฉัน', route('account')]]],
                        ['แอปและอุปกรณ์', [['ดาวน์โหลดแอป', route('download')], ['อุปกรณ์ที่รองรับ', route('download').'#devices']]],
                        ['ช่วยเหลือ', [['ศูนย์ช่วยเหลือ', route('help')], ['ติดต่อเรา', route('help')]]],
                        ['กฎหมาย', [['นโยบายความเป็นส่วนตัว', route('privacy')], ['เงื่อนไขการใช้งาน', route('terms')]]],
                    ];
                @endphp
                @foreach ($cols as [$label, $links])
                    <div class="flex flex-col items-start gap-2.5">
                        {{-- glossy beveled section chip --}}
                        <span class="mb-1.5 inline-flex rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-cream/75"
                              style="background:linear-gradient(180deg, rgba(255,255,255,.17) 0%, rgba(255,255,255,.035) 100%);
                                     box-shadow: inset 0 1px 0 rgba(255,255,255,.5), inset 0 -1px 0 rgba(0,0,0,.35), 0 2px 6px rgba(0,0,0,.3);">{{ $label }}</span>
                        @foreach ($links as [$text, $href])
                            <a href="{{ $href }}"
                               class="-mx-2 rounded-lg px-2 py-1 text-cream/55 transition-all duration-150 hover:translate-x-0.5 hover:bg-white/[0.06] hover:text-cream"
                               style="text-shadow:0 1px 1px rgba(0,0,0,.5)">{{ $text }}</a>
                        @endforeach
                    </div>
                @endforeach
            </div>

            {{-- glossy divider --}}
            <div aria-hidden="true" class="mt-8 h-px"
                 style="background:linear-gradient(90deg, transparent, rgba(255,255,255,.14) 30%, rgba(255,255,255,.14) 70%, transparent)"></div>

            <p class="mt-5 text-xs text-cream/40" style="text-shadow:0 1px 1px rgba(0,0,0,.5)">© {{ date('Y') }} NetWix — บริการสตรีมมิ่งภาพยนตร์และซีรีส์ · netwix.online</p>
        </div>
    </div>
</footer>
