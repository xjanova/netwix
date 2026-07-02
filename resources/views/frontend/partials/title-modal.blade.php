@php
    $heroYt = $content->youtube_id;
    $firstSeason = $content->seasons->first();
@endphp
<div x-data="{
        inList: @js($inMyList),
        liked: @js($liked),
        season: {{ $firstSeason->number ?? 1 }},
        busy: false,
        async toggleList() { if (this.busy) return; this.busy = true;
            try { this.inList = (await nxPost('{{ route('content.list', $content) }}')).in_list; } finally { this.busy = false; } },
        async toggleLike() { if (this.busy) return; this.busy = true;
            try { this.liked = (await nxPost('{{ route('content.like', $content) }}')).liked; } finally { this.busy = false; } },
     }">
    {{-- backdrop --}}
    <div class="relative aspect-video w-full overflow-hidden bg-black">
        {{-- gradient always underneath so the backdrop never shows a black void --}}
        <div class="absolute inset-0" style="background:{{ $content->gradient }}"></div>
        @if ($content->backdrop_url)
            <img src="{{ $content->backdrop_url }}" alt="" aria-hidden="true"
                 referrerpolicy="no-referrer" onerror="this.style.display='none'"
                 class="absolute inset-0 h-full w-full object-cover">
        @endif
        @if ($heroYt)
            <iframe src="https://www.youtube.com/embed/{{ $heroYt }}?autoplay=1&mute=1&loop=1&playlist={{ $heroYt }}&controls=0&modestbranding=1&rel=0&playsinline=1"
                    class="pointer-events-none absolute left-1/2 top-1/2 h-full w-full -translate-x-1/2 -translate-y-1/2 border-0"
                    style="min-width:178%;min-height:178%" allow="autoplay; encrypted-media"></iframe>
        @elseif (! $content->backdrop_url)
            <img src="{{ asset('assets/netwix-icon.png') }}" alt="" aria-hidden="true"
                 class="pointer-events-none absolute left-1/2 top-[42%] h-[42%] w-auto -translate-x-1/2 -translate-y-1/2 opacity-20">
        @endif
        <div class="absolute inset-0" style="background:linear-gradient(180deg,transparent 40%, rgba(20,16,32,0.9) 92%, #141020 100%)"></div>

        @if ($modal ?? true)
            <button type="button" @click="$dispatch('close-title')"
                    class="absolute right-4 top-4 flex h-9 w-9 items-center justify-center rounded-full bg-ink/70 text-lg hover:bg-ink">✕</button>
        @endif

        <div class="absolute bottom-5 left-6 right-6">
            @if ($content->is_original)
                <div class="nx-gradient mb-3 inline-flex rounded px-2 py-0.5 text-[10px] font-bold tracking-widest">NETWIX ORIGINAL</div>
            @endif
            <h2 class="text-2xl font-extrabold sm:text-3xl">{{ $content->title }}</h2>
        </div>
    </div>

    <div class="p-6 sm:p-8">
        <div class="flex flex-wrap items-center gap-3">
            <a href="{{ route('watch', $content) }}" class="flex items-center gap-2 rounded-md bg-cream px-6 py-2.5 font-bold text-ink hover:brightness-90">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg> เล่น
            </a>
            <button type="button" @click="toggleList" class="flex h-11 w-11 items-center justify-center rounded-full border border-cream/50 text-xl hover:border-cream" title="รายการของฉัน">
                <span x-text="inList ? '✓' : '+'"></span>
            </button>
            <button type="button" @click="toggleLike" class="flex h-11 w-11 items-center justify-center rounded-full border border-cream/50 text-lg hover:border-cream" :style="liked ? 'color:#ff2d55' : ''" title="ถูกใจ">♥</button>
        </div>

        <div class="mt-5 grid gap-5 sm:grid-cols-[2fr_1fr]">
            <div>
                <div class="mb-2 flex flex-wrap items-center gap-3 text-sm text-cream/75">
                    <span class="font-bold text-success">{{ $content->match_score }}% ตรงใจ</span>
                    <span>{{ $content->year }}</span>
                    <span class="rounded border border-cream/40 px-1.5 py-px text-xs">{{ $content->maturity }}</span>
                    <span class="text-gold">★ {{ $content->rating }}</span>
                </div>
                <p class="text-[15px] leading-relaxed text-cream/85">{{ $content->synopsis }}</p>
            </div>
            <div class="text-sm text-cream/55">
                <div class="mb-1"><span class="text-cream/40">แนว:</span> {{ $content->genres->pluck('name')->join(', ') }}</div>
                <div><span class="text-cream/40">ประเภท:</span> {{ ['series' => 'ซีรี่ส์', 'movie' => 'ภาพยนตร์', 'vertical' => 'ซีรีส์แนวตั้ง'][$content->type] }}</div>
            </div>
        </div>

        @if ($content->type === 'series' && $content->seasons->isNotEmpty())
            <div class="mt-7">
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
            </div>
        @endif

        @if ($content->type === 'vertical')
            <div class="mt-6 rounded-lg bg-white/5 p-4 text-sm text-cream/60">
                ซีรีส์แนวตั้ง {{ $content->episodes->count() }} ตอน · ปัดขึ้น–ลงเพื่อดูตอนถัดไป
            </div>
        @endif
    </div>
</div>
