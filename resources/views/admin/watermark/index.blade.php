@extends('layouts.admin')
@section('page-title', 'ลายน้ำบนปก')
@section('page-subtitle', 'ฝังโลโก้หรือข้อความลงในไฟล์ปก เพื่อให้ปกของเราติดชื่อเราไปทุกที่ที่ถูกนำไปใช้')

@section('content')
@if (session('status'))
    <div class="mb-4 rounded-lg border border-success/30 bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</div>
@endif

<div class="mb-5 rounded-xl border border-white/5 bg-white/[0.02] px-4 py-3 text-[13px] leading-relaxed text-cream/50">
    ลายน้ำถูก <b class="text-cream/75">ฝังลงในไฟล์ภาพจริง</b> ไม่ใช่วางทับตอนแสดงผล — ใครบันทึกรูปไปใช้ที่อื่นก็ติดไปด้วย<br>
    <span class="text-cream/40">ลากโลโก้บนภาพตัวอย่างเพื่อเลือกตำแหน่ง · ภาพที่เห็นคือผลจริงที่จะถูกบันทึก · ค่าใหม่มีผลกับปกที่บันทึกหลังจากนี้</span>
</div>

<form method="POST" action="{{ route('admin.watermark.update') }}"
      x-data="watermarkEditor(@js($cfg), @js($samples), @js(route('admin.watermark.preview')), @js(route('admin.watermark.logo')))">
    @csrf @method('PUT')

    <div class="grid gap-6 lg:grid-cols-[minmax(0,420px)_minmax(0,1fr)]">

        {{-- ── ตัวอย่างสด + ลากวาง ───────────────────────────────── --}}
        <div class="nx-card p-5">
            <h3 class="mb-3 text-base font-semibold">ตัวอย่างจริง — ลากเพื่อย้ายตำแหน่ง</h3>

            <div class="relative select-none overflow-hidden rounded-xl ring-1 ring-white/10"
                 style="aspect-ratio:2/3; background:#161020"
                 x-ref="stage"
                 @pointerdown="startDrag($event)" @pointermove="onDrag($event)"
                 @pointerup="endDrag()" @pointerleave="endDrag()">
                <img :src="previewUrl" alt="" class="pointer-events-none h-full w-full object-cover"
                     x-bind:class="loading ? 'opacity-70' : ''">
                {{-- เป้าเล็ง: บอกจุดที่กำลังลาก --}}
                <div class="pointer-events-none absolute h-8 w-8 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-brand/80 bg-brand/20"
                     x-show="dragging" x-cloak
                     :style="`left:${f.x}%; top:${f.y}%`"></div>
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-2">
                <span class="text-[12px] text-cream/45">ลองกับปกอื่น:</span>
                <template x-for="s in samples" :key="s.path">
                    <button type="button" @click="cover = s.path; refresh()"
                            class="rounded-md px-2 py-1 text-[11px]"
                            :class="cover === s.path ? 'bg-brand/20 text-brand' : 'bg-white/5 text-cream/60 hover:bg-white/10'"
                            x-text="s.title.slice(0, 14)"></button>
                </template>
            </div>
            <p class="mt-2 text-[11px] text-cream/40">
                ตำแหน่ง <span x-text="f.x"></span>% × <span x-text="f.y"></span>%
                <span x-show="f.auto_contrast" x-cloak> · ระบบปรับความเข้มตามพื้นหลังให้อัตโนมัติ</span>
            </p>
        </div>

        {{-- ── ตัวควบคุม ───────────────────────────────────────── --}}
        <div class="space-y-4">
            <div class="nx-card p-5">
                <label class="flex cursor-pointer items-start gap-3">
                    <input type="checkbox" name="enabled" value="1" x-model="f.enabled" class="mt-1 h-5 w-5 accent-brand">
                    <span>
                        <span class="font-semibold">เปิดใช้งานลายน้ำ</span>
                        <span class="mt-0.5 block text-[12px] text-cream/45">ปิดอยู่ = ปกที่บันทึกใหม่จะไม่มีลายน้ำ (ปกเดิมไม่เปลี่ยน)</span>
                    </span>
                </label>
            </div>

            <div class="nx-card space-y-4 p-5">
                <div>
                    <div class="mb-2 text-sm text-cream/60">ใช้อะไรเป็นลายน้ำ</div>
                    <div class="flex gap-2">
                        <button type="button" @click="f.mode='logo'; refresh()"
                                class="rounded-lg px-3.5 py-2 text-[13px]"
                                :class="f.mode==='logo' ? 'bg-brand/20 font-semibold text-brand' : 'bg-white/5 text-cream/60 hover:bg-white/10'">รูปโลโก้</button>
                        <button type="button" @click="f.mode='text'; refresh()"
                                class="rounded-lg px-3.5 py-2 text-[13px]"
                                :class="f.mode==='text' ? 'bg-brand/20 font-semibold text-brand' : 'bg-white/5 text-cream/60 hover:bg-white/10'">ข้อความ</button>
                    </div>
                    <input type="hidden" name="mode" :value="f.mode">
                </div>

                <div x-show="f.mode==='logo'" x-cloak>
                    <label class="mb-1.5 block text-sm text-cream/60">ไฟล์โลโก้ (แนะนำ PNG พื้นโปร่งใส)</label>
                    <select name="logo" x-model="f.logo" @change="refresh()" class="nx-input">
                        @foreach ($logos as $logo)
                            <option value="{{ $logo }}">{{ $logo }}</option>
                        @endforeach
                    </select>
                    <div class="mt-2 flex items-center gap-2">
                        <button type="button" @click="$refs.logoFile.click()" x-bind:disabled="uploading"
                                class="rounded-lg bg-white/10 px-3 py-1.5 text-xs hover:bg-white/15 disabled:opacity-50"
                                x-text="uploading ? 'กำลังอัปโหลด…' : '⬆ อัปโหลดโลโก้ใหม่'"></button>
                        <input x-ref="logoFile" type="file" accept="image/png,image/*" class="hidden" @change="uploadLogo($event)">
                        <span class="text-[11px] text-cream/40" x-text="uploadMsg"></span>
                    </div>
                </div>

                <div x-show="f.mode==='text'" x-cloak>
                    <label class="mb-1.5 block text-sm text-cream/60">ข้อความ</label>
                    <input name="text" x-model="f.text" @input.debounce.400ms="refresh()" maxlength="60" class="nx-input">
                </div>

                <div>
                    <label class="mb-1.5 flex items-center justify-between text-sm text-cream/60">
                        <span>ขนาด (เทียบความกว้างปก)</span><span class="text-cream/80" x-text="f.width + '%'"></span>
                    </label>
                    <input type="range" name="width" min="5" max="100" x-model.number="f.width"
                           @input.debounce.250ms="refresh()" class="w-full accent-brand">
                </div>

                <div>
                    <label class="mb-1.5 flex items-center justify-between text-sm text-cream/60">
                        <span>ความเข้ม</span><span class="text-cream/80" x-text="f.opacity + '%'"></span>
                    </label>
                    <input type="range" name="opacity" min="3" max="100" x-model.number="f.opacity"
                           @input.debounce.250ms="refresh()" class="w-full accent-brand">
                </div>

                <label class="flex cursor-pointer items-start gap-3 border-t border-white/5 pt-4">
                    <input type="checkbox" name="auto_contrast" value="1" x-model="f.auto_contrast"
                           @change="refresh()" class="mt-1 h-5 w-5 accent-brand">
                    <span>
                        <span class="text-sm">ปรับความเข้มอัตโนมัติตามพื้นหลัง</span>
                        <span class="mt-0.5 block text-[12px] text-cream/45">ปกสีเข้มจัดหรือสว่างจัดจะกลืนลายน้ำ — เปิดไว้จะเห็นเท่ากันทุกใบ</span>
                    </span>
                </label>

                <input type="hidden" name="x" :value="f.x">
                <input type="hidden" name="y" :value="f.y">
            </div>

            <div class="nx-card flex flex-wrap items-center gap-3 p-5">
                <button class="btn-brand px-5 py-2.5 text-sm">บันทึกการตั้งค่า</button>
                <span class="text-[12px] text-cream/45">
                    ปกที่ฝังลายน้ำแล้ว {{ number_format($markedCount) }} ใบ
                </span>
            </div>
        </div>
    </div>
