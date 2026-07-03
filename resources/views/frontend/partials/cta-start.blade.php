{{-- Landing call-to-action. Email capture when email sign-up is on; otherwise a
     plain "start" button (registration is social-only). --}}
@if ($emailReg ?? true)
    <form method="GET" action="{{ route('register') }}" class="mx-auto mt-4 flex max-w-xl flex-col gap-3 sm:flex-row">
        <input type="email" name="email" required placeholder="ที่อยู่อีเมล" class="nx-input flex-1 py-4 text-base">
        <button type="submit" class="btn-brand whitespace-nowrap px-8 py-4 text-lg">เริ่มกันเลย ›</button>
    </form>
@else
    <div class="mx-auto mt-5 flex max-w-xl flex-col items-center gap-3 sm:flex-row sm:justify-center">
        <a href="{{ route('register') }}" class="btn-brand w-full px-8 py-4 text-center text-lg sm:w-auto">เริ่มดูฟรีเลย ›</a>
        <a href="{{ route('login') }}" class="w-full rounded-lg border border-white/20 px-8 py-4 text-center text-lg text-cream/85 hover:bg-white/5 sm:w-auto">เข้าสู่ระบบ</a>
    </div>
@endif
