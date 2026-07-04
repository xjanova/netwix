@php
    $heroYt = $content->youtube_id;
    $firstSeason = $content->seasons->first();

    // Hero auto-preview: play episode 1's stream in the header. Use the stored clip when we have it,
    // else resolve one on demand (rongyok / wow-drama).
    $previewEp = $content->episodes->first();
    $previewSrc = $previewEp?->video_url;
    $previewResolve = ($previewEp && ! $previewSrc && $previewEp->source && $previewEp->source_ref)
        ? route('episode.source', $previewEp) : null;
    $hasHeroPreview = $previewSrc || $previewResolve;

    // Adult (18+/20+) titles need Pro — swap the play button for an upgrade CTA when the viewer isn't Pro.
    $needsPro = $content->requires_pro && ! auth()->user()?->isProMember();

    $isModal = $modal ?? true;
@endphp
{{-- The card is a fixed-height flex COLUMN: the hero is a shrink-0 sibling (NOT inside the scroll
     body), so its aspect-ratio never reflows against the body's scrollbar (that combo froze the
     modal before), and the video is never position:sticky (a sticky playing <video> repaint-stormed).
     Only the lower body (review + episodes) scrolls; the video + badges + title + poster stay pinned.
     Same markup drives the pop-up and the full title page. --}}
