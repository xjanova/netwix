@php
    // Rongyok bakes its own "โรงหยก / rongyok.com" mark into the bottom of every vertical clip.
    // For those we lay a single-colour frosted-glass band over that area and put the NetWix wordmark
    // on it, so the clip reads as ours. Every other player just gets a clear channel logo, top-left.
    $isRongyokVertical = (($content->source ?? null) === 'rongyok') && (($content->type ?? null) === 'vertical');
@endphp

@if ($isRongyokVertical)
    {{-- Frosted band masking the source's baked-in watermark, NetWix wordmark centred on the glass. --}}
    <div class="pointer-events-none absolute inset-x-0 bottom-[13%] z-20 flex select-none justify-center" aria-hidden="true">
        <div class="flex items-center justify-center px-8 py-2.5"
             style="width:100%;background:rgba(16,12,26,0.5);backdrop-filter:blur(12px) saturate(1.15);-webkit-backdrop-filter:blur(12px) saturate(1.15);border-top:1px solid rgba(255,255,255,0.07);border-bottom:1px solid rgba(255,255,255,0.07)">
            <img src="{{ asset('assets/netwix-wordmark.png') }}" alt="NetWix" class="h-[22px] w-auto opacity-95"
                 style="filter:drop-shadow(0 1px 5px rgba(0,0,0,0.85))">
        </div>
    </div>
@else
    {{-- Standard player: a clearly-visible channel logo, top-left below the top bar. --}}
    <div class="pointer-events-none absolute left-4 top-[62px] z-20 select-none opacity-80 sm:left-6 sm:top-[70px]"
         style="filter:drop-shadow(0 2px 7px rgba(0,0,0,0.85))" aria-hidden="true">
        <img src="{{ asset('assets/netwix-wordmark.png') }}" alt="" class="h-6 w-auto sm:h-7">
    </div>
@endif
