@guest
    <dialog x-ref="signupDialog" @cancel.prevent="closeSignup()"
            aria-labelledby="guest-signup-title"
            class="m-auto max-h-[90dvh] max-w-sm overflow-y-auto rounded-2xl border border-white/15 bg-surface p-6 text-center text-cream shadow-2xl backdrop:bg-black/80"
            style="width:calc(100% - 2rem)"
            @wheel.stop @touchstart.stop @touchend.stop>
        <div class="mb-3 text-4xl" aria-hidden="true">🎬</div>
        <h2 id="guest-signup-title" class="text-xl font-bold">สมัครฟรี เพื่อดูต่อ</h2>
        <p class="mt-3 text-sm leading-relaxed text-cream/70">
            @if ($guestWatch->limit() > 0)
                ดูฟรีได้ {{ $guestWatch->limit() }} ตอนแรกต่อเรื่อง<br>
            @endif
            สมัครสมาชิกฟรี แล้วกลับมาดูตอนนี้ต่อได้เลย
        </p>
        @if (filled(config('services.line.client_id')))
            <p class="mt-2 text-sm text-cream/70">สมัครด้วย LINE ได้ ไม่ต้องตั้งรหัสผ่านใหม่</p>
        @endif
        <a :href="signupUrl" class="btn-brand mt-5 block w-full py-3 text-base">สมัครเลย</a>
        <p class="mt-3 text-xs leading-relaxed text-cream/50">มีบัญชีแล้ว? เข้าสู่ระบบได้ที่หน้าสมัคร</p>
        <button type="button" @click="closeSignup()"
                class="mt-4 text-sm text-cream/60 underline hover:text-cream">กลับไปเลือกตอน</button>
    </dialog>
@endguest
