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
    </div>

    @if ($youtubeId)
        <iframe src="https://www.youtube.com/embed/{{ $youtubeId }}?autoplay=1&rel=0&modestbranding=1&playsinline=1"
                class="h-full w-full border-0" allow="autoplay; encrypted-media; fullscreen" allowfullscreen></iframe>
    @elseif ($source || $resolveUrl)
        <video x-ref="video" controls autoplay playsinline
               @timeupdate.throttle.10000ms="saveProgress()"
               @ended="saveProgress(100)"
               class="h-full w-full bg-black object-contain"></video>
        <div x-show="err" x-cloak class="pointer-events-none absolute inset-0 flex items-center justify-center px-6 text-center text-cream/70">
            <span x-text="err"></span>
        </div>
    @else
        <div class="flex h-full items-center justify-center px-6 text-center text-cream/60">
            ยังไม่มีไฟล์วิดีโอสำหรับตอนนี้ — เพิ่มลิงก์วิดีโอได้ในแผงผู้ดูแล
        </div>
    @endif
</div>

@push('scripts')
<script>
    function watchPlayer(cfg) {
        return {
            err: '',
            async init() {
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
