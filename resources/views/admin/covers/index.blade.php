@extends('layouts.admin')
@section('page-title', 'ปกที่หายไป')
@section('page-subtitle', 'หนังที่ยังไม่มีปก หรือปกโหลดไม่ขึ้น — อัปโหลดเองหรือให้ระบบไปหาจากเว็บต้นทางให้')

@section('content')
@php
    $typeLabel = ['series' => 'ซีรี่ส์', 'movie' => 'ภาพยนตร์', 'vertical' => 'แนวตั้ง'];
    $tabs = [
        'all' => ['ต้องแก้ทั้งหมด', $counts['all']],
        'none' => ['ไม่มีปกเลย', $counts['none']],
        'broken' => ['ปกเสีย (โหลดไม่ขึ้น)', $counts['broken']],
        'hotlink' => ['ปกยืมจากต้นทาง', $counts['hotlink']],
    ];
@endphp

<div class="mb-5 rounded-xl border border-white/5 bg-white/[0.02] px-4 py-3 text-[13px] leading-relaxed text-cream/50">
    รูปที่อัปโหลดจะถูกแปลงเป็น <b class="text-cream/75">WebP</b> และย่อให้เหลือด้านยาว {{ \App\Support\PosterBackfill::COVER_MAX_DIM }}px ให้อัตโนมัติ — ลากรูปมาวางบนการ์ด, กดปุ่มเลือกไฟล์, หรือคลิกการ์ดแล้วกด Ctrl+V ก็ได้<br>
    <span class="text-cream/40">“ปกยืมจากต้นทาง” คือปกที่ยังโหลดได้ แต่ดึงมาจากเซิร์ฟเวอร์ของเว็บอื่น — ช้ากว่าและวันหนึ่งเขาลบก็หายทันที กด “ดึงมาเก็บ” เพื่อย้ายมาไว้ที่เรา</span>
</div>

{{-- Buckets double as the filter: the count IS the reason to click. --}}
<div class="mb-4 flex flex-wrap items-center gap-2">
    @foreach ($tabs as $key => [$label, $n])
        <a href="{{ route('admin.covers.index', array_filter(['bucket' => $key === 'all' ? null : $key, 'source' => $source ?: null, 'q' => $q ?: null])) }}"
           class="rounded-lg px-3.5 py-2 text-[13px] {{ $bucket === $key ? 'bg-brand/20 font-semibold text-brand' : 'bg-white/5 text-cream/60 hover:bg-white/10' }}">
            {{ $label }}
            <span class="ml-1 rounded-full bg-black/25 px-1.5 py-0.5 text-[11px]">{{ number_format($n) }}</span>
        </a>
    @endforeach
</div>

<div class="mb-5 flex flex-wrap items-center gap-2" x-data="coverTools(@js(route('admin.covers.scan')))">
    <form method="GET" class="flex flex-wrap items-center gap-2">
        <input type="hidden" name="bucket" value="{{ $bucket }}">
        <input name="q" value="{{ $q }}" placeholder="ค้นชื่อเรื่อง…" class="nx-input w-56 py-2 text-sm">
        <select name="source" class="nx-input w-52 py-2 text-sm" onchange="this.form.submit()">
            <option value="">ทุกแหล่ง</option>
            @foreach ($sources as $sid => $n)
                <option value="{{ $sid }}" @selected($source === $sid)>{{ $sid }} ({{ number_format($n) }})</option>
            @endforeach
        </select>
        <button class="rounded-lg bg-white/10 px-3.5 py-2 text-[13px] hover:bg-white/15">ค้นหา</button>
    </form>

    <button type="button" @click="scan()" x-bind:disabled="busy"
            class="ml-auto rounded-lg bg-white/10 px-3.5 py-2 text-[13px] hover:bg-white/15 disabled:opacity-50"
            x-text="busy ? 'กำลังตรวจ…' : '🔎 ตรวจหาปกเสีย'"></button>
    <span class="text-[12px] text-cream/50" x-text="msg"></span>
</div>

@if ($items->total() === 0)
    <div class="nx-card p-10 text-center text-cream/55">
        <div class="text-4xl">✅</div>
        <div class="mt-3">ไม่มีเรื่องไหนขาดปกในหมวดนี้</div>
    </div>
