{{-- Public footer — shared by the landing page and the info pages. --}}
<footer class="border-t border-white/5 px-[5vw] py-12 text-cream/45">
    <div class="mx-auto max-w-6xl">
        <img src="{{ asset('assets/netwix-wordmark.png') }}" alt="NetWix" class="mb-6 h-9 opacity-70">
        <div class="grid grid-cols-2 gap-x-6 gap-y-2.5 text-[13px] sm:grid-cols-4">
            <div class="flex flex-col gap-2.5">
                <span class="mb-1 text-[11px] font-semibold uppercase tracking-wider text-cream/35">NetWix</span>
                <a href="{{ route('home') }}" class="hover:text-cream">หน้าแรก</a>
                <a href="{{ route('login') }}" class="hover:text-cream">เข้าสู่ระบบ</a>
                <a href="{{ route('register') }}" class="hover:text-cream">สมัครสมาชิก</a>
            </div>
            <div class="flex flex-col gap-2.5">
                <span class="mb-1 text-[11px] font-semibold uppercase tracking-wider text-cream/35">แอปและอุปกรณ์</span>
                <a href="{{ route('download') }}" class="hover:text-cream">ดาวน์โหลดแอป</a>
                <a href="{{ route('download') }}#devices" class="hover:text-cream">อุปกรณ์ที่รองรับ</a>
            </div>
            <div class="flex flex-col gap-2.5">
                <span class="mb-1 text-[11px] font-semibold uppercase tracking-wider text-cream/35">ช่วยเหลือ</span>
                <a href="{{ route('help') }}" class="hover:text-cream">ศูนย์ช่วยเหลือ</a>
                <a href="{{ route('help') }}" class="hover:text-cream">ติดต่อเรา</a>
            </div>
            <div class="flex flex-col gap-2.5">
                <span class="mb-1 text-[11px] font-semibold uppercase tracking-wider text-cream/35">กฎหมาย</span>
                <a href="{{ route('privacy') }}" class="hover:text-cream">นโยบายความเป็นส่วนตัว</a>
                <a href="{{ route('terms') }}" class="hover:text-cream">เงื่อนไขการใช้งาน</a>
            </div>
        </div>
        <p class="mt-8 text-xs text-cream/35">© {{ date('Y') }} NetWix — บริการสตรีมมิ่งภาพยนตร์และซีรีส์ · netwix.online</p>
    </div>
</footer>
