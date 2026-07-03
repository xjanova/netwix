@extends('layouts.player')
@section('title', $content->title)

@section('content')
<div class="relative h-[100dvh] w-full bg-black"
     x-data="watchPlayer({
        progressUrl: '{{ route('content.progress', $content) }}',
        episodeId: {{ $episode->id ?? 'null' }},
        source: @js($source),
        resolveUrl: @js($resolveUrl ?? null),
        youtube: @js($youtubeId),
        hasMedia: @js((bool) ($youtubeId || $source || $resolveUrl)),
     })"
     x-init="init()">

    {{-- top bar --}}
    <div class="absolute inset-x-0 top-0 z-30 flex items-center gap-4 bg-gradient-to-b from-black/80 to-transparent p-4">
        <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('browse') }}"
           class="flex h-10 w-10 items-center justify-center rounded-full bg-white/10 text-xl backdrop-blur hover:bg-white/20">‹</a>
        <div>
            <div class="text-base font-semibold">{{ $content->title }}</div>
            @if ($episode)
                <div class="text-xs text-cream/60">{{ $episode->title }}</div>
            @endif
        </div>

        @unless ($youtubeId)
            <button type="button" @click="toggleFs()" title="เต็มจอ" aria-label="เต็มจอ"
                    class="ml-auto flex h-10 w-10 items-center justify-center rounded-full bg-white/10 backdrop-blur hover:bg-white/20">
                <svg x-show="!fs" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3M3 16v3a2 2 0 0 0 2 2h3m8 0h3a2 2 0 0 0 2-2v-3"/></svg>
                <svg x-show="fs" x-cloak class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3v3a2 2 0 0 1-2 2H3m18 0h-3a2 2 0 0 1-2-2V3M3 16h3a2 2 0 0 1 2 2v3m8 0v-3a2 2 0 0 1 2-2h3"/></svg>
            </button>
        @endunless
    </div>

    @if ($youtubeId)
        <iframe src="https://www.youtube.com/embed/{{ $youtubeId }}?autoplay=1&rel=0&modestbranding=1&playsinline=1"
                @load="loading = false"
                class="h-full w-full border-0" allow="autoplay; encrypted-media; fullscreen" allowfullscreen></iframe>
    @elseif ($source || $resolveUrl)
        <video x-ref="video" controls autoplay playsinline
               @timeupdate.throttle.10000ms="saveProgress()"
               @ended="saveProgress(100)"
               @waiting="loading = true" @stalled="loading = true"
               @playing="loading = false" @canplay="loading = false" @error="loading = false"
               class="h-full w-full bg-black object-contain"></video>
        <div x-show="err" x-cloak class="pointer-events-none absolute inset-0 flex items-center justify-center px-6 text-center text-cream/70">
            <span x-text="err"></span>
        </div>
    @else
        <div class="flex h-full items-center justify-center px-6 text-center text-cream/60">
            ยังไม่มีไฟล์วิดีโอสำหรับตอนนี้ — เพิ่มลิงก์วิดีโอได้ในแผงผู้ดูแล
        </div>
    @endif

    {{-- branded "connecting to server" loader (until the stream can play) --}}
    <div x-show="loading && ! err" x-cloak class="pointer-events-none absolute inset-0 z-40">
        @include('partials.player-loading')
    </div>
</div>

@push('scripts')
<script>
    function watchPlayer(cfg) {
        return {
            err: '',
            fs: false,
            loading: cfg.hasMedia,
            async init() {
                document.addEventListener('fullscreenchange', () => { this.fs = window.nxFullscreenActive(); });
                document.addEventListener('webkitfullscreenchange', () => { this.fs = window.nxFullscreenActive(); });
                if (!this.$refs.video || cfg.youtube) return;
                if (cfg.source) {
                    window.nxAttachVideo(this.$refs.video, cfg.source);
                } else if (cfg.resolveUrl) {
                    try {
                        const r = await fetch(cfg.resolveUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                        const d = await r.json();
                        if (d && d.ready && d.url) {
                            window.nxAttachVideo(this.$refs.video, d.url);
                        } else {
                            this.err = 'กำลังเตรียมไฟล์ไว้… อีกสักครู่แล้วลองใหม่อีกครั้ง';
                        }
                    } catch (e) {
                        this.err = 'ไม่สามารถโหลดวิดีโอจากแหล่งต้นทางได้ในขณะนี้';
                    }
                }
            },
            toggleFs() {
                window.nxToggleFullscreen(this.$root, this.$refs.video, 'landscape');
            },
            saveProgress(force = null) {
                const v = this.$refs.video;
                if (!v || !v.duration) return;
                const percent = force ?? Math.round((v.currentTime / v.duration) * 100);
                nxPost(cfg.progressUrl, {
                    percent: Math.min(100, Math.max(0, percent)),
                    position_seconds: Math.round(v.currentTime),
                    episode_id: cfg.episodeId,
                }).catch(() => {});
            },
        };
    }
</script>
@endpush
@endsection
