{{-- One vertical (9:16) title card — click opens the detail modal, hover plays the ep1 preview.
     Shared by the /vertical page's initial rows and the lazy browse.row append (partials.vertical-cards). --}}
<div x-data="nxCardPreview(@js($content->preview_url))" x-init="$nextTick(() => initPreview())"
     @mouseenter="hoverCapable && arm()" @mouseleave="hoverCapable && release()"
     @click="$dispatch('open-title', '{{ route('title.modal', $content) }}')"
     @keydown.enter="$dispatch('open-title', '{{ route('title.modal', $content) }}')"
     role="button" tabindex="0"
     class="group block w-[132px] shrink-0 cursor-pointer sm:w-[150px] md:w-[168px]">
    <div class="relative aspect-[9/16] overflow-hidden rounded-xl ring-1 ring-white/5 transition group-hover:ring-2 group-hover:ring-white/25"
         style="background:{{ $content->gradient }}">
        @if ($content->poster_url)
            <img src="{{ $content->poster_url }}" alt="{{ $content->title }}" loading="lazy"
                 referrerpolicy="no-referrer" onerror="this.style.display='none'"
                 class="absolute inset-0 h-full w-full object-cover object-top">
        @else
            <div class="absolute inset-0 flex items-center justify-center">
                <img src="{{ asset('assets/netwix-icon.png') }}" alt="" class="h-12 w-12 opacity-30">
            </div>
        @endif
        @if ($content->preview_url)
            <video x-ref="clip" aria-hidden="true" data-src="{{ $content->preview_url }}"
                   muted loop playsinline preload="none"
                   class="absolute inset-0 h-full w-full object-cover object-top transition-opacity duration-300"
                   :class="hv ? 'opacity-100' : 'opacity-0'"></video>
        @endif
        <div class="absolute inset-0 flex items-center justify-center opacity-0 transition group-hover:opacity-100" style="background:rgba(0,0,0,0.35)">
            <span class="flex h-11 w-11 items-center justify-center rounded-full bg-cream/90 text-ink">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
            </span>
        </div>
        <div class="absolute left-2 top-2 z-10 flex flex-col items-start gap-1">
            @if ($content->is_original)
                <span class="nx-gradient rounded px-1.5 py-0.5 text-[9px] font-bold tracking-widest">NETWIX</span>
            @endif
            @if ($content->requires_pro)
                <span class="flex items-center gap-0.5 rounded bg-gradient-to-r from-gold to-[#ffcf5a] px-1.5 py-0.5 text-[9px] font-extrabold tracking-wide text-black shadow" title="ต้องเป็นสมาชิก Pro">👑 PRO</span>
            @endif
            @if ($content->dub_label)
                <span class="rounded px-1.5 py-0.5 text-[9px] font-bold {{ $content->dub_type === 'thai_dub' ? 'bg-emerald-500/90 text-black' : 'bg-sky-500/90 text-black' }}">{{ $content->dub_label }}</span>
            @endif
        </div>
        <div class="pointer-events-none absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/85 to-transparent p-2.5">
            <div class="truncate text-[13px] font-semibold">{{ $content->title }}</div>
            <div class="text-[11px] text-cream/60">{{ $content->episodes_count ?? $content->episodes->count() }} ตอน</div>
            {{-- view count (👁) — "ยังไม่มีคนดู" when nobody has watched yet (matches content-card) --}}
            <div class="mt-0.5 flex items-center gap-1 truncate text-[11px] {{ $content->views > 0 ? 'text-cream/60' : 'text-cream/40' }}">
                <svg class="h-3 w-3 shrink-0 opacity-70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>
                </svg>
                <span class="truncate">{{ $content->views_label }}</span>
            </div>
        </div>
    </div>
</div>
