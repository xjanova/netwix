{{-- Public top bar — shared by the info pages. --}}
<header class="sticky top-0 z-30 flex items-center justify-between border-b border-white/5 bg-ink/80 px-[5vw] py-4 backdrop-blur">
    <a href="{{ route('home') }}" class="flex items-center">
        <img src="{{ asset('assets/netwix-wordmark.png') }}" alt="NetWix" class="h-12 w-auto sm:h-14">
    </a>
    <div class="flex items-center gap-3">
        <a href="{{ route('login') }}" class="text-sm text-cream/70 hover:text-cream">เข้าสู่ระบบ</a>
        <a href="{{ route('register') }}" class="btn-brand px-5 py-2 text-sm">สมัครสมาชิก</a>
    </div>
</header>
