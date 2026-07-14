@extends('layouts.app')
@section('title', 'ภารกิจ · รับเหรียญ')

@section('content')
@php
    $missionsJs = $missions->map(fn ($x) => [
        'id' => $x['model']->id,
        'title' => $x['model']->title,
        'desc' => $x['model']->description,
        'source' => $x['model']->video_source,
        'ref' => $x['model']->playRef(),
        'poster' => $x['model']->poster,
        'required' => (int) $x['model']->required_seconds,
        'reward' => $x['model']->rewardLabel(),
        'kind' => $x['model']->reward_kind,
        'repeat' => $x['model']->repeat,
        'status' => $x['status'],
        'startUrl' => route('missions.start', $x['model']),
        'beatUrl' => route('missions.beat', $x['model']),
    ])->values();
@endphp

<div class="mx-auto max-w-5xl px-4 py-8" x-data="missionsPage({ missions: {{ \Illuminate\Support\Js::from($missionsJs) }} })">
    <div class="mb-2 flex items-center gap-3">
        <span class="nx-gradient h-7 w-1.5 rounded-full"></span>
        <h1 class="text-2xl font-bold">ภารกิจ · รับเหรียญ</h1>
    </div>
    <p class="mb-6 text-sm text-cream/55">ดูวิดีโอให้ครบเวลาที่กำหนด (ต้องเปิดหน้านี้ไว้และเล่นจริง) แล้วรับเหรียญอัตโนมัติ</p>

    <template x-if="missions.length === 0">
        <div class="nx-card p-10 text-center text-cream/50">ยังไม่มีภารกิจตอนนี้ — กลับมาเช็คใหม่ภายหลัง</div>
    </template>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <template x-for="m in missions" :key="m.id">
            <div class="nx-card flex flex-col overflow-hidden p-0">
                <div class="relative flex aspect-video items-center justify-center bg-gradient-to-br from-[#241a33] to-[#130f1c]">
                    <img x-show="m.poster" :src="m.poster" class="absolute inset-0 h-full w-full object-cover" alt="" onerror="this.style.display='none'">
                    <span class="relative text-3xl" x-text="m.kind === 'gold' ? '👑' : '🪙'"></span>
                    <span class="absolute right-2 top-2 rounded-full bg-black/55 px-2 py-0.5 text-[11px] font-semibold backdrop-blur" x-text="'ดู ' + m.required + ' วิ'"></span>
                </div>
                <div class="flex flex-1 flex-col p-4">
                    <div class="font-semibold" x-text="m.title"></div>
                    <div class="mt-0.5 text-xs text-cream/45" x-text="m.desc"></div>
                    <div class="mt-2 text-sm font-bold" :class="m.kind === 'gold' ? 'text-[#ffd76a]' : 'text-[#d6dce6]'" x-text="'รับ ' + m.reward"></div>
                    <div class="mt-3">
                        <template x-if="m.status === 'earned'">
                            <div class="rounded-lg bg-success/10 py-2 text-center text-sm font-semibold text-success" x-text="m.repeat === 'daily' ? '✓ รับแล้ววันนี้' : '✓ ทำสำเร็จแล้ว'"></div>
                        </template>
                        <template x-if="m.status !== 'earned'">
                            <button @click="open(m)" class="btn-brand w-full py-2 text-sm">▶ ทำภารกิจ</button>
                        </template>
                    </div>
                </div>
            </div>
        </template>
    </div>

    {{-- watch modal --}}
    <div x-show="active" x-cloak @keydown.escape.window="close()" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/85 p-4">
        <div class="nx-card w-full max-w-2xl p-5" @click.stop>
            <div class="mb-3 flex items-center justify-between">
                <h3 class="truncate text-base font-semibold" x-text="active?.title"></h3>
                <button @click="close()" class="flex h-9 w-9 items-center justify-center rounded-full bg-white/10 hover:bg-white/20">✕</button>
            </div>

            <div class="relative aspect-video w-full overflow-hidden rounded-lg bg-black">
                <div x-ref="player" class="h-full w-full"></div>
            </div>

            <template x-if="msg">
                <p class="mt-3 text-center text-sm text-[#ff6b81]" x-text="msg"></p>
            </template>

            <template x-if="!done && !msg">
                <div class="mt-4">
                    <div class="h-2.5 overflow-hidden rounded-full bg-white/10">
                        <div class="h-full rounded-full nx-gradient transition-all" :style="'width:' + pct() + '%'"></div>
                    </div>
                    <div class="mt-2 flex items-center justify-between text-xs text-cream/60">
                        <span x-text="'ดูแล้ว ' + watched + ' / ' + required + ' วินาที'"></span>
                        <span x-show="!playing" x-cloak class="text-gold">⏸ กดเล่นวิดีโอเพื่อนับเวลา</span>
                    </div>
                </div>
            </template>

            <template x-if="done">
                <div class="mt-4 rounded-lg bg-success/10 py-4 text-center">
                    <div class="text-lg font-bold text-success">🎉 สำเร็จ! รับ <span x-text="reward"></span></div>
                    <button @click="close()" class="btn-brand mt-3 px-6 py-2 text-sm">เยี่ยม</button>
                </div>
            </template>
        </div>
    </div>
