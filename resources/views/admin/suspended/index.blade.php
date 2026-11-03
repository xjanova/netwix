@extends('layouts.admin')
@section('page-title', 'หยุดเผยแพร่ (ปัญหาการเล่น)')
@section('page-subtitle', 'หนังที่ถูกหยุดเผยแพร่อัตโนมัติเพราะผู้ใช้หลายคนเล่นไม่ได้ — ลองเล่น หาลิงก์ทดแทนจากเว็บอื่น แล้วเผยแพร่ใหม่')

@section('content')
@php
    $reasonText = [
        'no_source' => 'ลิงค์ต้นทางตาย (ระบบตรวจเจอ)',
        'player' => 'ผู้ใช้เปิดแล้วเล่นไม่ได้',
        'all_links_failed' => 'ลองทุกลิงก์แล้วเล่นไม่ได้',
        'dead_manifest' => 'ต้นทางส่งไฟล์วิดีโอเสีย',
    ];
@endphp

@if (session('status'))
    <div class="mb-4 rounded-lg border border-success/30 bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</div>
@endif

<div class="mb-5 rounded-xl border border-white/5 bg-white/[0.02] px-4 py-3 text-[13px] leading-relaxed text-cream/50">
    ระบบจะหยุดเผยแพร่หนังให้อัตโนมัติเมื่อมีผู้ชม <b class="text-cream/75">ตั้งแต่ 5 คนขึ้นไป</b> เปิดแล้วเล่นไม่ได้ (หรือระบบตรวจพบว่าลิงค์ต้นทางตาย) —
    บอทจะค้นหาเรื่องเดียวกันจากเว็บอื่นให้ทุกวัน ถ้าเจอที่ชื่อตรงกันและเล่นได้จะใส่ลิงก์และเผยแพร่ให้เอง เรื่องที่เหลือในหน้านี้
    กด <span class="text-cream">🔍 หาลิงก์ทดแทน</span> เพื่อดูตัวเลือกที่ใกล้เคียง ลองเล่นก่อน แล้วกด <span class="text-cream">ใช้ลิงก์นี้</span>
    · ถ้าไม่เจอเลย ใส่ลิงก์เองได้ที่ <a href="{{ route('admin.force-link.index') }}" class="text-cream underline">บังคับลิงก์</a> หรือหน้าแก้ไขเรื่อง
</div>

@if ($items->total() === 0)
    <div class="nx-card p-10 text-center text-cream/55">
        <div class="text-4xl">✅</div>
        <div class="mt-3">ยังไม่มีหนังที่มีปัญหา — ทุกเรื่องเล่นได้ปกติ</div>
    </div>
