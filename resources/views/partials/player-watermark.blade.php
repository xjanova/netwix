@php
    // A clear NetWix channel logo, top-left. Rongyok verticals used to get a frosted band masking
    // their baked-in "โรงหยก" mark; that band is gone — instead the clip carries a large top-left
    // wordmark. It's the SAME size in both players (landscape + vertical) and stays ALWAYS visible —
    // it does NOT fade with the auto-hiding chrome (no x-show="ui"), so the brand never disappears.
    $isRongyokVertical = (($content->source ?? null) === 'rongyok') && (($content->type ?? null) === 'vertical');
@endphp

<div class="pointer-events-none absolute left-4 z-20 select-none opacity-90 sm:left-6 {{ $isRongyokVertical ? 'top-4 sm:top-5' : 'top-[62px] sm:top-[70px]' }}"
     style="filter:drop-shadow(0 2px 8px rgba(0,0,0,0.9))" aria-hidden="true">
    <img src="{{ asset('assets/netwix-wordmark.png') }}" alt="NetWix"
         class="h-10 w-auto sm:h-12">
</div>
