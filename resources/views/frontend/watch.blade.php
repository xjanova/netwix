@extends('layouts.player')
@section('title', $content->title)
@section('meta_keywords', $content->seo_keywords)

@php
    $eps = $episodes->map(fn ($e) => [
        'n' => $e->number,
        'title' => $e->title,
        'id' => $e->id,
        'url' => $e->video_url,
        'resolve' => ($e->source && ! $e->video_url) ? route('episode.source', $e) : null,
        'thumb' => $e->thumbnail_path ? $e->thumbnail_url : $content->poster_url,
        'has' => (bool) $e->thumbnail_path,
        'post' => route('episode.thumb', $e),
        'gen' => route('episode.gencover', $e),
        // Per-episode marker override, else the content default (0 = off).
        'introEnd' => (int) ($e->intro_end_seconds ?? $content->intro_end_seconds ?? 0),
        'outroSeconds' => (int) ($e->outro_seconds ?? $content->outro_seconds ?? 0),
    ])->values();
    // Manual movie with a URL on the content itself and no episode rows → one synthetic entry.
    if ($eps->isEmpty() && $content->video_url) {
        $eps = collect([[
            'n' => 1, 'title' => $content->title, 'id' => null,
            'url' => $content->video_url, 'resolve' => null,
            'thumb' => $content->poster_url, 'has' => true, 'post' => null,
            'introEnd' => (int) ($content->intro_end_seconds ?? 0),
            'outroSeconds' => (int) ($content->outro_seconds ?? 0),
        ]]);
    }

    // Rating + latest comments seed for the end-of-series card (shown when the LAST episode finishes on
    // a multi-episode title). Mirrors partials/title-feedback so the shapes match the AJAX endpoints.
    $ratingAvg = round((float) $content->ratings()->avg('stars'), 1);
    $ratingCount = $content->ratings()->count();
    $myRating = isset($currentProfile) ? (int) $content->ratings()->where('profile_id', $currentProfile->id)->value('stars') : 0;
    $commentCount = $content->comments()->count();
    $endComments = $content->comments()->with('profile')->latest()->limit(6)->get()->map(fn ($c) => [
        'author' => $c->profile?->name ?? 'สมาชิก',
        'color' => $c->profile?->avatar_color ?? '#8b2ff0',
        'initial' => $c->profile?->initial ?? 'N',
        'text' => $c->body,
        'ago' => optional($c->created_at)->diffForHumans(),
    ])->values();
@endphp

