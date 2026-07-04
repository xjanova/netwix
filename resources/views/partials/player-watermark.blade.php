@php
    // A clear NetWix channel logo, top-left. Rongyok verticals used to get a frosted band masking
    // their baked-in "โรงหยก" mark; per request that band is gone — instead these get a noticeably
    // larger top-left logo so the clip still reads as ours.
    $isRongyokVertical = (($content->source ?? null) === 'rongyok') && (($content->type ?? null) === 'vertical');
@endphp

<div x-show="ui" x-transition.opacity
     class="pointer-events-none absolute left-4 z-20 select-none opacity-90 sm:left-6 {{ $isRongyokVertical ? 'top-4 sm:top-5' : 'top-[62px] sm:top-[70px]' }}"
     style="filter:drop-shadow(0 2px 8px rgba(0,0,0,0.9))" aria-hidden="true">
    <img src="{{ asset('assets/netwix-wordmark.png') }}" alt="{{ $isRongyokVertical ? 'NetWix' : '' }}"
         class="w-auto {{ $isRongyokVertical ? 'h-10 sm:h-12' : 'h-6 sm:h-7' }}">
</div>