@else
    <div class="mb-3 text-[13px] text-cream/45">พบ {{ number_format($items->total()) }} เรื่อง</div>

    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
        @foreach ($items as $c)
            <div class="nx-card overflow-hidden p-0 focus:outline-none focus:ring-2 focus:ring-brand/60"
                 tabindex="0"
                 x-data="coverRow({
                     upload: @js(route('admin.covers.upload', $c)),
                     url: @js(route('admin.covers.url', $c)),
                     search: @js(route('admin.covers.search', $c)),
                     auto: @js(route('admin.covers.auto', $c)),
                     localize: @js(route('admin.covers.localize', $c)),
                     {{-- ผ่าน proxy ของเราเมื่อปกยังอยู่ที่ต้นทาง ไม่งั้นจะเห็นแบนเนอร์โฆษณาของเขาแทนปกจริง --}}
                     poster: @js(\App\Support\MediaUrl::adminPoster($c->poster_url)),
                     proxy: @js(route('admin.img-proxy')),
                 })"
                 @dragover.prevent="drag = true" @dragleave.prevent="drag = false"
                 @drop.prevent="drag = false; fromFile($event.dataTransfer.files[0])"
                 @paste="pasted($event)"
                 :class="drag ? 'ring-2 ring-brand' : ''">

                {{-- Preview: the branded gradient is the base layer, exactly like the public card, so an
                     empty slot looks here the way it looks to a viewer. --}}
                <div class="relative aspect-[2/3] w-full" style="background: {{ $c->gradient }}">
                    <template x-if="cover">
                        <img :src="cover" alt="" referrerpolicy="no-referrer" class="h-full w-full object-cover">
                    </template>
                    <div x-show="!cover" class="flex h-full w-full items-center justify-center" x-cloak>
                        <img src="{{ asset('assets/netwix-icon.png') }}" alt="" class="h-9 w-9 opacity-40">
                    </div>

                    <div x-show="saved" x-cloak
                         class="absolute inset-x-0 bottom-0 bg-success/85 py-1 text-center text-[12px] font-semibold text-ink">
                        ✓ ตั้งปกแล้ว<span x-show="via" x-text="' · ' + via"></span>
                    </div>
                    <div x-show="busy" x-cloak class="absolute inset-0 flex items-center justify-center bg-black/60 text-[12px] text-cream/80">
                        <span x-text="busyText"></span>
                    </div>
                </div>

                <div class="p-2.5">
                    <div class="truncate text-[13px] font-semibold" title="{{ $c->title }}">{{ $c->title }}</div>
                    <div class="mt-0.5 flex flex-wrap items-center gap-x-2 text-[11px] text-cream/40">
                        <span>{{ $c->source ?: '—' }}</span>
                        <span>· {{ $typeLabel[$c->type] ?? $c->type }}</span>
                        <span>· {{ number_format($c->views) }} วิว</span>
                        @unless ($c->is_published)<span class="text-[#ff6b81]">· ยังไม่เผยแพร่</span>@endunless
                    </div>

                    <div class="mt-2 grid grid-cols-2 gap-1.5">
                        <button type="button" @click="$refs.f.click()" x-bind:disabled="busy"
                                class="rounded-lg bg-brand/15 px-2 py-1.5 text-[12px] font-semibold text-brand hover:bg-brand/25 disabled:opacity-50">⬆ อัปโหลด</button>
                        <input x-ref="f" type="file" accept="image/*" class="hidden" @change="fromFile($event.target.files[0]); $event.target.value = ''">

                        @if ($bucket === 'hotlink')
                            <button type="button" @click="run('localize', 'กำลังดึงมาเก็บ…')" x-bind:disabled="busy"
                                    class="rounded-lg bg-white/10 px-2 py-1.5 text-[12px] hover:bg-white/15 disabled:opacity-50">⬇ ดึงมาเก็บ</button>
                        @else
                            <button type="button" @click="run('auto', 'กำลังหาปก…')" x-bind:disabled="busy"
                                    class="rounded-lg bg-white/10 px-2 py-1.5 text-[12px] hover:bg-white/15 disabled:opacity-50">⚡ หาให้อัตโนมัติ</button>
                        @endif

                        <button type="button" @click="lookup()" x-bind:disabled="busy"
                                class="rounded-lg bg-white/10 px-2 py-1.5 text-[12px] hover:bg-white/15 disabled:opacity-50">🔍 เลือกเอง</button>
                        <a href="{{ route('admin.contents.edit', $c) }}" target="_blank"
                           class="rounded-lg bg-white/5 px-2 py-1.5 text-center text-[12px] text-cream/70 hover:bg-white/10">✎ แก้ไข</a>
                    </div>

                    <p x-show="err" x-cloak class="mt-1.5 text-[11px] leading-tight text-[#ff6b81]" x-text="err"></p>

                    {{-- Search results: candidates only. Applying one is always a click, because a match
                         made on a title can be the wrong film. --}}
                    <div x-show="open" x-cloak class="mt-2 border-t border-white/5 pt-2">
                        <div x-show="!cands.length" class="text-[11px] text-cream/40" x-text="searchMsg"></div>
                        <div class="grid grid-cols-3 gap-1.5">
                            <template x-for="cd in cands" :key="cd.image">
                                <button type="button" @click="take(cd)" x-bind:disabled="busy"
                                        class="group relative overflow-hidden rounded-md ring-1 ring-white/10 hover:ring-brand disabled:opacity-50"
                                        :title="cd.title + ' — ' + cd.source + ' (' + Math.round(cd.score * 100) + '%)'">
                                    {{-- ผ่าน proxy เช่นกัน — รูปจากผลค้นหามาจากเว็บต้นทางโดยตรง --}}
                                    <img :src="proxy + '?url=' + encodeURIComponent(cd.image)" alt=""
                                         referrerpolicy="no-referrer" class="aspect-[2/3] w-full object-cover">
                                    <span class="absolute inset-x-0 bottom-0 bg-black/70 py-0.5 text-[10px] text-cream/80"
                                          x-text="Math.round(cd.score * 100) + '%'"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-5">{{ $items->links() }}</div>
