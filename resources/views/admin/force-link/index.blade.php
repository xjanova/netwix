@extends('layouts.admin')
@section('page-title', 'บังคับอัพเดทลิ้งค์หนัง')
@section('page-subtitle', 'ค้นชื่อหนังในเว็บเรา → เลือกเว็บสำรอง 1 เว็บ → บังคับใช้ลิ้งค์จากเว็บนั้นแทนต้นทางเดิม (เล่นลิ้งค์นี้ก่อนเสมอ)')

@section('content')
<div x-data="forceLink()" class="max-w-3xl">

    {{-- result toast --}}
    <template x-if="msg">
        <div class="mb-4 rounded-xl border px-4 py-3 text-[13px]"
             :class="msgOk ? 'border-success/30 bg-success/10 text-success' : 'border-danger/30 bg-danger/10 text-[#ff8f8f]'">
            <span x-text="msg"></span>
        </div>
    </template>

    {{-- STEP 1 — find a title in our catalogue --}}
    <div class="nx-card p-4">
        <div class="mb-2 flex items-center gap-2 text-[13px] font-semibold text-cream/80">
            <span class="grid h-5 w-5 place-items-center rounded-full bg-[#8b5cf6]/20 text-[11px] text-[#c4a5ff]">1</span>
            ค้นหาหนังในเว็บเรา
        </div>
        <input type="text" x-model="q1" @input.debounce.350ms="searchTitles()" @focus="searchTitles()"
               placeholder="พิมพ์ชื่อหนัง / ซีรีส์ ที่ต้องการเปลี่ยนลิ้งค์…"
               class="w-full rounded-lg border border-white/10 bg-white/[0.03] px-3.5 py-2.5 text-sm text-cream placeholder:text-cream/30 focus:border-[#8b5cf6]/50 focus:outline-none">

        <div x-show="searching1" class="mt-3 text-[12px] text-cream/40">กำลังค้นหา…</div>

        {{-- results (hidden once a title is picked) --}}
        <div x-show="!picked && titles.length" class="mt-3 space-y-2">
            <template x-for="t in titles" :key="t.id">
                <button type="button" @click="pick(t)"
                        class="flex w-full items-center gap-3 rounded-lg border border-white/5 bg-white/[0.02] p-2.5 text-left hover:border-[#8b5cf6]/40 hover:bg-white/[0.04]">
                    <img :src="t.poster" referrerpolicy="no-referrer" onerror="this.style.visibility='hidden'"
                         class="h-16 w-11 shrink-0 rounded object-cover ring-1 ring-white/10" style="background:#1a1420">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="truncate text-[13px] font-semibold text-cream/90" x-text="t.title"></span>
                            <span class="shrink-0 rounded-full bg-white/5 px-1.5 py-0.5 text-[10px] text-cream/50" x-text="t.type_label"></span>
                            <template x-if="t.year"><span class="shrink-0 text-[11px] text-cream/35" x-text="t.year"></span></template>
                        </div>
                        <div class="mt-0.5 flex flex-wrap items-center gap-x-2 text-[11px] text-cream/40">
                            <span x-text="'ต้นทาง: ' + t.source"></span>
                            <span x-text="'· ' + t.episodes + ' ตอน'"></span>
                            <template x-if="!t.published"><span class="text-[#ff8f8f]">· ไม่เผยแพร่</span></template>
                            <template x-if="t.forced_site"><span class="text-[#c4a5ff]" x-text="'· บังคับอยู่: ' + t.forced_site"></span></template>
                        </div>
                    </div>
                </button>
            </template>
        </div>
        <div x-show="!picked && !searching1 && q1.length >= 2 && !titles.length" class="mt-3 text-[12px] text-cream/40">ไม่พบหนังชื่อนี้ในเว็บเรา</div>
    </div>

    {{-- STEP 2 — the picked title + choose one pool site + search it --}}
    <div x-show="picked" x-cloak class="nx-card mt-3 p-4">
        <div class="mb-3 flex items-center gap-3 rounded-lg bg-white/[0.03] p-2.5">
            <img :src="picked?.poster" referrerpolicy="no-referrer" onerror="this.style.visibility='hidden'"
                 class="h-16 w-11 shrink-0 rounded object-cover ring-1 ring-white/10" style="background:#1a1420">
            <div class="min-w-0 flex-1">
                <div class="truncate text-[13px] font-semibold text-cream/90" x-text="picked?.title"></div>
                <div class="mt-0.5 text-[11px] text-cream/40">
                    <span x-text="picked?.type_label + ' · ต้นทาง ' + picked?.source + ' · ' + picked?.episodes + ' ตอน'"></span>
                    <template x-if="picked?.forced_site"><span class="text-[#c4a5ff]" x-text="' · บังคับอยู่: ' + picked.forced_site"></span></template>
                </div>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <a :href="picked?.watch_url" target="_blank" class="rounded-lg bg-white/5 px-2.5 py-1.5 text-[12px] text-cream/70 hover:bg-white/10">▶ ลองเล่น</a>
                <button type="button" @click="reset()" class="rounded-lg bg-white/5 px-2.5 py-1.5 text-[12px] text-cream/60 hover:bg-white/10">เปลี่ยนเรื่อง</button>
            </div>
        </div>

        <div class="mb-2 flex items-center gap-2 text-[13px] font-semibold text-cream/80">
            <span class="grid h-5 w-5 place-items-center rounded-full bg-[#8b5cf6]/20 text-[11px] text-[#c4a5ff]">2</span>
            เลือกเว็บสำรอง แล้วค้นหาหนังเรื่องนี้จากเว็บนั้น
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <select x-model="site" class="rounded-lg border border-white/10 bg-white/[0.03] px-3 py-2.5 text-sm text-cream focus:border-[#8b5cf6]/50 focus:outline-none">
                @foreach ($poolSites as $s)
                    <option value="{{ $s['id'] }}">{{ $s['name'] }}</option>
                @endforeach
            </select>
            <input type="text" x-model="q2" @keydown.enter="searchSite()"
                   placeholder="ชื่อหนังบนเว็บสำรอง (แก้ได้)…"
                   class="min-w-0 flex-1 rounded-lg border border-white/10 bg-white/[0.03] px-3.5 py-2.5 text-sm text-cream placeholder:text-cream/30 focus:border-[#8b5cf6]/50 focus:outline-none">
            <button type="button" @click="searchSite()" :disabled="searching2 || q2.length < 2"
                    class="nx-gradient shrink-0 rounded-lg px-4 py-2.5 text-[13px] font-semibold disabled:opacity-40">
                <span x-text="searching2 ? 'กำลังค้นหา…' : 'ค้นหาลิ้งค์'"></span>
            </button>
        </div>

        @if (count($poolSites) === 0)
            <div class="mt-2 text-[12px] text-[#ff8f8f]">ยังไม่มีเว็บสำรองในระบบ — เพิ่มเว็บ Halim ที่ backupPool ก่อน</div>
        @endif

        <template x-if="picked?.forced_site">
            <button type="button" @click="clearForced()" :disabled="applying"
                    class="mt-3 rounded-lg bg-white/5 px-3 py-2 text-[12px] text-cream/60 hover:bg-white/10 disabled:opacity-40">
                ↩ ยกเลิกการบังคับลิ้งค์ (กลับไปใช้ต้นทางเดิม)
            </button>
        </template>
    </div>

    {{-- STEP 3 — candidate links from the chosen site --}}
    <div x-show="picked && (cands.length || searchedSite)" x-cloak class="nx-card mt-3 p-4">
        <div class="mb-2 flex items-center gap-2 text-[13px] font-semibold text-cream/80">
            <span class="grid h-5 w-5 place-items-center rounded-full bg-[#8b5cf6]/20 text-[11px] text-[#c4a5ff]">3</span>
            <span x-text="'เลือกลิ้งค์จาก ' + (siteName || 'เว็บสำรอง') + ' เพื่อบังคับใช้'"></span>
        </div>
        <div x-show="!cands.length && searchedSite && !searching2" class="text-[12px] text-cream/40">ไม่พบหนังเรื่องนี้บนเว็บสำรองที่เลือก — ลองแก้คำค้น หรือเลือกเว็บอื่น</div>
        <div class="space-y-2">
            <template x-for="c in cands" :key="c.key">
                <div class="flex items-center gap-3 rounded-lg border border-white/5 bg-white/[0.02] p-2.5">
                    <img :src="c.poster" referrerpolicy="no-referrer" onerror="this.style.visibility='hidden'"
                         class="h-16 w-11 shrink-0 rounded object-cover ring-1 ring-white/10" style="background:#1a1420">
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-[13px] font-semibold text-cream/90" x-text="c.title"></div>
                        <div class="mt-0.5 text-[11px] text-cream/40">
                            <span x-text="(c.is_movie ? 'ภาพยนตร์' : 'ซีรี่ส์') + (c.year ? ' · ' + c.year : '')"></span>
                            <span class="text-cream/25" x-text="' · id ' + c.key"></span>
                        </div>
                    </div>
                    <button type="button" @click="apply(c, false)" :disabled="applying"
                            class="shrink-0 rounded-lg bg-[#8b5cf6]/20 px-3 py-2 text-[12px] font-semibold text-[#c4a5ff] hover:bg-[#8b5cf6]/30 disabled:opacity-40">
                        บังคับใช้ลิ้งค์นี้
                    </button>
                </div>
            </template>
        </div>

        {{-- verify-failed → offer to force anyway --}}
        <template x-if="confirmCand">
            <div class="mt-3 rounded-lg border border-[#ffb020]/30 bg-[#ffb020]/10 p-3 text-[12px] text-[#ffcf80]">
                <div x-text="confirmMsg"></div>
                <div class="mt-2 flex gap-2">
                    <button type="button" @click="apply(confirmCand, true)" :disabled="applying"
                            class="rounded-lg bg-[#ffb020]/20 px-3 py-1.5 font-semibold text-[#ffcf80] hover:bg-[#ffb020]/30 disabled:opacity-40">บังคับใช้เลย</button>
                    <button type="button" @click="confirmCand=null" class="rounded-lg bg-white/5 px-3 py-1.5 text-cream/60 hover:bg-white/10">ยกเลิก</button>
                </div>
            </div>
        </template>
    </div>