@else
<div x-data="suspendedPage()">
    <div class="text-[13px] text-cream/45">พบ {{ number_format($items->total()) }} เรื่องที่มีปัญหา</div>
    <div class="mt-3 space-y-2.5">
        @foreach ($items as $c)
            @php $firstEp = $c->episodes->first(); @endphp
            <div class="nx-card p-3.5">
                <div class="flex flex-wrap items-center gap-4">
                    <img src="{{ $c->poster_url }}" alt="" referrerpolicy="no-referrer" onerror="this.style.visibility='hidden'"
                         class="h-20 w-14 shrink-0 rounded-lg object-cover ring-1 ring-white/10" style="background:#1a1420">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <a href="{{ route('admin.contents.edit', $c) }}" class="truncate font-semibold hover:underline">{{ $c->title }}</a>
                            <span class="rounded-full bg-white/5 px-2 py-0.5 text-[11px] text-cream/50">{{ $siteNames[$c->source] ?? ($c->source ?: '—') }}</span>
                            <span class="rounded-full bg-white/5 px-2 py-0.5 text-[11px] text-cream/50">{{ ['series' => 'ซีรี่ส์', 'movie' => 'ภาพยนตร์', 'vertical' => 'แนวตั้ง'][$c->type] ?? $c->type }}</span>
                            <span class="rounded-full bg-white/5 px-2 py-0.5 text-[11px] text-cream/50">{{ $c->episodes->count() }} ตอน</span>
                        </div>
                        <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[12px] text-cream/45">
                            <span class="text-[#ff6b81]">✗ {{ $reasonText[$c->suspend_reason] ?? $c->suspend_reason }}</span>
                            <span>· เล่นไม่ได้ {{ $c->playback_fail_count }} คน</span>
                            <span>· หยุดเมื่อ {{ $c->suspended_at?->diffForHumans() }}</span>
                            <span>· ลิงก์สำรอง {{ $c->mirrors->count() }} แหล่ง{{ $c->mirrors->isNotEmpty() ? ' ('.$c->mirrors->map(fn ($m) => $siteNames[$m->source] ?? $m->source)->implode(', ').')' : '' }}</span>
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        @if ($firstEp)
                            <button type="button" @click="preview(@js(route('admin.preview.episode', $firstEp)), @js($c->title.' · ตอนที่ '.$firstEp->number))"
                                    class="rounded-lg bg-white/5 px-3 py-2 text-[13px] text-cream/70 hover:bg-white/10">▶ ลองเล่น</button>
                        @endif
                        <button type="button" @click="find({{ $c->id }}, @js(route('admin.suspended.candidates', $c)))"
                                class="rounded-lg bg-[#3b82f6]/15 px-3 py-2 text-[13px] text-[#8ab4ff] hover:bg-[#3b82f6]/25">🔍 หาลิงก์ทดแทน</button>
                        <form method="POST" action="{{ route('admin.suspended.republish', $c) }}">
                            @csrf
                            <button class="rounded-lg bg-success/15 px-3.5 py-2 text-[13px] font-semibold text-success hover:bg-success/25">↑ เผยแพร่อีกครั้ง</button>
                        </form>
                        <form method="POST" action="{{ route('admin.contents.destroy', $c) }}"
                              onsubmit="return confirm(@js('ลบ “'.$c->title.'” ทิ้งถาวร?'))">
                            @csrf @method('DELETE')
                            <button class="rounded-lg bg-[#e5484d]/15 px-3.5 py-2 text-[13px] text-[#ff6b81] hover:bg-[#e5484d]/25">🗑 ลบ</button>
                        </form>
                    </div>
                </div>

                {{-- Replacement candidates from every synced site. --}}
                <div x-show="open === {{ $c->id }}" x-cloak class="mt-3 border-t border-white/5 pt-3">
                    <div x-show="loading" class="py-4 text-center text-[13px] text-cream/50">⏳ กำลังค้นหาเรื่องเดียวกันจากทุกเว็บ…</div>
                    <div x-show="!loading && error" class="py-3 text-center text-[13px] text-[#ff6b81]" x-text="error"></div>
                    <div x-show="!loading && !error && results.length === 0" class="py-3 text-center text-[13px] text-cream/50">
                        ไม่พบเรื่องนี้ในเว็บอื่นที่ระบบซิงค์ไว้ — ใส่ลิงก์เองได้ที่หน้าแก้ไขเรื่อง หรือเพิ่มเว็บใหม่เข้าระบบ
                    </div>
                    <div class="space-y-1.5" x-show="!loading && results.length">
                        <template x-for="r in results" :key="r.id">
                            <div class="flex flex-wrap items-center gap-3 rounded-lg bg-white/[0.03] p-2">
                                <img :src="r.poster" alt="" referrerpolicy="no-referrer" onerror="this.style.visibility='hidden'" class="h-12 w-9 shrink-0 rounded object-cover" style="background:#1a1420">
                                <div class="min-w-0 flex-1 text-[13px]">
                                    <div class="truncate" x-text="r.title"></div>
                                    <div class="mt-0.5 flex flex-wrap gap-x-2 text-[11px] text-cream/45">
                                        <span class="text-cream/70" x-text="r.site + (r.same_site ? ' (เว็บเดิม · ลิงก์ใหม่)' : '')"></span>
                                        <span x-show="r.year" x-text="'· ' + r.year"></span>
                                        <span x-show="r.episodes" x-text="'· ' + r.episodes + ' ตอน'"></span>
                                        <span :class="r.score >= 90 ? 'text-success' : 'text-[#f5a524]'" x-text="r.score >= 90 ? '· ชื่อตรงกัน' : '· ชื่อใกล้เคียง ' + r.score + '%'"></span>
                                        <span x-show="r.mirrored" class="text-cream/60">· เป็นลิงก์สำรองอยู่แล้ว</span>
                                    </div>
                                </div>
                                <button type="button" @click="preview(r.preview, r.site + ' · ' + r.title)"
                                        class="rounded-lg bg-white/5 px-2.5 py-1.5 text-[12px] text-cream/70 hover:bg-white/10">▶ ลองเล่น</button>
                                <button type="button" @click="attach({{ $c->id }}, @js(route('admin.suspended.attach', $c)), r)" :disabled="busy !== null"
                                        class="rounded-lg bg-success/15 px-2.5 py-1.5 text-[12px] font-semibold text-success hover:bg-success/25 disabled:opacity-50"
                                        x-text="busy === r.id ? 'กำลังตรวจ…' : 'ใช้ลิงก์นี้'"></button>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-5">{{ $items->links() }}</div>

    {{-- Admin player: plays through the admin preview proxy, so an unpublished title still plays here. --}}
    <div x-show="pv" x-cloak class="fixed inset-0 z-[65] flex items-center justify-center bg-black/80 p-4" @click.self="pvClose()" @keydown.escape.window="pvClose()">
        <div class="nx-card w-full max-w-2xl p-5">
            <div class="mb-3 flex items-center justify-between gap-3">
                <h3 class="truncate text-base font-semibold" x-text="pv?.title"></h3>
                <button type="button" @click="pvClose()" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white/10 hover:bg-white/20">✕</button>
            </div>
            <video x-ref="pvVid" x-show="pv && !pvLoading && !pvError && !pvEmbed" controls playsinline class="mb-2 max-h-[60vh] w-full rounded-lg bg-black"></video>
            <iframe x-show="pvEmbed" x-cloak :src="pvEmbed"
                    sandbox="allow-scripts allow-same-origin allow-presentation allow-forms" allow="autoplay; fullscreen; encrypted-media"
                    class="mb-2 aspect-video max-h-[60vh] w-full rounded-lg border-0 bg-black"></iframe>
            <div x-show="pvLoading" x-cloak class="mb-2 rounded-lg border border-dashed border-white/10 bg-white/[0.02] py-12 text-center text-sm text-cream/50">⏳ กำลังขอวิดีโอ (ลองทุกลิงก์ของเรื่องนี้)…</div>
            <div x-show="pvError" x-cloak class="mb-2 rounded-lg border border-dashed border-[#ff6b81]/20 bg-[#ff6b81]/[0.05] py-12 text-center text-sm text-[#ff6b81]">เล่นไม่ได้ — ทุกลิงก์ของเรื่องนี้ยังตายอยู่ ลองกด 🔍 หาลิงก์ทดแทน</div>
        </div>
    </div>
