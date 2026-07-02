{{--
    Faint animated-logo background for the entry pages (login / register / setup / profiles).
    The clips are a glowing NetWix logo on near-black, so `mix-blend-screen` drops the black
    and only the glow shows subtly over the page's gradient. While the video buffers (or if it
    can't load), the centered logo behind it is what shows.

    Params: $video = desktop (landscape) clip filename in public/assets. Mobile always uses the
    portrait clip (logomedia3).
--}}
<div class="pointer-events-none absolute inset-0 -z-10 overflow-hidden" aria-hidden="true">
    {{-- fallback logo (visible while the clip loads / if it fails) --}}
    <img src="{{ asset('assets/netwix-icon.png') }}" alt=""
         class="absolute left-1/2 top-1/2 w-[42vmin] max-w-[360px] -translate-x-1/2 -translate-y-1/2 opacity-[0.12] blur-[1px]">

    {{-- desktop (landscape) --}}
    <video class="absolute inset-0 hidden h-full w-full object-cover opacity-70 mix-blend-screen sm:block"
           autoplay muted loop playsinline preload="auto">
        <source src="{{ asset('assets/'.($video ?? 'logomedia1.mp4')) }}" type="video/mp4">
    </video>

    {{-- mobile (portrait) --}}
    <video class="absolute inset-0 h-full w-full object-cover opacity-70 mix-blend-screen sm:hidden"
           autoplay muted loop playsinline preload="auto">
        <source src="{{ asset('assets/logomedia3.mp4') }}" type="video/mp4">
    </video>
</div>
