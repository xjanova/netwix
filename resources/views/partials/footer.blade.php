<footer class="mt-16 border-t border-white/5 px-[4vw] py-10 text-cream/45">
    <img src="{{ asset('assets/netwix-logo.png') }}" alt="NetWix" class="h-5 mb-5 opacity-70">
    <div class="grid grid-cols-2 gap-3 text-[13px] sm:grid-cols-4 max-w-3xl">
        <a href="{{ route('browse') }}" class="hover:text-cream">หน้าแรก</a>
        <a href="{{ route('browse.series') }}" class="hover:text-cream">ซีรี่ส์</a>
        <a href="{{ route('browse.movies') }}" class="hover:text-cream">ภาพยนตร์</a>
        <a href="{{ route('browse.vertical') }}" class="hover:text-cream">ซีรีส์แนวตั้ง</a>
        <span>ศูนย์ช่วยเหลือ</span>
        <span>เงื่อนไขการใช้งาน</span>
        <span>ความเป็นส่วนตัว</span>
        <span>ติดต่อเรา</span>
    </div>
    <p class="mt-6 text-xs text-cream/35">© {{ date('Y') }} NetWix — บริการสตรีมมิ่งภาพยนตร์และซีรีส์</p>
</footer>