<div x-data="{
        inList: @js($inMyList),
        liked: @js($liked),
        season: {{ $firstSeason->number ?? 1 }},
        busy: false,
        async toggleList() { if (this.busy) return; this.busy = true;
            try { this.inList = (await nxPost('{{ route('content.list', $content) }}')).in_list; } finally { this.busy = false; } },
        async toggleLike() { if (this.busy) return; this.busy = true;
            try { this.liked = (await nxPost('{{ route('content.like', $content) }}')).liked; } finally { this.busy = false; } },
     }"
     class="flex max-h-[88vh] flex-col">

    {{-- ═══════════ PINNED HERO (does not scroll) ═══════════ --}}
    <div class="relative aspect-video max-h-[52vh] w-full shrink-0 overflow-hidden bg-black">
        <div class="absolute inset-0" style="background:{{ $content->gradient }}"></div>
        @if ($content->backdrop_url)
            <img src="{{ $content->backdrop_url }}" alt="" aria-hidden="true"
                 referrerpolicy="no-referrer" onerror="this.style.display='none'"
                 class="absolute inset-0 h-full w-full object-cover object-[50%_30%]">
        @endif
        @if ($heroYt)
            <iframe src="https://www.youtube.com/embed/{{ $heroYt }}?autoplay=1&mute=1&loop=1&playlist={{ $heroYt }}&controls=0&modestbranding=1&rel=0&playsinline=1"
                    class="pointer-events-none absolute left-1/2 top-1/2 h-full w-full -translate-x-1/2 -translate-y-1/2 border-0"
                    style="min-width:178%;min-height:178%" allow="autoplay; encrypted-media"></iframe>
        @elseif ($hasHeroPreview)
            {{-- Episode-1 preview, plays continuously as a moving background WITH sound (muted for
                 18+/20+) + a mute toggle. SINGLE-INIT ONLY: heroPreview.init() is auto-called by
                 Alpine — do NOT add x-init="init()", or it resolves + loads the <video> twice and
                 hard-freezes the renderer. --}}
            <div class="absolute inset-0"
                 x-data="heroPreview({ src: @js($previewSrc), resolve: @js($previewResolve), adult: @js((bool) $content->is_adult) })">
                <video x-ref="hero" loop playsinline preload="none"
                       class="absolute inset-0 h-full w-full object-cover object-[50%_30%]"></video>
                <button type="button" @click.stop="toggleMute()" x-show="ready" x-cloak
                        class="absolute right-16 top-4 z-30 flex h-9 w-9 items-center justify-center rounded-full bg-black/55 text-base backdrop-blur transition hover:bg-black/80"
                        :title="muted ? 'เปิดเสียง' : 'ปิดเสียง'" :aria-label="muted ? 'เปิดเสียง' : 'ปิดเสียง'">
                    <span x-text="muted ? '🔇' : '🔊'"></span>
                </button>
            </div>
        @elseif (! $content->backdrop_url)
            @include('partials.logo-fill', ['seed' => $content->id])
        @endif

        {{-- cover poster floating on the right, overlapping the video with a shadow + edge (owner:
             หน้าปกไว้ด้านขวา มีเงา รูปทับขอบไว้) --}}
        @if ($content->poster_url)
            <img src="{{ $content->poster_url }}" alt="{{ $content->title }}"
                 referrerpolicy="no-referrer" onerror="this.style.display='none'"
                 class="pointer-events-none absolute right-5 top-1/2 z-20 w-auto max-w-[30%] -translate-y-1/2 rounded-xl object-cover shadow-[0_12px_45px_rgba(0,0,0,0.6)] ring-1 ring-white/15"
                 style="height:78%">
        @endif

        {{-- top: rating stars + maturity / PRO / year / match badges --}}
        <div class="absolute left-5 right-28 top-4 z-20 flex flex-wrap items-center gap-1.5">
            <span class="inline-flex items-center gap-1 rounded-full bg-black/45 px-2.5 py-1 text-sm font-bold text-gold backdrop-blur">★ {{ $content->rating }}</span>
            <span class="rounded-full px-2.5 py-1 text-xs font-semibold backdrop-blur {{ $content->is_adult ? 'bg-gold/90 text-black' : 'bg-black/45 text-cream/90' }}">{{ $content->maturity }}</span>
            @if ($content->requires_pro)
                <span class="inline-flex items-center gap-0.5 rounded-full bg-gold/90 px-2.5 py-1 text-xs font-bold text-black">👑 PRO</span>
            @endif
            <span class="rounded-full bg-black/45 px-2.5 py-1 text-xs text-cream/90 backdrop-blur">{{ $content->year }}</span>
            @if ($content->dub_label)
                <span class="rounded-full px-2.5 py-1 text-xs font-bold backdrop-blur {{ $content->dub_type === 'thai_dub' ? 'bg-emerald-500/90 text-black' : 'bg-sky-500/90 text-black' }}">{{ $content->dub_label }}</span>
            @endif
            <span class="rounded-full bg-black/45 px-2.5 py-1 text-xs font-bold text-success backdrop-blur">{{ $content->match_score }}% ตรงใจ</span>
        </div>

        @if ($modal ?? true)
            <button type="button" @click="$dispatch('close-title')"
                    class="absolute right-4 top-4 z-30 flex h-9 w-9 items-center justify-center rounded-full bg-ink/70 text-lg hover:bg-ink">✕</button>
        @endif

        {{-- fade + title + primary actions eased into the video's lower part (no hard edge) --}}
        <div class="pointer-events-none absolute inset-x-0 bottom-0 h-[64%]" style="background:linear-gradient(180deg, transparent 0%, rgba(20,16,32,0.35) 34%, rgba(20,16,32,0.85) 72%, var(--color-panel) 100%)"></div>
        <div class="absolute inset-x-0 bottom-0 z-20 p-5 pr-[34%] sm:p-6 sm:pr-[32%]">
            @if ($content->is_original)
                <div class="nx-gradient mb-2 inline-flex rounded px-2 py-0.5 text-[10px] font-bold tracking-widest">NETWIX ORIGINAL</div>
            @endif
            <h2 class="mb-3 text-2xl font-extrabold drop-shadow-[0_2px_12px_rgba(0,0,0,0.9)] sm:text-3xl">{{ $content->title }}</h2>
            <div class="flex flex-wrap items-center gap-2.5">
                @if ($needsPro)
                    <a href="{{ route('account') }}" class="flex items-center gap-2 rounded-md bg-gradient-to-r from-gold to-[#ffcf5a] px-5 py-2 font-bold text-black hover:brightness-95" title="เนื้อหาผู้ใหญ่ — เฉพาะสมาชิก Pro">
                        👑 ปลดล็อกด้วย Pro
                    </a>
                @else
                    <a href="{{ route('watch', $content) }}" class="flex items-center gap-2 rounded-md bg-cream px-5 py-2 font-bold text-ink hover:brightness-90">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg> เล่น
                    </a>
                @endif
                <button type="button" @click="toggleList" class="flex h-10 w-10 items-center justify-center rounded-full border border-cream/50 bg-black/25 text-xl backdrop-blur hover:border-cream" title="รายการของฉัน">
                    <span x-text="inList ? '✓' : '+'"></span>
                </button>
                <button type="button" @click="toggleLike" class="flex h-10 w-10 items-center justify-center rounded-full border border-cream/50 bg-black/25 text-lg backdrop-blur hover:border-cream" :style="liked ? 'color:#ff2d55' : ''" title="ถูกใจ">♥</button>
            </div>
        </div>
    </div>

    {{-- ═══════════ SCROLL BODY — left: review · right: episodes ═══════════ --}}
    <div class="min-h-0 flex-1 overflow-y-auto" style="scrollbar-gutter:stable">
        <div class="flex flex-col gap-6 p-6 sm:flex-row sm:gap-8 sm:p-8">

            {{-- RIGHT column on desktop / shown FIRST on mobile: episode list --}}
            <div class="min-w-0 flex-1 sm:order-2">
                @if ($content->type === 'series' && $content->seasons->isNotEmpty())
                    <div class="mb-3 flex items-center gap-3">
                        <h3 class="text-lg font-semibold">ตอนทั้งหมด</h3>
                        @if ($content->seasons->count() > 1)
                            <select x-model.number="season" class="rounded bg-surface-2 border border-white/15 px-3 py-1.5 text-sm outline-none">
                                @foreach ($content->seasons as $s)
                                    <option value="{{ $s->number }}">{{ $s->title ?? 'ซีซั่น '.$s->number }}</option>
                                @endforeach
                            </select>
                        @endif
                    </div>
                    @foreach ($content->seasons as $s)
                        <div x-show="season === {{ $s->number }}" class="flex flex-col gap-1">
                            @foreach ($s->episodes as $ep)
                                <a href="{{ route('watch', [$content, $ep]) }}"
                                   class="flex items-center gap-4 rounded-lg p-3 transition hover:bg-white/5">
                                    <span class="w-6 text-center text-lg font-bold text-cream/40">{{ $ep->number }}</span>
                                    <div class="h-14 w-24 flex-shrink-0 overflow-hidden rounded" style="background:{{ $content->gradient }}">
                                        @if ($ep->thumbnail_url)<img src="{{ $ep->thumbnail_url }}" class="h-full w-full object-cover" alt="">@endif
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex justify-between gap-2">
                                            <span class="truncate text-sm font-medium">{{ $ep->title }}</span>
                                            <span class="flex-shrink-0 text-xs text-cream/45">{{ $ep->duration_label }}</span>
                                        </div>
                                        <p class="truncate text-xs text-cream/50">{{ $ep->description }}</p>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @endforeach
                @elseif ($content->type === 'vertical' && $content->episodes->isNotEmpty())
                    <div class="mb-3 flex flex-wrap items-center gap-2">
                        <h3 class="text-lg font-semibold">ตอนทั้งหมด</h3>
                        <span class="text-sm text-cream/45">{{ $content->episodes->count() }} ตอน · ปัดขึ้น–ลงเพื่อดูตอนถัดไป</span>
                    </div>
                    {{-- portrait tiles (9:16) with the captured per-episode frame + episode number --}}
                    <div class="grid gap-2.5" style="grid-template-columns:repeat(auto-fill,minmax(84px,1fr))">
                        @foreach ($content->episodes as $i => $ep)
                            @php $epThumb = $ep->thumbnail_path ? $ep->thumbnail_url : $content->poster_url; @endphp
                            <a href="{{ route('watch', $content) }}?ep={{ $i }}" title="ตอนที่ {{ $ep->number }}"
                               class="relative block overflow-hidden rounded-lg ring-1 ring-white/10 transition hover:ring-2 hover:ring-brand"
                               style="aspect-ratio:9/16;background:{{ $content->gradient }}">
                                @if ($epThumb)
                                    <img src="{{ $epThumb }}" alt="" loading="lazy" referrerpolicy="no-referrer"
                                         onerror="this.style.display='none'" class="absolute inset-0 h-full w-full object-cover">
                                @endif
                                <div class="absolute inset-0 bg-gradient-to-t from-black/85 via-transparent to-transparent"></div>
                                <div class="absolute inset-x-0 bottom-1 text-center leading-none drop-shadow-[0_1px_3px_rgba(0,0,0,0.9)]">
                                    <span class="text-[15px] font-extrabold">{{ $ep->number }}</span>
                                    <span class="block text-[9px] font-medium text-cream/60">ตอน</span>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-cream/45">กด “เล่น” ด้านบนเพื่อรับชม</p>
                @endif
            </div>

            {{-- LEFT column on desktop / shown SECOND on mobile: synopsis + genres + rating/share/comments --}}
            <div class="sm:order-1 sm:w-[64%] sm:shrink-0">
                <p class="text-[15px] leading-relaxed text-cream/85">{{ $content->synopsis }}</p>
                <div class="mt-4 space-y-1 text-sm text-cream/55">
                    <div><span class="text-cream/40">แนว:</span> {{ $content->genres->pluck('name')->join(', ') }}</div>
                    <div><span class="text-cream/40">ประเภท:</span> {{ ['series' => 'ซีรี่ส์', 'movie' => 'ภาพยนตร์', 'vertical' => 'ซีรีส์แนวตั้ง'][$content->type] }}</div>
                </div>
                @include('frontend.partials.title-feedback')
            </div>

        </div>
    </div>
</div>
