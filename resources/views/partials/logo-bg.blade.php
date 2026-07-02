{{--
    Faint animated-logo background for the entry pages (login / register / setup / profiles).
    The clips are a glowing NetWix logo on near-black, so `mix-blend-screen` drops the black
    and only the glow shows over the page's gradient. While the clip loads (or if it can't),
    the centered logo behind it is what shows.

    ONE <video> with native autoplay — reliable playback, a single download, and no script
    (this markup also gets injected into the AJAX title modal, where <script> wouldn't run).
    The right clip is chosen server-side from the User-Agent: phones get the portrait clip,
    everything else the landscape one.

    Params: $video = desktop (landscape) clip filename in public/assets.
--}}
@php
    $mobile = (bool) preg_match('/iPhone|iPod|Windows Phone|Android.+Mobile/i', request()->userAgent() ?? '');
    $clip = $mobile ? 'logomedia3.mp4' : ($video ?? 'logomedia1.mp4');
@endphp
<div class="pointer-events-none absolute inset-0 -z-10 overflow-hidden" aria-hidden="true">
    {{-- fallback logo (visible while the clip loads / if it fails) --}}
    <img src="{{ asset('assets/netwix-icon.png') }}" alt=""
         class="absolute left-1/2 top-1/2 w-[42vmin] max-w-[360px] -translate-x-1/2 -translate-y-1/2 opacity-[0.12] blur-[1px]">

    <video class="absolute inset-0 h-full w-full object-cover opacity-80 mix-blend-screen"
           autoplay muted loop playsinline preload="auto">
        <source src="{{ asset('assets/'.$clip) }}" type="video/mp4">
    </video>
</div>
