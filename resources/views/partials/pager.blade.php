{{-- Windowed, jumpable pager. Pass the paginator as $p (e.g. @include('partials.pager', ['p' => $items])).
     The paginator is built with ->withQueryString(), so $p->url() keeps scope/type/sort/dir intact.

     These page links are deliberately FOLLOWED. They used to carry rel="nofollow", and that single
     attribute was the reason Google could not reach the catalogue: /series alone is 134 pages, and a
     nofollowed pager means there is no link path to any of them. Search Console said it plainly for a
     title page — "referring sitemap: none, referring page: none" — while robots.txt, the canonicals
     and the sitemap were all already correct. Pagination is the crawl path into a catalogue; the
     things that genuinely must not be followed are the SORT variants, which are nofollowed where they
     are rendered (hub + genre) and also blocked in robots.txt. --}}
@if ($p->hasPages())
    @php
        $cur = $p->currentPage();
        $last = $p->lastPage();
        $win = 2;                       // pages shown on each side of the current one
        $lo = max(1, $cur - $win);
        $hi = min($last, $cur + $win);
        $btn = 'shrink-0 rounded-full px-3.5 py-1.5 transition';
        $on = 'nx-gradient font-semibold text-white';
        $off = 'bg-white/10 text-cream/80 hover:bg-white/20';
        $mute = 'bg-white/5 text-cream/25';
    @endphp
    <nav class="mt-9 flex flex-wrap items-center justify-center gap-1.5 text-sm" aria-label="แบ่งหน้า">
        @if ($p->onFirstPage())
            <span class="{{ $btn }} {{ $mute }}">‹</span>
        @else
            <a href="{{ $p->previousPageUrl() }}" class="{{ $btn }} {{ $off }}" aria-label="ก่อนหน้า">‹</a>
        @endif

        @if ($lo > 1)
            <a href="{{ $p->url(1) }}" class="{{ $btn }} {{ $off }}">1</a>
            @if ($lo > 2)<span class="px-1 text-cream/30">…</span>@endif
        @endif

        @for ($i = $lo; $i <= $hi; $i++)
            @if ($i === $cur)
                <span class="{{ $btn }} {{ $on }}" aria-current="page">{{ $i }}</span>
            @else
                <a href="{{ $p->url($i) }}" class="{{ $btn }} {{ $off }}">{{ $i }}</a>
            @endif
        @endfor

        @if ($hi < $last)
            @if ($hi < $last - 1)<span class="px-1 text-cream/30">…</span>@endif
            <a href="{{ $p->url($last) }}" class="{{ $btn }} {{ $off }}">{{ $last }}</a>
        @endif

        @if ($p->hasMorePages())
            <a href="{{ $p->nextPageUrl() }}" class="{{ $btn }} {{ $off }}" aria-label="ถัดไป">›</a>
        @else
            <span class="{{ $btn }} {{ $mute }}">›</span>
        @endif

        {{-- Jump to any page. Enter/blur navigates to that page (query string preserved). --}}
        <span class="ml-2 flex items-center gap-1 text-cream/40">
            <span class="hidden sm:inline">ไปหน้า</span>
            <input type="number" min="1" max="{{ $last }}" value="{{ $cur }}" inputmode="numeric"
                   onkeydown="if(event.key==='Enter'){event.preventDefault();this.blur();}"
                   onchange="var n=Math.max(1,Math.min({{ $last }},parseInt(this.value)||1));if(n!=={{ $cur }})location.href='{{ $p->url('__P__') }}'.replace('__P__',n);"
                   class="w-16 rounded-full bg-white/10 px-3 py-1.5 text-center text-cream focus:outline-none focus:ring-1 focus:ring-brand">
            <span>/ {{ number_format($last) }}</span>
        </span>
    </nav>
@endif
