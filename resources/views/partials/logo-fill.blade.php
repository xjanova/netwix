{{--
    Fallback for a preview area (hero / title modal) that has no trailer AND no image:
    fill it with an animated NetWix logo clip instead of a dead static frame.

    ONE <video> with native autoplay (single download, no script — survives being injected
    into the AJAX title modal via x-html). The landscape clip is picked from the two options
    by $seed (content id) so titles don't all look identical but each stays stable across
    renders; phones get the portrait clip (chosen server-side from the User-Agent).

    Params: $seed = stable integer (content id) used to pick logomedia1 vs logomedia2.
--}}
@php
    $mobile = (bool) preg_match('/iPhone|iPod|Windows Phone|Android.+Mobile/i', request()->userAgent() ?? '');
    $clip = $mobile ? 'logomedia3.mp4' : ((($seed ?? 0) % 2) ? 'logomedia2.mp4' : 'logomedia1.mp4');
@endphp
<video class="pointer-events-none absolute inset-0 h-full w-full object-cover opacity-90 mix-blend-screen"
       autoplay muted loop playsinline preload="auto">
    <source src="{{ asset('assets/'.$clip) }}" type="video/mp4">
</video>
