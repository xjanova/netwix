@extends('layouts.admin')
@section('page-title', $content->exists ? 'แก้ไขคอนเทนต์' : 'เพิ่มคอนเทนต์')
@section('page-subtitle', $content->exists ? $content->title : 'สร้างเรื่องใหม่ในคลัง NetWix')
@section('action')<a href="{{ route('admin.contents.index') }}" class="rounded-lg bg-white/5 px-4 py-2.5 text-sm hover:bg-white/10">← กลับ</a>@endsection

@php
    $primaryId = $content->exists ? optional($content->primaryGenre())->id : null;
    $val = fn ($f, $d = '') => old($f, $content->$f ?? $d);

    // A same-origin playable source for the first episode, so the admin can preview + grab a poster.
    $firstEp = $content->exists ? $content->episodes->first() : null;
    $previewSrc = null;
    if ($firstEp) {
        $previewSrc = $firstEp->video_url
            ?: ($firstEp->source
                ? (in_array($firstEp->source, ['wowdrama', 'anime108'], true) ? route('stream.manifest', $firstEp) : route('stream.mp4', $firstEp))
                : null);
    } elseif ($content->exists && $content->video_url) {
        $previewSrc = $content->video_url;
    }
@endphp

@section('content')
<form method="POST" action="{{ $content->exists ? route('admin.contents.update', $content) : route('admin.contents.store') }}" class="grid gap-6 lg:grid-cols-[2fr_1fr]">
    @csrf
    @if ($content->exists) @method('PUT') @endif

    {{-- Left column --}}
    <div class="flex flex-col gap-5">
        <div class="nx-card p-5">
            <label class="mb-1.5 block text-sm text-cream/60">ชื่อเรื่อง *</label>
            <input name="title" value="{{ $val('title') }}" class="nx-input mb-4" required>

            <div class="mb-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-sm text-cream/60">Slug (เว้นว่างให้สร้างอัตโนมัติ)</label>
                    <input name="slug" value="{{ $val('slug') }}" class="nx-input">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm text-cream/60">ประเภท *</label>
                    <select name="type" class="nx-input">
                        @foreach (['series' => 'ซีรี่ส์', 'movie' => 'ภาพยนตร์', 'vertical' => 'ซีรีส์แนวตั้ง'] as $k => $lbl)
                            <option value="{{ $k }}" @selected($val('type') === $k)>{{ $lbl }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <label class="mb-1.5 block text-sm text-cream/60">เรื่องย่อ</label>
            <textarea name="synopsis" rows="4" class="nx-input">{{ $val('synopsis') }}</textarea>
        </div>

        <div class="nx-card p-5">
            <h3 class="mb-4 text-base font-semibold">สื่อและวิดีโอ</h3>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-sm text-cream/60">YouTube Trailer ID</label>
                    <input name="trailer_youtube_id" value="{{ $val('trailer_youtube_id') }}" placeholder="เช่น aqz-KE-bpKQ" class="nx-input">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm text-cream/60">วิดีโอหลัก (mp4 / m3u8 / YouTube) — หนัง & แนวตั้ง</label>
                    <input name="video_url" value="{{ $val('video_url') }}" class="nx-input">
                </div>
                <div x-data="posterField('poster', @js($content->exists ? route('admin.storage.set-poster', $content) : null), @js($val('poster_path')))">
                    <label class="mb-1.5 block text-sm text-cream/60">โปสเตอร์ (URL 2:3)</label>
                    <input name="poster_path" x-model="v" x-on:input="ok = true" class="nx-input">
                    <div class="mt-2 flex items-start gap-3">
                        <img x-show="v && ok" x-cloak :src="v" x-on:error="ok = false" referrerpolicy="no-referrer" alt=""
                             class="h-40 w-auto rounded-lg object-cover ring-1 ring-white/10">
                        @if ($content->exists)
                            <div class="flex flex-col gap-1">
                                <button type="button" @click="$refs.f.click()" x-bind:disabled="saving"
                                        class="rounded-lg bg-white/10 px-3 py-1.5 text-xs hover:bg-white/15 disabled:opacity-50" x-text="saving ? 'กำลังอัปโหลด…' : '⬆ อัปโหลดรูป'"></button>
                                <input x-ref="f" type="file" accept="image/*" class="hidden" @change="up($event)">
                                <span class="text-xs leading-tight" :class="up_ok ? 'text-success' : 'text-[#ff6b81]'" x-text="msg"></span>
                            </div>
                        @endif
                    </div>
                </div>
                <div x-data="posterField('backdrop', @js($content->exists ? route('admin.storage.set-poster', $content) : null), @js($val('backdrop_path')))">
                    <label class="mb-1.5 block text-sm text-cream/60">ภาพพื้นหลัง (URL 16:9)</label>
                    <input name="backdrop_path" x-model="v" x-on:input="ok = true" class="nx-input">
                    <div class="mt-2 flex items-start gap-3">
                        <img x-show="v && ok" x-cloak :src="v" x-on:error="ok = false" referrerpolicy="no-referrer" alt=""
                             class="h-28 w-auto rounded-lg object-cover ring-1 ring-white/10">
                        @if ($content->exists)
                            <div class="flex flex-col gap-1">
                                <button type="button" @click="$refs.f.click()" x-bind:disabled="saving"
                                        class="rounded-lg bg-white/10 px-3 py-1.5 text-xs hover:bg-white/15 disabled:opacity-50" x-text="saving ? 'กำลังอัปโหลด…' : '⬆ อัปโหลดรูป'"></button>
                                <input x-ref="f" type="file" accept="image/*" class="hidden" @change="up($event)">
                                <span class="text-xs leading-tight" :class="up_ok ? 'text-success' : 'text-[#ff6b81]'" x-text="msg"></span>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            @if ($previewSrc)
                <div class="mt-4 border-t border-white/5 pt-4" x-data="posterPicker({ src: @js($previewSrc), url: @js(route('admin.storage.set-poster', $content)) })">
                    <button type="button" @click="show()"
                            class="rounded-lg bg-brand/15 px-4 py-2 text-sm font-semibold text-brand hover:bg-brand/25">🎬 เล่นวิดีโอ / จับปกจากเฟรม</button>
                    <span class="ml-2 text-xs text-cream/40">เล่นดูในหลังบ้าน แล้วเลือกเฟรมเป็นปกหรือภาพพื้นหลัง</span>

                    <div x-show="open" x-cloak @keydown.escape.window="hide()" @click.self="hide()"
                         class="fixed inset-0 z-[60] flex items-center justify-center bg-black/80 p-4">
                        <div class="nx-card w-full max-w-3xl p-5" @click.stop>
                            <div class="mb-3 flex items-center justify-between">
                                <h3 class="text-base font-semibold">เล่นวิดีโอ · เลือกปกจากเฟรม</h3>
                                <button type="button" @click="hide()" class="flex h-9 w-9 items-center justify-center rounded-full bg-white/10 hover:bg-white/20">✕</button>
                            </div>
                            <video x-ref="pv" controls playsinline class="mb-3 max-h-[56vh] w-full rounded-lg bg-black"></video>
                            <div class="flex flex-wrap items-center gap-3">
                                <button type="button" @click="grab('poster')" x-bind:disabled="saving"
                                        class="btn-brand px-4 py-2 text-sm disabled:opacity-50">📸 ตั้งเป็นปก (2:3)</button>
                                <button type="button" @click="grab('backdrop')" x-bind:disabled="saving"
                                        class="rounded-lg bg-white/10 px-4 py-2 text-sm font-semibold hover:bg-white/15 disabled:opacity-50">🖼 ตั้งเป็นภาพพื้นหลัง (16:9)</button>
                                <span class="text-sm" :class="ok ? 'text-success' : 'text-[#ff6b81]'" x-text="msg"></span>
                                <span class="ml-auto text-xs text-cream/40">เลื่อนแถบวิดีโอไปเฟรมที่ต้องการ แล้วกด</span>
                            </div>
                        </div>
                    </div>
                </div>

                @push('scripts')
                <script>
                    function posterPicker(cfg) {
                        return {
                            open: false, saving: false, ok: false, msg: '',
                            show() {
                                this.open = true; this.msg = '';
                                this.$nextTick(() => {
                                    const v = this.$refs.pv;
                                    window.nxAttachVideo ? window.nxAttachVideo(v, cfg.src) : (v.src = cfg.src);
                                    v.play?.().catch(() => {});
                                });
                            },
                            hide() {
                                const v = this.$refs.pv;
                                if (v) { try { v.pause(); v.removeAttribute('src'); v.load(); } catch (e) {} }
                                this.open = false;
                            },
                            async grab(kind) {
                                const v = this.$refs.pv;
                                if (!v || !v.videoWidth) { this.ok = false; this.msg = 'วิดีโอยังไม่พร้อม รอสักครู่'; return; }
                                this.saving = true; this.msg = '';
                                try {
                                    const w = 640, h = Math.round(w * v.videoHeight / v.videoWidth) || 360;
                                    const cv = document.createElement('canvas'); cv.width = w; cv.height = h;
                                    cv.getContext('2d').drawImage(v, 0, 0, w, h);
                                    const img = cv.toDataURL('image/jpeg', 0.82);
                                    const r = await window.nxPost(cfg.url, { image: img, kind });
                                    if (r && r.ok) {
                                        this.ok = true;
                                        this.msg = (kind === 'poster' ? 'ตั้งเป็นปกแล้ว' : 'ตั้งเป็นภาพพื้นหลังแล้ว') + ' ✓';
                                        const input = document.querySelector(kind === 'poster' ? '[name="poster_path"]' : '[name="backdrop_path"]');
                                        if (input) { input.value = r.url; input.dispatchEvent(new Event('input', { bubbles: true })); }
                                    } else { this.ok = false; this.msg = 'บันทึกไม่สำเร็จ'; }
                                } catch (e) { this.ok = false; this.msg = 'จับเฟรมไม่ได้'; }
                                finally { this.saving = false; }
                            },
                        };
                    }
                </script>
                @endpush
            @endif

            @push('scripts')
            <script>
                function posterField(kind, url, initial) {
                    return {
                        v: initial || '', ok: true, saving: false, up_ok: false, msg: '', url,
                        up(e) {
                            const f = e.target.files && e.target.files[0];
                            e.target.value = '';
                            if (!f || !this.url) return;
                            if (!f.type.startsWith('image/')) { this.up_ok = false; this.msg = 'ไฟล์ไม่ใช่รูปภาพ'; return; }
                            if (f.size > 8_000_000) { this.up_ok = false; this.msg = 'ไฟล์ใหญ่เกินไป (สูงสุด 8MB)'; return; }
                            this.saving = true; this.msg = '';
                            const r = new FileReader();
                            r.onload = async () => {
                                try {
                                    const res = await window.nxPost(this.url, { image: r.result, kind });
                                    if (res && res.ok) { this.v = res.url; this.ok = true; this.up_ok = true; this.msg = 'อัปโหลดแล้ว ✓'; }
                                    else { this.up_ok = false; this.msg = 'อัปโหลดไม่สำเร็จ (รูปไม่ถูกต้อง?)'; }
                                } catch (err) { this.up_ok = false; this.msg = 'อัปโหลดผิดพลาด'; }
                                finally { this.saving = false; }
                            };
                            r.onerror = () => { this.saving = false; this.up_ok = false; this.msg = 'อ่านไฟล์ไม่ได้'; };
                            r.readAsDataURL(f);
                        },
                    };
                }
            </script>
            @endpush
        </div>

    </div>

    {{-- Right column --}}
    <div class="flex flex-col gap-5">
        <div class="nx-card p-5">
            <button type="submit" class="btn-brand w-full py-3">{{ $content->exists ? 'บันทึกการเปลี่ยนแปลง' : 'สร้างคอนเทนต์' }}</button>
        </div>

        <div class="nx-card p-5">
            <h3 class="mb-3 text-base font-semibold">การตั้งค่า</h3>
            <div class="grid grid-cols-2 gap-4">
                <div><label class="mb-1.5 block text-xs text-cream/60">ปี</label><input name="year" type="number" value="{{ $val('year', 2025) }}" class="nx-input"></div>
                <div>
                    <label class="mb-1.5 block text-xs text-cream/60">เรตอายุ</label>
                    @php $curMat = $val('maturity', '13+'); $matOpts = \App\Support\Maturity::OPTIONS; @endphp
                    <select name="maturity" class="nx-input">
                        @unless (in_array($curMat, $matOpts, true))<option value="{{ $curMat }}" selected>{{ $curMat }}</option>@endunless
                        @foreach ($matOpts as $mat)
                            <option value="{{ $mat }}" @selected($curMat === $mat)>{{ $mat }} @if (in_array($mat, \App\Support\Maturity::ADULT, true))· ผู้ใหญ่ + Pro @endif</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-[11px] leading-tight text-cream/40">18+/20+ = เฉพาะโปรไฟล์ผู้ใหญ่ และต้องเป็นสมาชิก Pro (โปรไฟล์เด็กจะไม่เห็น)</p>
                </div>
                <div><label class="mb-1.5 block text-xs text-cream/60">% ตรงใจ</label><input name="match_score" type="number" min="0" max="100" value="{{ $val('match_score', 96) }}" class="nx-input"></div>
                <div><label class="mb-1.5 block text-xs text-cream/60">คะแนน (0-10)</label><input name="rating" type="number" step="0.1" min="0" max="10" value="{{ $val('rating', 8.5) }}" class="nx-input"></div>
                <div class="col-span-2"><label class="mb-1.5 block text-xs text-cream/60">ความยาว (นาที) — เฉพาะหนัง</label><input name="duration_minutes" type="number" value="{{ $val('duration_minutes') }}" class="nx-input"></div>
                <div class="col-span-2 rounded-lg border border-white/10 bg-white/[0.02] p-3">
                    <div class="mb-2 text-xs font-semibold text-cream/70">⏱ มาร์คเวลา (ข้ามอินโทร / เครดิต) — ใช้กับทุกตอน</div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-[11px] text-cream/55">ข้ามอินโทรถึงวินาทีที่</label>
                            <input name="intro_end_seconds" type="number" min="0" max="36000" value="{{ $val('intro_end_seconds') }}" placeholder="เช่น 90 = 1:30" class="nx-input">
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] text-cream/55">ความยาวเครดิตท้ายเรื่อง (วินาที)</label>
                            <input name="outro_seconds" type="number" min="0" max="36000" value="{{ $val('outro_seconds') }}" placeholder="เช่น 60 = 1:00" class="nx-input">
                        </div>
                    </div>
                    <p class="mt-1.5 text-[11px] leading-tight text-cream/40">เว้นว่าง = ปิดใช้งาน · ปุ่ม “ข้ามอินโทร” จะโผล่ช่วงต้นเรื่อง และเมื่อถึงเครดิตจะเด้ง “เล่นตอนต่อไป” อัตโนมัติ (ตอนสุดท้าย/หนัง = เด้งการ์ดให้คะแนน)</p>
                </div>
            </div>
            <div class="mt-4 flex flex-col gap-2.5 text-sm">
                <label class="flex items-center gap-2.5"><input type="checkbox" name="is_original" value="1" class="accent-brand" @checked($val('is_original'))> NetWix Original</label>
                <label class="flex items-center gap-2.5"><input type="checkbox" name="is_featured" value="1" class="accent-brand" @checked($val('is_featured'))> แสดงเป็น Hero (แนะนำ)</label>
                <label class="flex items-center gap-2.5"><input type="checkbox" name="is_published" value="1" class="accent-brand" @checked($val('is_published', true))> เผยแพร่</label>
                <label class="flex items-center gap-2.5"><input type="checkbox" name="is_vip" value="1" class="accent-brand" @checked($val('is_vip'))> ⭐ โซน VIP (ปลดล็อกด้วยเหรียญทอง)</label>
            </div>
            <div class="mt-3">
                <label class="mb-1.5 block text-xs text-cream/60">ราคาปลดล็อก VIP (เหรียญทอง)</label>
                <input name="vip_price_gold" type="number" min="0" max="1000000" value="{{ $val('vip_price_gold') }}" class="nx-input" placeholder="เว้นว่าง = ใช้ราคากลางจากหน้าโปรโมชัน">
                <p class="mt-1 text-[11px] leading-tight text-cream/40">ใช้เมื่อติ๊ก “โซน VIP” เท่านั้น — สมาชิก Pro ดูโซน VIP ได้ฟรี (ตั้งค่าที่หน้าโปรโมชัน)</p>
            </div>
        </div>

        <div class="nx-card p-5">
            <h3 class="mb-3 text-base font-semibold">หมวดหมู่</h3>
            <div class="flex flex-col gap-2 text-sm">
                @foreach ($genres as $g)
                    <label class="flex items-center justify-between gap-2 rounded-lg px-2 py-1.5 hover:bg-white/5">
                        <span class="flex items-center gap-2.5">
                            <input type="checkbox" name="genres[]" value="{{ $g->id }}" class="accent-brand" @checked(in_array($g->id, old('genres', $selectedGenres)))>
                            {{ $g->name }}
                        </span>
                        <label class="flex items-center gap-1 text-xs text-cream/45">
                            <input type="radio" name="primary_genre" value="{{ $g->id }}" class="accent-brand" @checked(old('primary_genre', $primaryId) == $g->id)> หลัก
                        </label>
                    </label>
                @endforeach
                @if ($genres->isEmpty())
                    <a href="{{ route('admin.genres.index') }}" class="text-brand">+ เพิ่มหมวดก่อน</a>
                @endif
            </div>
        </div>
    </div>
</form>

@if ($content->exists && $content->type !== 'movie')
    <div class="mt-6">
        @include('admin.contents.partials.episodes')
    </div>
@endif
@endsection
