{{-- Faint NetWix wordmark shown over every player (channel-logo style). Sits below the top bar so
     it never clashes with the back / episode / fullscreen buttons, and ignores pointer events. --}}
<div class="pointer-events-none absolute right-4 top-[64px] z-20 select-none opacity-[0.28] sm:right-6 sm:top-[70px]"
     style="filter:drop-shadow(0 1px 5px rgba(0,0,0,0.75))" aria-hidden="true">
    <img src="{{ asset('assets/netwix-wordmark.png') }}" alt="" class="h-3.5 w-auto sm:h-4">
</div>