</form>

@push('scripts')
<script>
    function watermarkEditor(cfg, samples, previewRoute, logoRoute) {
        return {
            f: Object.assign({}, cfg),
            samples,
            cover: samples.length ? samples[0].path : '',
            previewUrl: '',
            loading: false,
            dragging: false,
            uploading: false,
            uploadMsg: '',
            _t: null,

            init() { this.refresh(); },

            /** Rebuild the preview URL. Debounced by the callers that fire rapidly (sliders, typing). */
            refresh() {
                const q = new URLSearchParams({
                    cover: this.cover, mode: this.f.mode, text: this.f.text ?? '',
                    logo: this.f.logo ?? '', x: this.f.x, y: this.f.y,
                    width: this.f.width, opacity: this.f.opacity,
                    auto_contrast: this.f.auto_contrast ? 1 : 0,
                    _: Date.now(),
                });
                this.loading = true;
                const url = previewRoute + '?' + q.toString();
                const img = new Image();
                img.onload = () => { this.previewUrl = url; this.loading = false; };
                img.onerror = () => { this.loading = false; };
                img.src = url;
            },

            // ---- ลากวาง: จุดที่ปล่อยคือ "กึ่งกลาง" ของลายน้ำ ซึ่งตรงกับที่คนคิดเวลาบอกว่า "เอาตรงนี้"
            startDrag(e) { this.dragging = true; this.moveTo(e); },
            onDrag(e) { if (this.dragging) this.moveTo(e); },
            endDrag() { if (!this.dragging) return; this.dragging = false; this.refresh(); },

            moveTo(e) {
                const r = this.$refs.stage.getBoundingClientRect();
                if (!r.width || !r.height) return;
                this.f.x = Math.round(Math.min(100, Math.max(0, ((e.clientX - r.left) / r.width) * 100)));
                this.f.y = Math.round(Math.min(100, Math.max(0, ((e.clientY - r.top) / r.height) * 100)));
                // อัปเดตภาพระหว่างลากแบบหน่วง เพื่อไม่ให้ยิงคำขอทุกพิกเซล
                clearTimeout(this._t);
                this._t = setTimeout(() => this.refresh(), 180);
            },

            uploadLogo(e) {
                const file = e.target.files && e.target.files[0];
                e.target.value = '';
                if (!file) return;
                if (file.size > 5_000_000) { this.uploadMsg = 'ไฟล์ใหญ่เกิน 5MB'; return; }
                this.uploading = true; this.uploadMsg = '';
                const r = new FileReader();
                r.onload = async () => {
                    try {
                        const res = await window.nxPostSoft(logoRoute, { image: r.result });
                        if (res && res.ok) {
                            // เพิ่มเข้า dropdown ทันทีโดยไม่ต้องรีเฟรชหน้า
                            const sel = document.querySelector('select[name="logo"]');
                            if (sel && !Array.from(sel.options).some(o => o.value === res.logo)) {
                                sel.add(new Option(res.logo, res.logo));
                            }
                            this.f.logo = res.logo; this.f.mode = 'logo';
                            this.uploadMsg = 'อัปโหลดแล้ว'; this.refresh();
                        } else { this.uploadMsg = (res && res.error) || 'อัปโหลดไม่สำเร็จ'; }
                    } catch (err) { this.uploadMsg = 'อัปโหลดผิดพลาด'; }
                    finally { this.uploading = false; }
                };
                r.onerror = () => { this.uploading = false; this.uploadMsg = 'อ่านไฟล์ไม่ได้'; };
                r.readAsDataURL(file);
            },
        };
    }
</script>
@endpush
@endsection