@endif

@push('scripts')
<script>
    /**
     * One card in the missing-covers grid. Every action ends the same way — the server answers with
     * the stored cover's URL and the card swaps it in — so the admin sees the real, converted image
     * rather than a local preview that might not match what was saved.
     */
    function coverRow(cfg) {
        return {
            cover: cfg.poster || '', proxy: cfg.proxy || '',
            busy: false, busyText: '', saved: false, via: '', err: '',
            drag: false, open: false, cands: [], searchMsg: '',

            /** Read a picked/dropped/pasted file and send it as a data URL (the existing upload shape). */
            fromFile(file) {
                // Drop and paste have no disabled state to lean on, unlike the buttons — guard here so a
                // second image landing mid-upload can't race the first one onto the same title.
                if (!file || this.busy) return;
                if (!file.type.startsWith('image/')) { this.err = 'ไฟล์นี้ไม่ใช่รูปภาพ'; return; }
                if (file.size > 8_000_000) { this.err = 'ไฟล์ใหญ่เกิน 8MB'; return; }
                this.err = ''; this.busy = true; this.busyText = 'กำลังอัปโหลด…';
                const r = new FileReader();
                r.onload = async () => {
                    try {
                        this.applied(await window.nxPostSoft(cfg.upload, { image: r.result }));
                    } catch (e) { this.err = 'อัปโหลดไม่สำเร็จ'; }
                    finally { this.busy = false; }
                };
                r.onerror = () => { this.busy = false; this.err = 'อ่านไฟล์ไม่ได้'; };
                r.readAsDataURL(file);
            },

            /** Ctrl+V — an image on the clipboard becomes the cover; anything else is ignored. */
            pasted(e) {
                const item = [...(e.clipboardData?.items || [])].find(i => i.type.startsWith('image/'));
                if (item) { e.preventDefault(); this.fromFile(item.getAsFile()); }
            },

            /**
             * POST an endpoint that resolves a cover on the server (auto / localize).
             *
             * nxPostSoft, not nxPost: these calls scrape live source sites, and nxPost THROWS on a 4xx
             * without handing back the body — the only way to show the server's reason would be to
             * fire the whole expensive request a second time.
             */
            async run(which, label) {
                this.err = ''; this.busy = true; this.busyText = label;
                try {
                    this.applied(await window.nxPostSoft(cfg[which]));
                } catch (e) { this.err = 'ทำรายการไม่สำเร็จ'; }
                finally { this.busy = false; }
            },

            /** Ask the sources what they have under this title's name. */
            async lookup() {
                this.open = true; this.err = ''; this.cands = []; this.searchMsg = 'กำลังค้นหาจากเว็บต้นทาง…';
                try {
                    const res = await fetch(cfg.search, { headers: { Accept: 'application/json' } });
                    const data = await res.json();
                    this.cands = data.candidates || [];
                    this.searchMsg = this.cands.length ? '' : 'ไม่พบปกที่ชื่อใกล้เคียงในเว็บต้นทาง';
                } catch (e) { this.searchMsg = 'ค้นหาไม่สำเร็จ'; }
            },

            /** Use one of the search results. */
            async take(cd) {
                this.err = ''; this.busy = true; this.busyText = 'กำลังบันทึกปก…';
                try {
                    const res = await window.nxPostSoft(cfg.url, { url: cd.image });
                    if (res && res.ok) this.open = false;
                    this.applied(res, cd.source);
                } catch (e) { this.err = 'โหลดรูปนี้ไม่ได้ ลองรูปอื่น'; }
                finally { this.busy = false; }
            },

            /** Show the stored cover, or the server's own reason for not storing one. */
            applied(res, via) {
                if (!res || !res.ok || !res.url) {
                    this.err = (res && res.error) || 'บันทึกไม่สำเร็จ';

                    return;
                }
                this.err = ''; this.cover = res.url; this.saved = true; this.via = via || res.via || '';
            },
        };
    }

    function coverTools(scanUrl) {
        return {
            busy: false, msg: '',
            async scan() {
                this.busy = true; this.msg = 'กำลังตรวจปกที่ยืมมาจากต้นทาง…';
                try {
                    const r = await window.nxPost(scanUrl);
                    this.msg = r.message || 'ตรวจเสร็จแล้ว';
                    // A pass that healed or flagged something changed the list — show it.
                    if (r.healed || r.dead) setTimeout(() => window.location.reload(), 1200);
                } catch (e) { this.msg = 'ตรวจไม่สำเร็จ'; }
                finally { this.busy = false; }
            },
        };
    }
</script>
@endpush
@endsection
