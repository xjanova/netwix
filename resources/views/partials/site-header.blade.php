{{-- Top bar for the standalone info pages (download / help / privacy / terms).

     These pages are public, so they were built with a hardcoded login+register pair — which meant a
     signed-in member landing on them was shown "เข้าสู่ระบบ / สมัครสมาชิก" and reasonably concluded
     their session had been lost. Public and logged-out are not the same thing: the page stays open to
     everyone, the header just has to tell the truth about who is reading it. --}}
<header class="sticky top-0 z-30 flex items-center justify-between border-b border-white/5 bg-ink/80 px-[5vw] py-4 backdrop-blur">
    <a href="{{ route('home') }}" class="flex items-center">
        <img src="{{ asset('assets/netwix-wordmark.png') }}" alt="NetWix" class="h-12 w-auto sm:h-14">
    </a>

    <div class="flex items-center gap-2 sm:gap-3">
        @auth
            {{-- Straight back to what they were doing, plus a way into their account. The profile
                 avatar isn't used here: currentProfile is shared by the `profile` middleware, which
                 these public routes don't run, so it would render a meaningless placeholder. --}}
            <a href="{{ route('account') }}"
               class="hidden text-sm text-cream/70 transition hover:text-cream sm:inline-block">บัญชีของฉัน</a>
            <a href="{{ route('browse') }}" class="btn-brand px-5 py-2 text-sm">เข้าคลังหนัง</a>
        @else
            <a href="{{ route('login') }}" class="text-sm text-cream/70 transition hover:text-cream">เข้าสู่ระบบ</a>
            <a href="{{ route('register') }}" class="btn-brand px-5 py-2 text-sm">สมัครสมาชิก</a>
        @endauth
    </div>
</header>
