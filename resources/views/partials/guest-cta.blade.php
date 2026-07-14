@guest
    @php
        // The headline promo comes straight from the LIVE campaign config (Campaigns::active),
        // so the banner always matches the real reward (e.g. "สมัครใหม่รับ Premium ฟรี 1 ปี").
        $nxPromo = \App\Support\Campaigns::active(true)[0] ?? null;
    @endphp
    <div x-data="{ open: true }" x-show="open" x-cloak
         class="pointer-events-none fixed inset-x-0 bottom-4 z-[70] flex justify-center px-4">
        <div class="pointer-events-auto flex max-w-xl items-center gap-3 rounded-full border border-white/15 bg-black/85 py-2 pl-4 pr-2 shadow-2xl backdrop-blur">
            <span class="text-lg leading-none">{{ $nxPromo['icon'] ?? '🎁' }}</span>
            <p class="min-w-0 text-[13px] leading-snug text-cream/90">
                {{ $nxPromo['text'] ?? 'สมัครสมาชิกฟรี รับสิทธิพิเศษตามแคมเปญ' }}
            </p>
            <a href="{{ route('register') }}"
               class="btn-brand shrink-0 rounded-full px-4 py-1.5 text-[13px] font-bold">สมัครฟรี</a>
            <button type="button" @click="open = false" aria-label="ปิด"
                    class="grid h-7 w-7 shrink-0 place-items-center rounded-full text-cream/50 transition hover:bg-white/10 hover:text-cream">✕</button>
        </div>
    </div>
@endguest
