@php
    $links = [
        ['label' => 'หน้าแรก', 'route' => 'browse'],
        ['label' => 'ซีรี่ส์', 'route' => 'browse.series'],
        ['label' => 'ภาพยนตร์', 'route' => 'browse.movies'],
        ['label' => 'ซีรีส์แนวตั้ง', 'route' => 'browse.vertical'],
        ['label' => 'รายการของฉัน', 'route' => 'browse.mylist'],
    ];
@endphp
<nav
    x-data="{ scrolled: false, searchOpen: false, q: '', results: [], menu: false, mobile: false,
        async search() {
            if (this.q.trim() === '') { this.results = []; return; }
            try {
                const r = await fetch('{{ route('search.suggest') }}?q=' + encodeURIComponent(this.q),
                    { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                this.results = (await r.json()).results;
            } catch (e) { this.results = []; }
        } }"
    x-on:scroll.window="scrolled = window.scrollY > 40"
    :class="scrolled ? 'bg-ink/95 backdrop-blur' : 'bg-gradient-to-b from-black/80 to-transparent'"
    class="fixed inset-x-0 top-0 z-[100] transition-colors duration-300"
>
    <div class="flex items-center justify-between px-[4vw] py-4">
        {{-- Left: logo + desktop links --}}
        <div class="flex items-center gap-9">
            <button class="lg:hidden flex flex-col gap-1 p-1.5" @click="mobile = true" aria-label="เมนู">
                <span class="block h-0.5 w-5 bg-cream"></span>
                <span class="block h-0.5 w-5 bg-cream"></span>
                <span class="block h-0.5 w-5 bg-cream"></span>
            </button>
            <a href="{{ route('browse') }}"><img src="{{ asset('assets/netwix-logo.png') }}" alt="NetWix" class="h-6 w-auto"></a>
            <div class="hidden lg:flex items-center gap-6">
                @foreach ($links as $link)
                    <a href="{{ route($link['route']) }}"
                       class="text-sm transition {{ request()->routeIs($link['route']) ? 'text-cream font-semibold' : 'text-cream/65 hover:text-cream' }}">
                        {{ $link['label'] }}
                    </a>
                @endforeach
            </div>
        </div>

        {{-- Right: search + profile --}}
        <div class="flex items-center gap-5">
            <div class="relative flex items-center gap-2">
                <button @click="searchOpen = !searchOpen; if(searchOpen) $nextTick(() => $refs.search.focus())" aria-label="ค้นหา"
                        class="text-cream/90 hover:text-cream">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                </button>
                <input x-show="searchOpen" x-cloak x-ref="search" type="text" placeholder="ค้นหา ชื่อเรื่อง แนว…"
                       x-model="q" @input.debounce.300ms="search()"
                       @keydown.enter="window.location='{{ route('search') }}?q=' + encodeURIComponent(q)"
                       class="w-40 sm:w-56 rounded bg-ink/90 border border-white/25 px-3 py-2 text-sm outline-none focus:border-brand">
                <div x-show="searchOpen && results.length" x-cloak
                     class="absolute right-0 top-11 w-72 rounded-lg border border-white/10 bg-surface shadow-2xl overflow-hidden z-[150]">
                    <template x-for="r in results" :key="r.url">
                        <a :href="r.url" class="flex items-center justify-between gap-3 px-4 py-3 text-sm border-b border-white/5 hover:bg-white/5">
                            <span x-text="r.title"></span><span class="text-cream/40 text-xs" x-text="r.year"></span>
                        </a>
                    </template>
                </div>
            </div>

            <div class="relative">
                <button @click="menu = !menu" class="flex h-8 w-8 items-center justify-center rounded-md font-bold text-black/60"
                        style="background: {{ $currentProfile->avatar_color ?? '#8b2ff0' }}">
                    {{ $currentProfile->initial ?? 'N' }}
                </button>
                <div x-show="menu" x-cloak @click.outside="menu = false"
                     class="absolute right-0 top-11 w-52 rounded-lg border border-white/10 bg-ink/95 backdrop-blur shadow-2xl overflow-hidden z-[150]">
                    @foreach ($otherProfiles ?? [] as $op)
                        <form method="POST" action="{{ route('profiles.select', $op) }}">
                            @csrf
                            <button class="flex w-full items-center gap-2.5 px-3.5 py-2.5 text-left hover:bg-white/5">
                                <span class="flex h-6 w-6 items-center justify-center rounded text-[11px] font-bold text-black/60" style="background: {{ $op->avatar_color }}">{{ $op->initial }}</span>
                                <span class="text-[13px]">{{ $op->name }}</span>
                            </button>
                        </form>
                    @endforeach
                    <a href="{{ route('profiles.index') }}" class="block px-3.5 py-2.5 text-[13px] text-cream/60 border-t border-white/10 hover:text-cream">จัดการโปรไฟล์</a>
                    @if (auth()->user()->isAdmin())
                        <a href="{{ route('admin.dashboard') }}" class="block px-3.5 py-2.5 text-[13px] text-cream/60 border-t border-white/10 hover:text-cream">แผงผู้ดูแล</a>
                    @endif
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="block w-full px-3.5 py-2.5 text-left text-[13px] text-cream/60 border-t border-white/10 hover:text-cream">ออกจากระบบ</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- Mobile drawer --}}
    <div x-show="mobile" x-cloak class="lg:hidden">
        <div class="fixed inset-0 bg-black/60 z-[180]" @click="mobile = false"></div>
        <div class="fixed inset-y-0 left-0 z-[190] w-[78vw] max-w-[300px] bg-ink-2 p-6 shadow-2xl overflow-y-auto">
            <img src="{{ asset('assets/netwix-logo.png') }}" alt="NetWix" class="h-6 mb-6">
            @foreach ($links as $link)
                <a href="{{ route($link['route']) }}" class="block border-b border-white/10 py-4 text-base">{{ $link['label'] }}</a>
            @endforeach
            <form method="POST" action="{{ route('logout') }}" class="mt-4">
                @csrf<button class="py-4 text-base text-cream/60">ออกจากระบบ</button>
            </form>
        </div>
    </div>
</nav>
