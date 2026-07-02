{{--
    Faint animated-logo background for the entry pages (login / register / setup / profiles).
    The clips are a glowing NetWix logo on near-black, so `mix-blend-screen` drops the black
    and only the glow shows subtly over the page's gradient. While the video buffers (or if it
    can't load / JS is off), the centered logo behind it is what shows.

    Only ONE clip is ever downloaded: the landscape $video on wider screens, the portrait
    logomedia3 on phones — chosen at load time so mobile visitors don't pull the desktop file
    (and vice-versa).

    Params: $video = desktop (landscape) clip filename in public/assets.
--}}
<div class="pointer-events-none absolute inset-0 -z-10 overflow-hidden" aria-hidden="true">
    {{-- fallback logo (visible while the clip loads / if it fails / JS off) --}}
    <img src="{{ asset('assets/netwix-icon.png') }}" alt=""
         class="absolute left-1/2 top-1/2 w-[42vmin] max-w-[360px] -translate-x-1/2 -translate-y-1/2 opacity-[0.12] blur-[1px]">

    <video class="absolute inset-0 h-full w-full object-cover opacity-70 mix-blend-screen"
           muted loop playsinline preload="none"
           data-desktop="{{ asset('assets/'.($video ?? 'logomedia1.mp4')) }}"
           data-mobile="{{ asset('assets/logomedia3.mp4') }}"></video>
    <script>
        (function () {
            var v = document.currentScript.previousElementSibling;
            v.src = window.matchMedia('(min-width: 640px)').matches ? v.dataset.desktop : v.dataset.mobile;
            var p = v.play();
            if (p && p.catch) { p.catch(function () {}); }
        })();
    </script>
</div>