</div>

@push('scripts')
<script>
    function missionsPage(cfg) {
        return {
            missions: cfg.missions,
            active: null, token: null, watched: 0, required: 0, playing: false, done: false, reward: '', msg: '',
            _hb: null, _yt: null, _v: null,

            async open(m) {
                this.active = m; this.token = null; this.watched = 0; this.required = m.required;
                this.playing = false; this.done = false; this.reward = ''; this.msg = '';
                try {
                    const r = await window.nxPost(m.startUrl, {});
                    if (!r || !r.ok) { this.msg = (r && r.error) || 'เริ่มภารกิจไม่ได้'; return; }
                    this.token = r.token; this.required = r.required; this.watched = r.watched || 0;
                    this.$nextTick(() => this.mountPlayer(m));
                    this.startHeartbeat(m);
                } catch (e) { this.msg = 'เกิดข้อผิดพลาด ลองใหม่'; }
            },

            mountPlayer(m) {
                const box = this.$refs.player;
                if (!box) return;
                if (m.source === 'youtube') {
                    this.ytReady().then(() => {
                        this._yt = new YT.Player(box, {
                            width: '100%', height: '100%', videoId: m.ref,
                            playerVars: { autoplay: 1, rel: 0, modestbranding: 1, playsinline: 1 },
                            events: { onStateChange: (e) => { this.playing = (e.data === 1); } },
                        });
                    });
                } else {
                    box.innerHTML = '';
                    const v = document.createElement('video');
                    v.setAttribute('playsinline', ''); v.controls = true; v.className = 'h-full w-full bg-black object-contain';
                    box.appendChild(v); this._v = v;
                    v.addEventListener('play', () => this.playing = true);
                    v.addEventListener('pause', () => this.playing = false);
                    v.addEventListener('ended', () => this.playing = false);
                    window.nxAttachVideo ? window.nxAttachVideo(v, m.ref) : (v.src = m.ref);
                    v.play && v.play().catch(() => {});
                }
            },

            // Resolve once the YouTube IFrame API is ready (loaded once, then polled).
            ytReady() {
                return new Promise((res) => {
                    if (window.YT && window.YT.Player) return res();
                    if (!window._nxYtLoading) {
                        window._nxYtLoading = true;
                        const s = document.createElement('script'); s.src = 'https://www.youtube.com/iframe_api'; document.head.appendChild(s);
                    }
                    const iv = setInterval(() => { if (window.YT && window.YT.Player) { clearInterval(iv); res(); } }, 150);
                });
            },

            startHeartbeat(m) { this.stopHeartbeat(); this._hb = setInterval(() => this.beat(m), 15000); },
            stopHeartbeat() { if (this._hb) { clearInterval(this._hb); this._hb = null; } },

            async beat(m) {
                // Only count while the video is actually playing AND this tab is focused/visible.
                if (this.done || !this.token) return;
                if (!this.playing || document.visibilityState !== 'visible') return;
                try {
                    const r = await window.nxPost(m.beatUrl, { token: this.token });
                    if (!r || !r.ok) return;
                    this.watched = r.watched; this.required = r.required;
                    if (r.done) {
                        this.done = true; this.stopHeartbeat();
                        if (r.reward) { this.reward = r.reward.label; }
                        const c = this.missions.find((x) => x.id === m.id); if (c) c.status = 'earned';
                    }
                } catch (e) {}
            },

            pct() { return this.required ? Math.min(100, Math.round(this.watched / this.required * 100)) : 0; },

            close() {
                this.stopHeartbeat();
                try { if (this._yt && this._yt.destroy) this._yt.destroy(); } catch (e) {}
                try { if (this._v) { this._v.pause(); this._v.removeAttribute('src'); this._v.load(); } } catch (e) {}
                this._yt = null; this._v = null; this.active = null;
            },
        };
    }
</script>
@endpush
@endsection
