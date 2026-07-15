@extends('layouts.admin')
@section('page-title', $campaign->exists ? 'แก้ไขแคมเปญ' : 'สร้างแคมเปญคลิปอัตโนมัติ')
@section('page-subtitle', 'ตั้งค่าว่าจะให้ระบบเลือกหนังแบบไหน ตัดคลิปยังไง และโพสต์เฟซบุ๊กเวลาไหนบ้าง')
@section('action')
    <a href="{{ route('admin.clip-campaigns.index') }}" class="rounded-lg border border-white/10 bg-white/5 px-4 py-2.5 text-sm text-cream/70 hover:text-cream">← กลับ</a>
@endsection

@php
    $selType = old('content_type', $campaign->content_type);
    $selPick = old('pick', $campaign->pick ?? 'trending');
    $selAspect = old('aspect', $campaign->aspect ?? '9:16');
    $selTargets = old('targets', $campaign->exists ? $campaign->targetList() : ['reels', 'feed']);
    $selDays = old('days', $campaign->exists ? $campaign->dayList() : []);
    $selSlots = old('slots', $campaign->exists ? $campaign->slotList() : ['18:00']);
    if (empty($selSlots)) { $selSlots = ['18:00']; }
    $dayLabels = ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'];
@endphp

@section('content')
<form method="POST" action="{{ $campaign->exists ? route('admin.clip-campaigns.update', $campaign) : route('admin.clip-campaigns.store') }}"
      x-data="campaignForm({{ Illuminate\Support\Js::from($selSlots) }}, {{ Illuminate\Support\Js::from($campaign->content ? ['id' => $campaign->content->id, 'title' => $campaign->content->title] : null) }}, {{ old('full_episode', $campaign->full_episode) ? 'true' : 'false' }})"
      class="max-w-3xl space-y-6">
    @csrf
    @if ($campaign->exists) @method('PUT') @endif

    {{-- ── Basics ─────────────────────────────────────────────────────────── --}}
    <div class="nx-card space-y-4 p-5">
        <div>
            <label class="mb-1 block text-[12px] text-cream/50">ชื่อแคมเปญ</label>
            <input name="name" value="{{ old('name', $campaign->name) }}" required maxlength="80"
                   placeholder="เช่น หนังมาแรง โพสต์เย็น"
                   class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-cream placeholder:text-cream/30">
        </div>
        <label class="flex items-center gap-3 text-sm">
            <input type="checkbox" name="is_enabled" value="1" @checked(old('is_enabled', $campaign->is_enabled))
                   class="h-4 w-4 rounded border-white/20 bg-white/5 text-brand-2">
            <span>เปิดใช้งานแคมเปญนี้ทันที <span class="text-cream/40">(ต้องเปิด “ระบบอัตโนมัติ” ที่หน้ารวมด้วย ถึงจะโพสต์เอง)</span></span>
        </label>
    </div>

    {{-- ── Title selection ─────────────────────────────────────────────────── --}}
    <div class="nx-card space-y-4 p-5">
        <div class="text-sm font-semibold text-cream/80">เลือกหนังจาก</div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-[12px] text-cream/50">ประเภท</label>
                <select name="content_type" class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-cream">
                    <option value="" @selected($selType === null || $selType === '')>ทุกประเภท</option>
                    <option value="movie" @selected($selType === 'movie')>หนัง</option>
                    <option value="series" @selected($selType === 'series')>ซีรีส์</option>
                    <option value="anime" @selected($selType === 'anime')>อนิเมะ</option>
                    <option value="vertical" @selected($selType === 'vertical')>แนวตั้ง (Shorts)</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-[12px] text-cream/50">ไม่เอาประเภท <span class="text-cream/30">(ไม่บังคับ)</span></label>
                @php $selExclude = old('exclude_type', $campaign->exclude_type); @endphp
                <select name="exclude_type" class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-cream">
                    <option value="" @selected(! $selExclude)>ไม่ตัดออก</option>
                    <option value="movie" @selected($selExclude === 'movie')>ไม่เอาหนัง</option>
                    <option value="series" @selected($selExclude === 'series')>ไม่เอาซีรีส์</option>
                    <option value="anime" @selected($selExclude === 'anime')>ไม่เอาอนิเมะ/การ์ตูน</option>
                    <option value="vertical" @selected($selExclude === 'vertical')>ไม่เอาแนวตั้ง</option>
                </select>
                @error('exclude_type')<p class="mt-1 text-[12px] text-[#ff6b81]">{{ $message }}</p>@enderror
                <p class="mt-1 text-[11px] text-cream/35">การ์ตูนส่วนใหญ่ถูกเก็บเป็น “ซีรีส์” ด้วย — ถ้าทำแคมเปญซีรีส์แยกจากการ์ตูน ให้เลือก “ไม่เอาอนิเมะ/การ์ตูน”</p>
            </div>
            <div>
                <label class="mb-1 block text-[12px] text-cream/50">หมวดหมู่</label>
                <select name="genre_id" class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-cream">
                    <option value="">ทุกหมวด</option>
                    @foreach ($genres as $g)
                        <option value="{{ $g->id }}" @selected((int) old('genre_id', $campaign->genre_id) === $g->id)>{{ $g->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-[12px] text-cream/50">วิธีเลือกเรื่อง</label>
                <select name="pick" class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-cream">
                    <option value="trending" @selected($selPick === 'trending')>มาแรง (ยอดวิวสูงสุด)</option>
                    <option value="random" @selected($selPick === 'random')>สุ่ม</option>
                    <option value="newest" @selected($selPick === 'newest')>ใหม่ล่าสุด</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-[12px] text-cream/50">ไม่โพสต์เรื่องซ้ำภายใน (วัน)</label>
                <input type="number" name="avoid_recent_days" min="0" max="365"
                       value="{{ old('avoid_recent_days', $campaign->avoid_recent_days ?? 14) }}"
                       class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-cream">
            </div>
        </div>

        {{-- Optional: pin ONE specific title (overrides the filters above) --}}
        <div class="relative">
            <label class="mb-1 block text-[12px] text-cream/50">เจาะจงเรื่องเดียว <span class="text-cream/30">(ไม่บังคับ — ใส่แล้วจะข้ามตัวกรองด้านบน)</span></label>
            <input type="hidden" name="content_id" :value="pinned ? pinned.id : ''">
            <template x-if="!pinned">
                <input x-model="titleQ" @input.debounce.300ms="searchTitle()" @focus="searchTitle()"
                       placeholder="พิมพ์ชื่อเรื่องเพื่อค้นหา…"
                       class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-cream placeholder:text-cream/30">
            </template>
            <div x-show="titleResults.length && !pinned" x-cloak
                 class="absolute z-20 mt-1 w-full overflow-hidden rounded-lg border border-white/10 bg-[#141019] shadow-xl">
                <template x-for="r in titleResults" :key="r.id">
                    <button type="button" @click="pin(r)" class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-white/5">
                        <span class="truncate" x-text="r.title"></span>
                        <span class="shrink-0 text-[11px] text-cream/40" x-text="r.episodes + ' ตอน'"></span>
                    </button>
                </template>
            </div>
            <div x-show="pinned" x-cloak class="mt-1 flex items-center gap-2 text-[13px]">
                <span class="rounded-full bg-brand/20 px-3 py-1 text-brand-2" x-text="'🎯 ' + (pinned ? pinned.title : '')"></span>
                <button type="button" @click="unpin()" class="text-cream/40 hover:text-cream">✕ ล้าง</button>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-[12px] text-cream/50">แหล่งที่มา <span class="text-cream/30">(ไม่บังคับ)</span></label>
                <input name="source" value="{{ old('source', $campaign->source) }}" placeholder="เว้นว่าง=ทุกแหล่ง เช่น rongyok"
                       class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-cream placeholder:text-cream/30">
                <p class="mt-1 text-[11px] text-cream/35">บางแหล่ง (เช่น anime108/wowdrama) ตัดคลิปยังไม่ได้เพราะติดโทเคน — ถ้าเจอคลิปล้มเหลวบ่อย ลองล็อกเป็น <code>rongyok</code></p>
            </div>
            <label class="flex items-center gap-3 self-end pb-2 text-sm">
                <input type="checkbox" name="include_adult" value="1" @checked(old('include_adult', $campaign->include_adult))
                       class="h-4 w-4 rounded border-white/20 bg-white/5 text-brand-2">
                <span>รวมหนัง 18+/20+ ด้วย <span class="text-cream/40">(เสี่ยงเพจโดนแบน — แนะนำปิด)</span></span>
            </label>
        </div>
    </div>

    {{-- ── Clip + targets ──────────────────────────────────────────────────── --}}
    <div class="nx-card space-y-4 p-5">
        <div class="text-sm font-semibold text-cream/80">คลิป & ปลายทาง</div>

        {{-- full-episode switch --}}
        <label class="flex items-start gap-3 rounded-lg border border-white/10 bg-white/5 px-3 py-2.5 text-sm"
               :class="fullEp ? 'border-brand/50 bg-brand/10' : ''">
            <input type="checkbox" name="full_episode" value="1" x-model="fullEp"
                   class="mt-0.5 h-4 w-4 rounded border-white/20 bg-white/5 text-brand-2">
            <span>
                โพสต์<b>ทั้งตอน</b> (เต็มความยาว ไม่ตัด)
                <span class="block text-[11px] text-cream/40">ใช้เวลาประมวลผลนานกว่าปกติมาก และไฟล์ต้องไม่เกิน ~1GB (ตอนยาวมากอาจล้มเหลว) — ภาพคงสัดส่วนต้นฉบับ ไม่ครอป</span>
            </span>
        </label>

        <div class="grid gap-4 sm:grid-cols-2" :class="fullEp ? 'pointer-events-none opacity-40' : ''">
            <div>
                <label class="mb-1 block text-[12px] text-cream/50">ความยาวคลิป (วินาที)</label>
                <input type="number" name="duration" min="5" max="600" value="{{ old('duration', $campaign->duration ?? 45) }}"
                       class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-cream">
            </div>
            <div>
                <label class="mb-1 block text-[12px] text-cream/50">สุ่มความยาว สูงสุดไม่เกิน (วินาที) <span class="text-cream/30">(ไม่บังคับ)</span></label>
                <input type="number" name="duration_max" min="5" max="600" value="{{ old('duration_max', $campaign->duration_max) }}"
                       placeholder="เว้นว่าง = ใช้ความยาวคงที่"
                       class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-cream placeholder:text-cream/30">
                <p class="mt-1 text-[11px] text-cream/35">ใส่แล้วแต่ละโพสต์จะสุ่มความยาวระหว่าง “ความยาวคลิป” ถึงค่านี้</p>
            </div>
            <div>
                <label class="mb-1 block text-[12px] text-cream/50">ตำแหน่งที่ตัด</label>
                <select name="start_mode" class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-cream">
                    <option value="middle" @selected(old('start_mode', $campaign->start_mode ?? 'middle') === 'middle')>กลางเรื่อง (เลี่ยงintro/เครดิต)</option>
                    <option value="random" @selected(old('start_mode', $campaign->start_mode ?? 'middle') === 'random')>สุ่มตำแหน่ง (ไม่ซ้ำฉากเดิม)</option>
                    <option value="ending" @selected(old('start_mode', $campaign->start_mode ?? 'middle') === 'ending')>ท้ายตอน (ตัดช่วงสุดท้าย — น่าติดตาม)</option>
                </select>
                <p class="mt-1 text-[11px] text-cream/35">“ท้ายตอน” = เอาช่วงสุดท้ายตามความยาวที่ตั้งไว้ ตัดจบก่อนเครดิตท้ายเรื่อง (ถ้าตอนสั้นกว่านั้นจะได้ทั้งตอน)</p>
            </div>
            <div>
                <label class="mb-1 block text-[12px] text-cream/50">สัดส่วน</label>
                <select name="aspect" class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-cream">
                    <option value="9:16" @selected($selAspect === '9:16')>9:16 แนวตั้ง (Reels)</option>
                    <option value="1:1" @selected($selAspect === '1:1')>1:1 จตุรัส (ฟีด)</option>
                    <option value="16:9" @selected($selAspect === '16:9')>16:9 แนวนอน</option>
                </select>
            </div>
        </div>

        <div>
            <label class="mb-1 block text-[12px] text-cream/50">เลือกตอน (สำหรับซีรีส์/เรื่องที่มีหลายตอน)</label>
            <select name="episode_pick" class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-cream">
                <option value="first" @selected(old('episode_pick', $campaign->episode_pick ?? 'first') === 'first')>ตอนแรกเสมอ</option>
                <option value="random" @selected(old('episode_pick', $campaign->episode_pick ?? 'first') === 'random')>สุ่มตอน</option>
                <option value="sequential" @selected(old('episode_pick', $campaign->episode_pick ?? 'first') === 'sequential')>เรียงตามลำดับ (โพสต์ถัดไป = ตอนถัดจากที่โพสต์ล่าสุด วนกลับตอน 1 เมื่อจบ)</option>
                <option value="unposted" @selected(old('episode_pick', $campaign->episode_pick ?? 'first') === 'unposted')>ไม่ซ้ำเด็ดขาด (เฉพาะตอนที่ยังไม่เคยถูกโพสต์เลย)</option>
            </select>
            <p class="mt-1 text-[11px] text-cream/35">
                ใช้คู่กับ “เจาะจงเรื่องเดียว” ได้ เช่น ปักซีรีส์หนึ่งเรื่องแล้วให้โพสต์คลิปตอน 1, 2, 3, … ตามเวลาที่ตั้ง<br>
                <b>ไม่ซ้ำเด็ดขาด</b> = ดูจากประวัติการโพสต์จริง<b>ของทุกแคมเปญรวมกัน</b> ตอนที่เคยโพสต์แล้วจะไม่ถูกหยิบอีกเลย ถ้าเรื่องไหนโพสต์ครบทุกตอนแล้วจะข้ามไปเรื่องอื่นให้เอง (เหมาะกับสุ่มเรื่องรายชั่วโมง)
            </p>
        </div>
        <div>
            <label class="mb-1.5 block text-[12px] text-cream/50">โพสต์ไปที่</label>
            <div class="flex gap-2">
                @foreach (['reels' => 'Reels', 'feed' => 'ฟีด (โพสต์วิดีโอ)'] as $val => $lbl)
                    <label class="flex flex-1 cursor-pointer items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-3 py-2.5 text-sm has-[:checked]:border-brand/50 has-[:checked]:bg-brand/10">
                        <input type="checkbox" name="targets[]" value="{{ $val }}" @checked(in_array($val, $selTargets, true))
                               class="h-4 w-4 rounded border-white/20 bg-white/5 text-brand-2">
                        <span>{{ $lbl }}</span>
                    </label>
                @endforeach
            </div>
        </div>
        <p class="text-[11px] leading-relaxed text-cream/40">
            แคปชันเขียนให้อัตโนมัติ (AI/เทมเพลต) + ต่อท้ายด้วยลิงก์โหลดแอป NetWix และแฮชแท็กเสมอ —
            ปรับข้อความรีวิว/ลิงก์ได้ที่ “ตั้งค่า / เชื่อมต่อ”
        </p>
    </div>

    {{-- ── Schedule ────────────────────────────────────────────────────────── --}}
    <div class="nx-card space-y-4 p-5">
        <div class="text-sm font-semibold text-cream/80">เวลาโพสต์</div>
        <div>
            <label class="mb-1.5 block text-[12px] text-cream/50">วันที่โพสต์ <span class="text-cream/30">(ไม่เลือกเลย = ทุกวัน)</span></label>
            <div class="flex flex-wrap gap-1.5">
                @foreach ($dayLabels as $i => $lbl)
                    <label class="cursor-pointer">
                        <input type="checkbox" name="days[]" value="{{ $i }}" @checked(in_array($i, array_map('intval', (array) $selDays), true)) class="peer sr-only">
                        <span class="flex h-9 w-11 items-center justify-center rounded-lg border border-white/10 bg-white/5 text-[13px] text-cream/60 peer-checked:border-transparent peer-checked:bg-gradient-to-br peer-checked:from-brand peer-checked:to-brand-2 peer-checked:text-white">{{ $lbl }}</span>
                    </label>
                @endforeach
            </div>
        </div>
        <div>
            <label class="mb-1.5 block text-[12px] text-cream/50">ช่วงเวลา <span class="text-cream/30">(เวลาไทย · เพิ่มได้หลายเวลา)</span></label>
            @error('slots')<p class="mb-1.5 text-[12px] text-[#ff6b81]">{{ $message }}</p>@enderror
            <div class="space-y-2">
                <template x-for="(slot, i) in slots" :key="i">
                    <div class="flex items-center gap-2">
                        <input type="time" name="slots[]" x-model="slots[i]" required
                               class="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-cream [color-scheme:dark]">
                        <button type="button" @click="removeSlot(i)" x-show="slots.length > 1"
                                class="rounded-lg bg-white/5 px-2.5 py-2 text-cream/40 hover:text-[#ff6b81]">✕</button>
                    </div>
                </template>
            </div>
            <button type="button" @click="addSlot()" class="mt-2 rounded-lg border border-dashed border-white/15 px-3 py-1.5 text-[13px] text-cream/60 hover:text-cream">+ เพิ่มเวลา</button>
        </div>
    </div>

    <div class="flex items-center gap-3">
        <button class="nx-gradient rounded-lg px-6 py-2.5 text-sm font-semibold" style="box-shadow:0 8px 22px rgba(176,38,255,0.32)">
            {{ $campaign->exists ? '💾 บันทึกการแก้ไข' : '✅ สร้างแคมเปญ' }}
        </button>
        <a href="{{ route('admin.clip-campaigns.index') }}" class="text-sm text-cream/50 hover:text-cream">ยกเลิก</a>
    </div>
</form>

<script>
function campaignForm(initialSlots, pinnedTitle, fullEpisode) {
    return {
        fullEp: !!fullEpisode,
        slots: (initialSlots && initialSlots.length) ? [...initialSlots] : ['18:00'],
        addSlot() { this.slots.push('12:00'); },
        removeSlot(i) { this.slots.splice(i, 1); if (!this.slots.length) this.slots = ['18:00']; },

        titleQ: '', titleResults: [], pinned: pinnedTitle || null,
        async searchTitle() {
            const q = this.titleQ.trim();
            if (!q) { this.titleResults = []; return; }
            try {
                const r = await fetch('{{ route('admin.clips.search') }}?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } });
                const res = await r.json();
                this.titleResults = res.items || [];
            } catch (e) { this.titleResults = []; }
        },
        pin(r) { this.pinned = { id: r.id, title: r.title }; this.titleResults = []; this.titleQ = ''; },
        unpin() { this.pinned = null; },
    };
}
</script>
@endsection
