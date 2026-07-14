{{-- Running promo banner ("แถบวิ่งโปรโมชั่น") — active marketing campaigns from App\Support\Campaigns
     (config-derived free-Pro + referral promos, plus admin announcements). Reuses the .nx-marquee CSS
     (track holds the set twice → -50% loops seamlessly; pauses on hover; respects reduced-motion). --}}
@php $promos = \App\Support\Campaigns::active(auth()->guest()); @endphp
@if (count($promos))
    <div class="nx-marquee border-y border-brand/15 bg-gradient-to-r from-brand/10 via-white/[0.03] to-cyan-500/10 py-2.5">
        <div class="nx-marquee-track" style="--speed:{{ max(24, count($promos) * 13) }}s">
            @foreach (array_merge($promos, $promos) as $p)
                <a href="{{ $p['link'] }}" class="inline-flex items-center gap-2 whitespace-nowrap px-4 text-sm font-semibold text-cream/85 transition hover:text-white">
                    <span class="text-base leading-none">{{ $p['icon'] }}</span>
                    <span>{{ $p['text'] }}</span>
                    <span class="text-brand">›</span>
                </a>
            @endforeach
        </div>
    </div>
@endif
