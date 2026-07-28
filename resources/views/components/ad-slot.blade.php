@props(['name', 'content' => null, 'class' => ''])

{{--
    One named ad placement. Renders nothing at all — no wrapper, no reserved space — when the viewer
    shouldn't see ads (Pro member, adult title, ads switched off) or when the slot isn't configured,
    so an unsold slot leaves no gap in the layout.

    Three shapes, decided in [App\Support\Ads::slot]:
      adsense → a standard responsive <ins class="adsbygoogle"> unit
      house   → one of our OWN uploaded banners (see [App\Models\HouseBanner])
      custom  → raw markup from another network, pasted by an admin (see the note on trust in Ads.php)
--}}
@php($nxAd = \App\Support\Ads::slot($name, auth()->user(), $content))

@if ($nxAd)
    <div class="nx-ad nx-ad-{{ $name }} {{ $class }}" data-ad-slot="{{ $name }}">
        <div class="mx-auto max-w-[970px] px-4 py-3">
            {{-- Labelled because an ad that reads as editorial content is both a dark pattern and,
                 for AdSense specifically, a policy violation. --}}
            <div class="mb-1 text-[10px] uppercase tracking-wider text-cream/25">โฆษณา</div>

            @if ($nxAd['kind'] === 'house')
                {{-- Our own creative. rel="sponsored nofollow" keeps a paid/self promo from passing
                     link equity, and the click goes through /b/{id} so it can be counted. --}}
                @if ($nxAd['link'])
                    <a href="{{ route('house-banner.click', $nxAd['id']) }}" target="_blank"
                       rel="sponsored nofollow noopener" class="block">
                        <img src="{{ $nxAd['src'] }}" alt="{{ $nxAd['name'] ?? 'โฆษณา' }}" loading="lazy"
                             class="mx-auto h-auto w-full max-w-full rounded-lg">
                    </a>
                @else
                    <img src="{{ $nxAd['src'] }}" alt="{{ $nxAd['name'] ?? 'โฆษณา' }}" loading="lazy"
                         class="mx-auto h-auto w-full max-w-full rounded-lg">
                @endif

            @elseif ($nxAd['kind'] === 'adsense')
                <ins class="adsbygoogle"
                     style="display:block"
                     data-ad-client="{{ $nxAd['client'] }}"
                     data-ad-slot="{{ $nxAd['unit'] }}"
                     data-ad-format="auto"
                     data-full-width-responsive="true"></ins>
                <script>(adsbygoogle = window.adsbygoogle || []).push({});</script>
            @else
                {!! $nxAd['html'] !!}
            @endif
        </div>
    </div>
@endif