</div>

<script>
    window.suspendedPage = () => ({
        open: null, loading: false, error: '', results: [], busy: null,
        pv: null, pvLoading: false, pvError: false, pvEmbed: null,
        async find(id, url) {
            if (this.open === id) { this.open = null; return; }
            this.open = id; this.loading = true; this.error = ''; this.results = [];
            try {
                const r = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } });
                const d = await r.json();
                if (this.open !== id) return;
                this.results = d.results || [];
            } catch (e) {
                if (this.open === id) this.error = 'ค้นหาไม่สำเร็จ — ลองใหม่อีกครั้ง';
            }
            if (this.open === id) this.loading = false;
        },
        async attach(id, url, r, force = false) {
            if (this.busy !== null) return;
            this.busy = r.id;
            try {
                const body = new URLSearchParams({ _token: document.querySelector('meta[name="csrf-token"]').content, source_title: r.id });
                if (force) body.set('force', '1');
                const res = await fetch(url, { method: 'POST', body, headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } });
                const d = await res.json().catch(() => null);
                this.busy = null;
                if (d && d.ok) { alert(d.message); window.location.reload(); return; }
                if (d && d.need_confirm) { if (confirm(d.message)) await this.attach(id, url, r, true); return; }
                alert((d && d.message) || 'ใช้ลิงก์นี้ไม่สำเร็จ — ลองใหม่อีกครั้ง');
            } catch (e) {
                this.busy = null;
                alert('ใช้ลิงก์นี้ไม่สำเร็จ — เชื่อมต่อไม่ได้');
            }
        },
        preview(url, title) {
            const active = { url, title };
            this.pvReset();
            this.pv = active; this.pvLoading = true;
            this.$nextTick(async () => {
                const v = this.$refs.pvVid;
                try {
                    const d = await window.nxAdminPreview(url, { isCurrent: () => this.pv === active });
                    if (this.pv !== active) return;
                    this.pvLoading = false;
                    if (d && d.ready && d.url && d.kind === 'embed') { this.pvEmbed = d.url; }
                    else if (d && d.ready && d.url) { window.nxAttachVideo(v, d.url, null, d.kind); v.play?.().catch(() => {}); }
                    else { this.pvError = true; }
                } catch (e) { if (this.pv === active) { this.pvLoading = false; this.pvError = true; } }
            });
        },
        pvReset() {
            const v = this.$refs.pvVid;
            if (v) { try { v.pause(); v.removeAttribute('src'); v.load(); } catch (e) {} }
            this.pvLoading = false; this.pvError = false; this.pvEmbed = null;
        },
        pvClose() { this.pvReset(); this.pv = null; },
    });
</script>
@endif
@endsection