</div>

<script>
function forceLink() {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    return {
        q1: '', titles: [], searching1: false, picked: null,
        site: @js($poolSites[0]['id'] ?? ''), q2: '', cands: [], searching2: false, searchedSite: false, siteName: '',
        applying: false, confirmCand: null, confirmMsg: '',
        msg: null, msgOk: false,

        async searchTitles() {
            const q = this.q1.trim();
            if (q.length < 2) { this.titles = []; return; }
            this.searching1 = true;
            try {
                const r = await fetch('{{ route('admin.force-link.titles') }}?q=' + encodeURIComponent(q),
                    { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
                this.titles = (await r.json()).results || [];
            } catch (e) { this.titles = []; }
            this.searching1 = false;
        },

        pick(t) {
            this.picked = t;
            this.q2 = (t.title || '').replace(/\s*\(\d{4}\)\s*$/, '').trim();
            this.cands = []; this.searchedSite = false; this.confirmCand = null; this.msg = null;
        },

        reset() {
            this.picked = null; this.cands = []; this.searchedSite = false; this.confirmCand = null;
            this.searchTitles();
        },

        async searchSite() {
            const q = this.q2.trim();
            if (q.length < 2 || !this.site) return;
            this.searching2 = true; this.cands = []; this.searchedSite = true; this.confirmCand = null; this.msg = null;
            try {
                const r = await fetch('{{ route('admin.force-link.site') }}?site=' + encodeURIComponent(this.site) + '&q=' + encodeURIComponent(q),
                    { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
                const j = await r.json();
                this.cands = j.results || [];
                this.siteName = j.site || '';
                if (j.error) this.flash(j.error, false);
                else if (!this.cands.length && j.synced === 0)
                    this.flash('ยังไม่ได้ซิงค์แคตตาล็อกของ ' + (j.site || 'เว็บนี้') + ' — ไปหน้า “นำเข้าหนัง” เลือกเว็บนี้ กด“ซิงค์แคตตาล็อก” ก่อน แล้วค่อยกลับมาค้นหา', false);
            } catch (e) { this.flash('ค้นหาจากเว็บสำรองไม่สำเร็จ', false); }
            this.searching2 = false;
        },

        async apply(cand, skipVerify) {
            if (this.applying) return;               // guard double-tap
            this.applying = true; this.msg = null;
            try {
                const r = await fetch('{{ route('admin.force-link.apply') }}', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json',
                               'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: JSON.stringify({ content: this.picked.id, site: this.site, key: cand.key, skip_verify: !!skipVerify }),
                });
                const j = await r.json();
                if (j.need_confirm) { this.confirmCand = cand; this.confirmMsg = j.message; this.applying = false; return; }
                this.confirmCand = null;
                this.flash(j.message || (j.ok ? 'สำเร็จ' : 'ไม่สำเร็จ'), !!j.ok);
                if (j.ok) { this.picked.forced_site = j.site; }
            } catch (e) { this.flash('บันทึกไม่สำเร็จ', false); }
            this.applying = false;
        },

        async clearForced() {
            if (this.applying || !this.picked) return;
            this.applying = true;
            try {
                const r = await fetch('{{ route('admin.force-link.clear') }}', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json',
                               'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: JSON.stringify({ content: this.picked.id }),
                });
                const j = await r.json();
                this.flash(j.message || 'ยกเลิกแล้ว', !!j.ok);
                if (j.ok) this.picked.forced_site = null;
            } catch (e) { this.flash('ยกเลิกไม่สำเร็จ', false); }
            this.applying = false;
        },

        flash(m, ok) { this.msg = m; this.msgOk = ok; },
    };
}
</script>
@endsection
