@php
    // Type hubs + a few top genres in the header give Googlebot a strong internal-link graph on every
    // public page. Genres cached so this shared partial never adds a query per request.
    $navHubs = [
        ['label' => 'ซีรี่ส์', 'route' => 'browse.series'],
        ['label' => 'ภาพยนตร์', 'route' => 'browse.movies'],
        ['label' => 'อนิเมะ', 'route' => 'browse.anime'],
        ['label' => 'แนวตั้ง', 'route' => 'browse.vertical'],
    ];
    $navGenres = cache()->remember('public:nav-genres', 3600, fn () => \App\Models\Genre::orderBy('sort')->take(6)->get(['slug', 'name']));
@endphp
<nav x-data="{ scrolled: false, mobile: false, searchOpen: false, q: '', results: [],
        async search() {
            if (this.q.trim() === '') { this.results = []; return; }
            try {
                const r = await fetch('{{ route('search.suggest') }}?q=' + encodeURIComponent(this.q), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                this.results = (await r.json()).results;
            } catch (e) { this.results = []; }
        } }"
     x-on:scroll.window="scrolled = window.scrollY > 40"
     :class="scrolled ? 'bg-ink/95 backdrop-blur' : 'bg-gradient-to-b from-black/80 to-transparent'"
     class="fixed inset-x-0 top-0 z-[100] transition-colors duration-300">
    <div class="flex items-center justify-between px-[4vw] py-4">
        <div class="flex items-center gap-7">
            <button class="lg:hidden flex flex-col gap-1 p-1.5" @click="mobile = true" aria-label="เมนู">
                <span class="block h-0.5 w-5 bg-cream"></span>
                <span class="block h-0.5 w-5 bg-cream"></span>
                <span class="block h-0.5 w-5 bg-cream"></span>
            </button>
            <a href="{{ route('home') }}" aria-label="NetWix หน้าแรก"><img src="{{ asset('assets/netwix-wordmark.png') }}" alt="NetWix" class="h-8 w-auto"></a>
            <div class="hidden lg:flex items-center gap-5">
                @foreach ($navHubs as $h)
                    <a href="{{ route($h['route']) }}"
                       class="text-sm transition {{ request()->routeIs($h['route']) ? 'text-cream font-semibold' : 'text-cream/65 hover:text-cream' }}">{{ $h['label'] }}</a>
                @endforeach
            </div>
        </div>

        <div class="flex items-center gap-3">
            {{-- Search --}}
            <div class="relative flex items-center gap-2">
                <button @click="searchOpen = !searchOpen; if(searchOpen) $nextTick(() => $refs.search.focus())" aria-label="ค้นหา" class="text-cream/90 hover:text-cream">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                </button>
                <input x-show="searchOpen" x-cloak x-ref="search" type="text" placeholder="ค้นหา ชื่อเรื่อง แนว…"
                       x-model="q" @input.debounce.300ms="search()"
                       @keydown.enter="window.location='{{ route('search') }}?q=' + encodeURIComponent(q)"
                       class="w-40 rounded bg-ink/90 border border-white/25 px-3 py-2 text-sm outline-none focus:border-brand sm:w-56">
                <div x-show="searchOpen && results.length" x-cloak
                     class="absolute right-0 top-11 w-72 overflow-hidden rounded-lg border border-white/10 bg-surface shadow-2xl z-[150]">
                    <template x-for="r in results" :key="r.url">
                        <a :href="r.url" class="flex items-center justify-between gap-3 border-b border-white/5 px-4 py-3 text-sm hover:bg-white/5">
                            <span x-text="r.title"></span><span class="text-xs text-cream/40" x-text="r.year"></span>
                        </a>
                    </template>
                </div>
            </div>

            @auth
                <a href="{{ route('browse') }}" class="btn-brand rounded-lg px-4 py-2 text-sm font-semibold">เข้าคลังหนัง</a>
            @else
                <a href="{{ route('login') }}" class="hidden rounded-lg px-4 py-2 text-sm font-medium text-cream/80 transition hover:text-cream sm:inline-block">เข้าสู่ระบบ</a>
                <a href="{{ route('register') }}" class="btn-brand rounded-lg px-4 py-2 text-sm font-semibold">สมัครฟรี</a>
            @endauth
        </div>
    </div>

    {{-- Mobile drawer --}}
    <div x-show="mobile" x-cloak class="lg:hidden">
        <div class="fixed inset-0 bg-black/60 z-[180]" @click="mobile = false"></div>
        <div class="fixed inset-y-0 left-0 z-[190] w-[78vw] max-w-[300px] overflow-y-auto bg-ink-2 p-6 shadow-2xl">
            <a href="{{ route('home') }}"><img src="{{ asset('assets/netwix-wordmark.png') }}" alt="NetWix" class="mb-6 h-9"></a>
            @foreach ($navHubs as $h)
                <a href="{{ route($h['route']) }}" class="block border-b border-white/10 py-3 text-base">{{ $h['label'] }}</a>
            @endforeach
            <span class="mb-2 mt-5 block text-[11px] font-semibold uppercase tracking-wider text-cream/35">หมวดหมู่</span>
            @foreach ($navGenres as $g)
                <a href="{{ route('browse.genre', $g) }}" class="block border-b border-white/10 py-2.5 text-[15px] text-cream/75">{{ $g->name }}</a>
            @endforeach
            <div class="mt-5 flex flex-col gap-2">
                @auth
                    <a href="{{ route('browse') }}" class="btn-brand rounded-lg px-4 py-2.5 text-center text-sm font-semibold">เข้าคลังหนัง</a>
                @else
                    <a href="{{ route('register') }}" class="btn-brand rounded-lg px-4 py-2.5 text-center text-sm font-semibold">สมัครฟรี</a>
                    <a href="{{ route('login') }}" class="rounded-lg border border-white/15 px-4 py-2.5 text-center text-sm">เข้าสู่ระบบ</a>
                @endauth
            </div>
        </div>
    </div>
</nav>
