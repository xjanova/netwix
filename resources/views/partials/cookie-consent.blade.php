{{-- Essential-cookie notice — dismissed choice remembered in localStorage. --}}
<div x-data="{ show: false }" x-init="show = !localStorage.getItem('nx_cookie_ok')"
     x-show="show" x-cloak x-transition.opacity.duration.300ms
     class="fixed inset-x-0 bottom-0 z-[80] p-3 sm:p-4">
    <div class="mx-auto flex max-w-3xl flex-col items-center gap-3 rounded-2xl border border-white/10 p-4 shadow-2xl sm:flex-row"
         style="background:rgba(20,15,30,0.94);backdrop-filter:blur(10px)">
        <span class="text-xl" aria-hidden="true">🍪</span>
        <p class="flex-1 text-center text-[13px] leading-relaxed text-cream/75 sm:text-left">
            เราใช้คุกกี้ที่จำเป็นเพื่อให้ระบบทำงานและจดจำการตั้งค่าของคุณ ·
            <span class="text-cream/50">We use essential cookies to run the site.</span>
            <a href="{{ route('privacy') }}" class="text-brand hover:underline">นโยบายความเป็นส่วนตัว</a>
        </p>
        <button type="button" @click="localStorage.setItem('nx_cookie_ok', '1'); show = false"
                class="btn-brand shrink-0 rounded-full px-6 py-2 text-sm font-semibold">ยอมรับ · Accept</button>
    </div>
</div>
