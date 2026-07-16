@props(['content'])

{{-- Crawlable catalog card for the PUBLIC surface. Unlike the member <x-content-card> (which opens
     a JS modal and exposes no href), this is a plain <a> to the title page with real alt text — so
     Googlebot can actually follow it and walk the catalog. No Alpine, no auth assumptions. --}}
<a href="{{ route('title.show', $content) }}" title="{{ $content->title }}"
   class="group block w-[142px] shrink-0 sm:w-[160px] md:w-[178px]">
    <div class="relative aspect-[9/16] overflow-hidden rounded-xl ring-1 ring-white/5 transition duration-200 group-hover:ring-2 group-hover:ring-white/25"
         style="background:{{ $content->gradient }}">
        {{-- Branded fallback cover as the base layer — shown when there's no poster OR a hotlinked one
             is dead/blocked (onerror drops the <img>), so a card is never a blank box. --}}
        <div class="absolute inset-0 flex flex-col items-center justify-center gap-1.5 px-3 text-center">
            <img src="{{ asset('assets/netwix-icon.png') }}" alt="" class="h-9 w-9 opacity-40">
            <span class="line-clamp-2 text-[13px] font-semibold text-cream/80">{{ $content->title }}</span>
        </div>
        @if ($content->poster_url)
            <img src="{{ $content->poster_url }}" alt="{{ $content->title }} โปสเตอร์" loading="lazy"
                 referrerpolicy="no-referrer" onerror="nxHealCover(this, '{{ route('content.heal-cover', $content) }}')"
                 class="absolute inset-0 h-full w-full object-cover object-top">
        @endif

        <div class="absolute left-2 top-2 z-10 flex flex-col items-start gap-1">
            @if ($content->link_under_review)
                <span class="rounded bg-gold/90 px-1.5 py-0.5 text-[9px] font-bold text-black shadow" title="กำลังตรวจสอบลิงก์ อาจดูไม่ได้ชั่วคราว">🚧 ตรวจสอบลิงก์</span>
            @endif
            @if ($content->is_original)
                <span class="nx-gradient rounded px-1.5 py-0.5 text-[9px] font-bold tracking-widest">NETWIX</span>
            @endif
            @if ($content->dub_label)
                <span class="rounded px-1.5 py-0.5 text-[9px] font-bold {{ $content->dub_type === 'thai_dub' ? 'bg-emerald-500/90 text-black' : 'bg-sky-500/90 text-black' }}">{{ $content->dub_label }}</span>
            @endif
        </div>
        <span class="absolute right-2 top-2 z-10 rounded bg-black/55 px-1.5 py-0.5 text-[10px] font-semibold">{{ $content->maturity }}</span>
    </div>

    <div class="mt-2">
        <div class="truncate text-[13px] font-medium">{{ $content->title }}</div>
        <div class="truncate text-[11px] text-cream/45">
            {{ $content->primaryGenre()?->name }}{{ $content->year ? ' · '.$content->year : '' }}
        </div>
    </div>
</a>