@section('content')
<div class="relative h-[100dvh] w-full bg-black"
     x-data="watchPlayer({
        progressUrl: '{{ route('content.progress', $content) }}',
        reportUrl: '{{ route('playback.report', $content) }}',
        episodes: @js($eps),
        start: {{ $startIndex }},
        youtube: @js($youtubeId),
        hasMedia: @js((bool) $youtubeId || $eps->isNotEmpty()),
        rateUrl: '{{ route('content.rate', $content) }}',
        commentUrl: '{{ route('content.comment', $content) }}',
        myRating: {{ $myRating }},
        ratingAvg: {{ $ratingAvg ?: 0 }},
        ratingCount: {{ $ratingCount }},
        comments: @js($endComments),
        commentCount: {{ $commentCount }},
        introEnd: {{ (int) ($content->intro_end_seconds ?? 0) }},
        outroSeconds: {{ (int) ($content->outro_seconds ?? 0) }},
     })"
     x-init="init()" @mousemove="poke()" @touchstart="poke()">

    {{-- Non-blocking notice: this title's link is under review (some viewers couldn't play it). Playback
         still works normally; this just sets expectations. Auto-fades; dismissible. --}}
    @if ($content->link_under_review)
        <div x-data="{ show: true }" x-show="show" x-transition.opacity x-init="setTimeout(() => show = false, 8000)"
             class="absolute inset-x-0 top-16 z-40 mx-auto flex w-fit max-w-[92%] items-center gap-2 rounded-lg bg-gold/95 px-4 py-2.5 text-[13px] font-semibold text-black shadow-lg">
            🚧 เรื่องนี้กำลังตรวจสอบลิงก์ อาจเล่นไม่ได้ชั่วคราว — ขออภัยในความไม่สะดวก
            <button type="button" @click="show = false" class="ml-1 text-black/55 hover:text-black">✕</button>
        </div>
    @endif

    {{-- top bar (auto-hides when idle; any mouse/touch activity brings it back) --}}
    <div x-show="ui" x-transition.opacity class="absolute inset-x-0 top-0 z-30 flex items-center gap-3 bg-gradient-to-b from-black/80 to-transparent p-4">
        <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('browse') }}"
           class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-white/10 text-xl backdrop-blur hover:bg-white/20">‹</a>
        <div class="min-w-0">
            <div class="truncate text-base font-semibold">{{ $content->title }}</div>
            <div x-show="episodes.length > 1" x-cloak x-text="'ตอนที่ ' + (episodes[index]?.n || '')" class="text-xs text-cream/60"></div>
        </div>

        @unless ($youtubeId)
            <button type="button" x-show="episodes.length > 1" x-cloak @click="epMenu = true"
                    class="ml-auto flex h-10 items-center gap-1.5 rounded-full bg-white/10 px-4 text-sm font-semibold backdrop-blur hover:bg-white/20">
                ▦ เลือกตอน
            </button>
            <button type="button" @click="toggleFs()" title="เต็มจอ" aria-label="เต็มจอ"
                    :class="episodes.length > 1 ? '' : 'ml-auto'"
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-white/10 backdrop-blur hover:bg-white/20">
                <svg x-show="!fs" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3M3 16v3a2 2 0 0 0 2 2h3m8 0h3a2 2 0 0 0 2-2v-3"/></svg>
                <svg x-show="fs" x-cloak class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3v3a2 2 0 0 1-2 2H3m18 0h-3a2 2 0 0 1-2-2V3M3 16h3a2 2 0 0 1 2 2v3m8 0v-3a2 2 0 0 1 2-2h3"/></svg>
            </button>
        @endunless
    </div>

    @if ($youtubeId)
        {{-- src is bound (not server-set) so a pre-roll ad can play first; beginPlayback() fills it in --}}
        <iframe :src="ytSrc" x-show="ytSrc" x-cloak
                @load="loading = false"
                class="h-full w-full border-0" allow="autoplay; encrypted-media; fullscreen" allowfullscreen></iframe>
    @elseif ($eps->isNotEmpty())
        {{-- controlsList=nofullscreen drops the NATIVE fullscreen button (Chromium/Android) so there's
             only ONE fullscreen control — the custom top-bar one (nxToggleFullscreen), which keeps our
             overlay UI + locks landscape. The native one fullscreens the bare <video>, dropping that UI,
             and on this full-viewport player looked like it "did nothing". (iOS ignores controlsList but
             its native video fullscreen works, and our button falls back to it there.) --}}
        <video x-ref="video" x-show="!embedUrl" controls controlsList="nofullscreen" autoplay playsinline
               @timeupdate.throttle.10000ms="saveProgress()"
               @timeupdate="resume(); marks()"
               @ended="onEnded()"
               @waiting="stall()" @stalled="stall()"
               @playing="resume(); maybeCapture()" @canplay="resume()" @loadeddata="resume()" x-on:error="resume()"
               @volumechange="if (! $refs.video.muted) forcedMute = false"
               class="h-full w-full bg-black object-contain"></video>

        {{-- 9nung/abyss: a sandboxed 3rd-party player iframe. sandbox WITHOUT allow-popups blocks the
             source's casino/pop-under ads; allow-scripts/same-origin let the player run. --}}
        <iframe x-ref="embed" x-show="embedUrl" x-cloak :src="embedUrl" @load="resume()"
                sandbox="allow-scripts allow-same-origin allow-presentation allow-forms"
                allow="autoplay; fullscreen; encrypted-media"
                class="absolute inset-0 h-full w-full border-0 bg-black"></iframe>

        {{-- iPad forces muted autoplay → one-tap unmute so a silent start isn't a mystery --}}
        <button x-show="forcedMute" x-cloak @click.stop="unmute()"
                class="absolute right-4 top-4 z-40 flex items-center gap-2 rounded-full bg-black/60 px-3.5 py-2 text-sm font-semibold backdrop-blur hover:bg-black/80">
            <span class="text-base">🔇</span> แตะเพื่อเปิดเสียง
        </button>

        {{-- Skip-intro (appears while inside [1s, intro_end]); the toggle remembers auto-skip in localStorage --}}
        <div x-show="showSkip" x-cloak class="absolute bottom-24 right-4 z-40 flex items-center gap-2">
            <label class="flex cursor-pointer items-center gap-1.5 rounded-full bg-black/60 px-3 py-2 text-xs text-cream/80 backdrop-blur">
                <input type="checkbox" :checked="autoSkip" @change="setAutoSkip($event.target.checked)" class="accent-brand"> ข้ามอัตโนมัติ
            </label>
            <button type="button" @click="skipIntro()"
                    class="rounded-lg bg-white/90 px-4 py-2.5 text-sm font-bold text-black shadow-lg transition hover:bg-white">ข้ามอินโทร ⏭</button>
        </div>

        {{-- Next-episode card at the credits marker (non-last episode): auto-advances at 0, cancelable --}}
        <div x-show="showNext" x-cloak
             class="absolute bottom-24 right-4 z-40 w-64 rounded-xl bg-black/85 p-4 ring-1 ring-white/10 backdrop-blur">
            <div class="mb-1 text-xs text-cream/55">ตอนต่อไปกำลังจะเริ่ม</div>
            <div class="mb-3 truncate text-sm font-semibold" x-text="episodes[index + 1] ? ('ตอนที่ ' + (episodes[index + 1].n || (index + 2))) : ''"></div>
            <div class="flex items-center gap-2">
                <button type="button" @click="playNextNow()" class="btn-brand flex-1 py-2 text-sm">▶ เล่นเลย (<span x-text="nextIn"></span>)</button>
                <button type="button" @click="dismissNext()" class="rounded-lg bg-white/10 px-3 py-2 text-sm hover:bg-white/15">ยกเลิก</button>
            </div>
        </div>

        <div x-show="err" x-cloak class="pointer-events-none absolute inset-0 flex items-center justify-center px-6 text-center text-cream/70">
            <span x-text="err"></span>
        </div>

        {{-- episode picker (covers = captured frame, else main poster) — tap to jump --}}
        <div x-show="epMenu" x-cloak @click.self="epMenu = false"
             class="absolute inset-0 z-50 flex flex-col bg-black/90 backdrop-blur">
            <div class="flex items-center justify-between px-5 py-4">
                <span class="truncate text-lg font-bold">เลือกตอน · {{ $content->title }}</span>
                <button @click="epMenu = false" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white/10 text-lg hover:bg-white/20">✕</button>
            </div>
            <div class="grid flex-1 content-start gap-2.5 overflow-y-auto px-5 pb-8" style="grid-template-columns:repeat(auto-fill,minmax(148px,1fr))">
                <template x-for="(ep, i) in episodes" :key="i">
                    <button @click="go(i)" style="aspect-ratio:16/9"
                            class="group relative overflow-hidden rounded-lg ring-1 ring-white/10 transition hover:ring-2 hover:ring-white/40"
                            :class="i === index ? '!ring-2 !ring-brand' : ''">
                        <div class="absolute inset-0" style="background:linear-gradient(160deg,#241a33,#130f1c)"></div>
                        <img :src="ep.thumb" x-show="ep.thumb" loading="lazy" referrerpolicy="no-referrer"
                             class="absolute inset-0 h-full w-full object-cover" onerror="this.style.display='none'">
                        <div class="absolute inset-0 bg-gradient-to-t from-black/85 via-transparent to-transparent"></div>
                        <div class="absolute bottom-1.5 left-2 flex items-baseline gap-1 leading-none drop-shadow-[0_1px_3px_rgba(0,0,0,0.9)]">
                            <span class="text-[19px] font-extrabold" x-text="ep.n"></span>
                            <span class="text-[11px] font-medium text-cream/60">ตอน</span>
                        </div>
                        <span x-show="i === index" x-cloak class="nx-gradient absolute right-1.5 top-1.5 rounded px-1.5 py-0.5 text-[10px] font-bold">● กำลังดู</span>
                    </button>
                </template>
            </div>
        </div>

        {{-- End-of-series card: auto-shown by onEnded() when the LAST episode of a MULTI-episode title
             finishes. Members get interactive stars + a comment box (same endpoints as the title page);
             guests see the score read-only + a login CTA. Movies (1 episode) never reach this. --}}
        <div x-show="finished" x-cloak x-transition.opacity
             class="absolute inset-0 z-[55] flex items-start justify-center overflow-y-auto bg-black/90 px-4 py-8 backdrop-blur">
            <div class="w-full max-w-lg">
                <div class="text-center text-2xl font-extrabold">🎉 ดูจบแล้ว!</div>
                <div class="mb-5 mt-1 text-center text-sm text-cream/60">{{ $content->title }} — ให้คะแนนเรื่องนี้หน่อยไหม?</div>

                <div class="nx-card p-5">
                    {{-- rating --}}
                    @auth
                        <div class="flex flex-col items-center gap-1">
                            <div class="flex items-center gap-1.5">
                                <template x-for="n in 5" :key="n">
                                    <button type="button" @click="rate(n)" @mouseenter="hoverStar = n" @mouseleave="hoverStar = 0"
                                            class="text-4xl leading-none transition" :class="(hoverStar || my) >= n ? 'text-gold' : 'text-cream/25'">★</button>
                                </template>
                            </div>
                            <p class="text-sm text-cream/70"><span x-text="avg || '-'"></span> <span class="text-cream/45">(<span x-text="rcount"></span> รีวิว)</span></p>
                            <p class="text-[12px] text-brand-2" x-show="my > 0" x-cloak>ให้ไว้ <span x-text="my"></span> ดาว · แตะเพื่อแก้</p>
                        </div>
                    @else
                        <div class="flex flex-col items-center gap-1">
                            <div class="flex items-center gap-1.5">
                                @for ($i = 1; $i <= 5; $i++)
                                    <span class="text-4xl leading-none {{ $i <= round($ratingAvg) ? 'text-gold' : 'text-cream/25' }}">★</span>
                                @endfor
                            </div>
                            <p class="text-sm text-cream/70">{{ $ratingAvg ?: '-' }} <span class="text-cream/45">({{ $ratingCount }} รีวิว)</span></p>
                            <a href="{{ route('login') }}" class="text-[13px] text-brand-2 hover:underline">เข้าสู่ระบบเพื่อให้คะแนน</a>
                        </div>
                    @endauth

                    {{-- comment --}}
                    <div class="mt-5 border-t border-white/10 pt-4">
                        <div class="mb-2 text-sm font-semibold">ความคิดเห็น <span class="text-cream/45">(<span x-text="ccount"></span>)</span></div>
                        @auth
                            <form @submit.prevent="postComment()">
                                <textarea x-model="cbody" maxlength="500" rows="2" placeholder="บอกความรู้สึกหลังดูจบ…"
                                          class="w-full resize-none rounded-lg border border-white/10 bg-surface-2 px-3.5 py-2.5 text-sm outline-none focus:border-brand"></textarea>
                                @include('partials.turnstile')
                                <div class="mt-2 flex justify-end">
                                    <button type="submit" :disabled="cbusy || !cbody.trim()" class="btn-brand px-5 py-1.5 text-sm disabled:opacity-40">ส่งความคิดเห็น</button>
                                </div>
                            </form>
                        @else
                            <a href="{{ route('login') }}" class="flex items-center justify-center gap-2 rounded-lg border border-white/10 bg-surface-2 px-3.5 py-3 text-sm text-cream/70 transition hover:border-brand hover:text-cream">
                                เข้าสู่ระบบเพื่อร่วมแสดงความคิดเห็น
                            </a>
                        @endauth

                        <div class="mt-3 flex max-h-40 flex-col gap-3 overflow-y-auto">
                            <template x-for="(c, i) in comments" :key="i">
                                <div class="flex gap-2.5">
                                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-[11px] font-bold text-black/60"
                                          :style="'background:' + (c.color || '#8b2ff0')" x-text="c.initial || (c.author || '?').charAt(0)"></span>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-2 text-[12px]"><span class="font-semibold" x-text="c.author"></span><span class="text-cream/40" x-text="c.ago"></span></div>
                                        <p class="mt-0.5 whitespace-pre-line break-words text-[13px] text-cream/80" x-text="c.text"></p>
                                    </div>
                                </div>
                            </template>
                            <p x-show="comments.length === 0" class="py-3 text-center text-[13px] text-cream/40">ยังไม่มีความคิดเห็น — เป็นคนแรกเลย!</p>
                        </div>
                    </div>
                </div>

                {{-- actions --}}
                <div class="mt-4 flex flex-wrap items-center justify-center gap-2.5">
                    <button type="button" @click="replayAll()" class="rounded-lg bg-white/10 px-4 py-2.5 text-sm font-semibold hover:bg-white/15">↻ ดูอีกครั้งตั้งแต่ตอนแรก</button>
                    <button type="button" @click="finished = false; epMenu = true" class="rounded-lg bg-white/10 px-4 py-2.5 text-sm font-semibold hover:bg-white/15">▦ เลือกตอน</button>
                    <a href="{{ route('browse') }}" class="btn-brand px-5 py-2.5 text-sm font-semibold">ดูเรื่องอื่น →</a>
                    <button type="button" @click="finished = false" class="rounded-lg px-4 py-2.5 text-sm text-cream/55 hover:text-cream">ปิด</button>
                </div>
            </div>
        </div>
    @else
        <div class="flex h-full items-center justify-center px-6 text-center text-cream/60">
            ยังไม่มีไฟล์วิดีโอสำหรับเรื่องนี้ — เพิ่มลิงก์วิดีโอได้ในแผงผู้ดูแล
        </div>
    @endif

    @include('partials.player-watermark')

    {{-- branded "connecting to server" loader (only for a real stall) --}}
    <div x-show="loading && ! err" x-cloak class="pointer-events-none absolute inset-0 z-40">
        @include('partials.player-loading')
    </div>

    {{-- pre-roll ad: sits on top (z-70) until skipped/finished, then playback begins (see beginPlayback) --}}
    @include('partials.preroll-ad', ['ad' => $ad ?? null])
</div>

@push('scripts')
<script>
    function watchPlayer(cfg) {
        return {
            episodes: cfg.episodes || [],
            reportUrl: cfg.reportUrl,
            index: Math.min(cfg.start || 0, Math.max(0, (cfg.episodes || []).length - 1)),
            epMenu: false,
            err: '',
            fs: false,
            forcedMute: false,   // true only when iPad refused sound-autoplay and we fell back to muted
            loading: cfg.hasMedia,
            ui: true,
            ytSrc: '',           // YouTube embed src — filled by beginPlayback() (after any pre-roll ad)
            embedUrl: null,      // set when the episode resolves to a 3rd-party player iframe (9nung/abyss)
            // end-of-series rate + comment card (shown by onEnded / at the outro marker on the last episode)
            finished: false,
            my: cfg.myRating || 0, avg: cfg.ratingAvg || 0, rcount: cfg.ratingCount || 0, hoverStar: 0, rbusy: false,
            comments: cfg.comments || [], ccount: cfg.commentCount || 0, cbody: '', cbusy: false,
            // playback markers (content-level): skip-intro + credits/next
            introEnd: cfg.introEnd || 0,
            outroSeconds: cfg.outroSeconds || 0,
            showSkip: false, autoSkip: false, showNext: false, nextIn: 0,
            _outroFired: false, _nextT: null,
            _uiT: null,
            _stallT: null,
            _poll: null,
            _url: '',
            _reloads: 0,
            _lastT: 0,
            _stuck: 0,
            _watch: null,

            // auto-hide the top chrome (+ watermark) when idle; mouse/touch activity shows it again
            poke() { this.ui = true; clearTimeout(this._uiT); this._uiT = setTimeout(() => { this.ui = false; }, 2800); },

            // loader shows only for a stall that lasts (>700ms) and hides the moment playback resumes
            stall() { clearTimeout(this._stallT); this._stallT = setTimeout(() => { this.loading = true; }, 700); },
            resume() { clearTimeout(this._stallT); this.loading = false; },

            init() {
                document.addEventListener('fullscreenchange', () => { this.fs = window.nxFullscreenActive(); });
                document.addEventListener('webkitfullscreenchange', () => { this.fs = window.nxFullscreenActive(); });
                try { this.autoSkip = localStorage.getItem('nx_autoskip') === '1'; } catch (e) {}
                this.poke();
                // Gate the FIRST playback behind a pre-roll ad if one is showing (partials/preroll-ad).
                if (window.nxPreroll && window.nxPreroll.pending && !window.nxPreroll.done) {
                    window.addEventListener('nx-preroll-done', () => this.beginPlayback(), { once: true });
                } else {
                    this.beginPlayback();
                }
            },

            // Start the real video — YouTube gets its src now; a <video> title loads + arms the watchdog.
            beginPlayback() {
                if (cfg.youtube) {
                    this.ytSrc = 'https://www.youtube.com/embed/' + cfg.youtube + '?autoplay=1&rel=0&modestbranding=1&playsinline=1';
                    return;
                }
                if (!this.$refs.video) return;
                if (this.episodes.length) this.load();
                // watch for a mid-episode freeze that never recovers on its own (see watchdog()); the
                // same tick lazily grabs an on-demand cover for an episode that has none yet.
                this._watch = setInterval(() => {
                    this.watchdog();
                    window.nxMaybeThumb(this.$refs.video, this.episodes[this.index]);
                }, 6000);
            },

            stopPoll() { if (this._poll) { clearInterval(this._poll); this._poll = null; } },

            // Fix for the #1 landscape complaint "เล่นแล้วค้างกลางเรื่อง": if playback is supposed to be
            // running but currentTime hasn't advanced for ~12s (a buffer stall hls.js couldn't nudge
            // past, with no lower rendition to drop to on single-rendition wowdrama/HLS), hard re-attach
            // the same source and seek back. Budget of 3 per stuck stretch; refills once frames advance.
            watchdog() {
                const v = this.$refs.video;
                if (!v || v.paused || v.ended || !v.duration) { this._stuck = 0; return; }
                if (v.currentTime > this._lastT + 0.25) {          // advancing → healthy
                    this._lastT = v.currentTime; this._stuck = 0; this._reloads = 0; return;
                }
                this._stuck++;                                     // not moving while it should be
                if (this._stuck >= 2 && this._reloads < 3) {       // ~12s genuinely stuck
                    this._stuck = 0; this._reloads++;
                    this.hardReload();
                }
            },
            hardReload() {
                const v = this.$refs.video;
                if (!v || !this._url) return;
                const t = v.currentTime || 0;
                this.stall();                                      // show the connecting loader
                window.nxAttachVideo(v, this._url, this.reportUrl);
                const seekBack = () => {
                    v.removeEventListener('loadedmetadata', seekBack);
                    try { if (t > 1 && isFinite(v.duration)) v.currentTime = Math.max(0, t - 1); } catch (e) {}
                    this.tryPlay(v);
                };
                v.addEventListener('loadedmetadata', seekBack);
                this.tryPlay(v);
            },

            async load() {
                this.stopPoll();
                this.err = '';
                this._outroFired = false; this.showSkip = false; this.dismissNext();   // fresh markers per episode
                const ep = this.episodes[this.index];
                if (!ep) return;
                this.introEnd = ep.introEnd || 0; this.outroSeconds = ep.outroSeconds || 0;   // per-episode override ?? content default
                if (ep.url) { this.attach(ep.url); return; }

                const d = await this.tryResolve(ep);
                if (d && d.ready && d.url) { this.attach(d.url, d.kind); return; }

                // Not resolvable yet — show the loader and poll until it becomes available.
                this.stall();
                const my = this.index;
                this._poll = setInterval(async () => {
                    if (this.index !== my) { this.stopPoll(); return; }
                    const r = await this.tryResolve(ep);
                    if (r && r.ready && r.url) { this.stopPoll(); this.attach(r.url, r.kind); }
                }, 10000);
            },
            async tryResolve(ep) {
                if (!ep.resolve) return null;
                try {
                    const r = await fetch(ep.resolve, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    return await r.json();
                } catch (e) { return null; }
            },
            attach(url, kind) {
                // 9nung/abyss: a 3rd-party player iframe, not a stream — show the sandboxed iframe instead
                // of the <video> (popups are blocked by its sandbox). No proxy, no watchdog.
                if (kind === 'embed') { this.attachEmbed(url); return; }
                this.embedUrl = null;
                this._url = url;
                this._reloads = 0; this._lastT = 0; this._stuck = 0;   // fresh recovery budget per source
                this.stall();
                const v = this.$refs.video;
                window.nxAttachVideo(v, url, this.reportUrl);
                this.tryPlay(v);
            },
            attachEmbed(url) {
                // Stop any <video> playback and hand over to the iframe player.
                const v = this.$refs.video;
                if (v) { try { v.pause(); v.removeAttribute('src'); v.load(); } catch (e) {} }
                this._url = '';
                this.stall();
                this.embedUrl = url;   // :src on the iframe → loads the abyss player; @load clears the loader
            },

            // iPad/iPadOS blocks autoplay-WITH-SOUND that isn't triggered by a user tap — and landing on
            // the watch page is not a tap. Without this, play() rejects, we swallow it, and the <video>
            // sits black ("player พยายามเล่นแต่ไม่มีภาพ"). So: if the sound autoplay is refused, retry
            // MUTED (always allowed) so the picture actually appears; the native controls let the user
            // unmute. Mirrors the vertical player, which already does this and thus plays fine on iPad.
            tryPlay(v) {
                const p = v.play?.();
                if (p && p.catch) p.catch(() => {
                    // Sound-autoplay refused → play muted so a picture appears, and raise the pill so the
                    // silent start isn't a mystery. If even muted play is refused we leave the pill off.
                    v.muted = true;
                    v.play?.().then(() => { this.forcedMute = true; }).catch(() => {});
                });
            },

            // User taps "แตะเพื่อเปิดเสียง" — a real gesture, so restoring sound is allowed on iPad.
            unmute() {
                const v = this.$refs.video;
                if (!v) return;
                v.muted = false;
                this.forcedMute = false;
                v.play?.().catch(() => {});
                this.poke();
            },

            go(i) { this.epMenu = false; if (i !== this.index) { this.index = i; this.load(); } },
            next() { if (this.index < this.episodes.length - 1) { this.index++; this.load(); } },
            onEnded() {
                this.saveProgress(100);
                // More episodes to go → auto-play the next one.
                if (this.index < this.episodes.length - 1) { this.next(); return; }
                // Last episode. If the outro marker already popped the card, don't do it again.
                if (this._outroFired) return;
                // Finished the LAST episode of a MULTI-episode title → invite a rating + comment.
                if (this.episodes.length > 1) { this.showEndCard(); }
            },
            // Reveal the rate+comment card (from the real end, or early at the credits marker).
            showEndCard() {
                this.finished = true; this.ui = true; clearTimeout(this._uiT);
                // The Turnstile widget rendered while the card was display:none — nudge it once the card
                // is visible so getResponse() returns a fresh token for the comment post.
                this.$nextTick(() => { try { window.turnstile && window.turnstile.reset(); } catch (e) {} });
            },

            // Covers are generated in the admin panel now (Admin → สร้างปกตอน) —
            // no more on-watch capture.
            maybeCapture() {},

            toggleFs() { window.nxToggleFullscreen(this.$root, this.$refs.video, 'landscape'); },

            saveProgress(force = null) {
                const v = this.$refs.video;
                if (!v || !v.duration) return;
                const ep = this.episodes[this.index];
                // floor to 1 while playing so a brief watch of a long title still lands in "ดูต่อ"
                // (a few seconds of a 2-hour movie used to round to 0% → excluded from continue).
                const percent = force ?? Math.max(1, Math.round((v.currentTime / v.duration) * 100));
                nxPost(cfg.progressUrl, {
                    percent: Math.min(100, Math.max(0, percent)),
                    position_seconds: Math.round(v.currentTime),
                    episode_id: ep ? ep.id : null,
                }).catch(() => {});
            },

            // ---- end-of-series card: rate + comment (members) -------------------
            async rate(n) {
                if (this.rbusy) return;
                this.rbusy = true;
                try {
                    const d = await nxPost(cfg.rateUrl, { stars: n });
                    this.my = d.my_rating; this.avg = d.avg; this.rcount = d.count;
                } catch (e) {} finally { this.rbusy = false; }
            },
            async postComment() {
                if (this.cbusy || !this.cbody.trim()) return;
                this.cbusy = true;
                const ts = window.turnstile ? window.turnstile.getResponse() : '';
                try {
                    const d = await nxPost(cfg.commentUrl, { body: this.cbody, 'cf-turnstile-response': ts });
                    // normalise to the seeded shape (endpoint returns avatar_color, list uses color)
                    this.comments.unshift({ author: d.comment.author, color: d.comment.avatar_color, initial: d.comment.initial, text: d.comment.text, ago: d.comment.ago });
                    this.ccount = d.count; this.cbody = '';
                    if (window.turnstile) window.turnstile.reset();
                } catch (e) {} finally { this.cbusy = false; }
            },
            replayAll() { this.finished = false; this.go(0); },   // rewatch from episode 1

            // ---- playback markers: skip-intro + credits/next --------------------
            // Runs on every timeupdate (cheap numeric checks). Shows the skip button inside the intro
            // window, and once the credits marker is reached fires enterOutro() a single time.
            marks() {
                const v = this.$refs.video;
                if (!v || !isFinite(v.duration) || !v.duration) return;
                const t = v.currentTime, dur = v.duration;
                this.showSkip = this.introEnd > 1 && t >= 1 && t < this.introEnd;
                if (this.showSkip && this.autoSkip) this.skipIntro();
                if (this.outroSeconds > 0 && t > 5 && (dur - t) <= this.outroSeconds) this.enterOutro();
            },
            skipIntro() {
                const v = this.$refs.video;
                if (!v || this.introEnd <= 0) return;
                try { v.currentTime = this.introEnd; } catch (e) {}
                this.showSkip = false;
                this.tryPlay(v);
            },
            setAutoSkip(on) {
                this.autoSkip = on;
                try { localStorage.setItem('nx_autoskip', on ? '1' : '0'); } catch (e) {}
            },
            // Hit the credits marker (once per episode): last episode / movie → rate+comment card;
            // otherwise a "next episode" countdown that auto-advances (cancelable).
            enterOutro() {
                if (this._outroFired) return;
                this._outroFired = true;
                if (this.index >= this.episodes.length - 1) {
                    if (this.episodes.length > 1 || this.outroSeconds > 0) this.showEndCard();
                } else {
                    this.startNextCountdown();
                }
            },
            startNextCountdown() {
                this.showNext = true;
                this.nextIn = 10;
                this.cancelNextTimer();
                this._nextT = setInterval(() => {
                    this.nextIn--;
                    if (this.nextIn <= 0) { this.playNextNow(); }
                }, 1000);
            },
            cancelNextTimer() { if (this._nextT) { clearInterval(this._nextT); this._nextT = null; } },
            dismissNext() { this.cancelNextTimer(); this.showNext = false; },   // keep watching the credits
            playNextNow() { this.cancelNextTimer(); this.showNext = false; this.next(); },
        };
    }
</script>
@endpush
@endsection
