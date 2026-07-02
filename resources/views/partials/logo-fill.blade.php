{{--
    Fallback for a preview area (hero / title modal) that has no trailer AND no image:
    fill it with an animated NetWix logo clip instead of a dead static frame.

    The landscape clip is picked from the two options by $seed (content id) so titles don't
    all look identical but each title is stable across renders; phones get the portrait clip.
    Two <video>s + CSS + preload="none" → only the shown clip autoplays/downloads. Script-free
    so it survives being injected into the AJAX title modal.

    Params: $seed = stable integer (content id) used to pick logomedia1 vs logomedia2.
--}}
@php $desktop = (($seed ?? 0) % 2) ? 'logomedia2.mp4' : 'logomedia1.mp4'; @endphp
{{-- desktop (landscape) --}}
<video class="pointer-events-none absolute inset-0 hidden h-full w-full object-cover opacity-90 mix-blend-screen sm:block"
       autoplay muted loop playsinline preload="none">
    <source src="{{ asset('assets/'.$desktop) }}" type="video/mp4">
</video>
{{-- mobile (portrait) --}}
<video class="pointer-events-none absolute inset-0 h-full w-full object-cover opacity-90 mix-blend-screen sm:hidden"
       autoplay muted loop playsinline preload="none">
    <source src="{{ asset('assets/logomedia3.mp4') }}" type="video/mp4">
</video>
