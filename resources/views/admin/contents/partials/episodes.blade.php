<div class="nx-card p-5" x-data="thumbPicker()">
    @php $canMirror = in_array($content->source, ['rongyok'], true); @endphp
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h3 class="text-base font-semibold">ตอน ({{ $content->episodes->count() }})</h3>
        <div class="flex flex-wrap items-center gap-2">
            @if ($canMirror)
                <form method="POST" action="{{ route('admin.storage.mirror-content', $content) }}"
                      onsubmit="return confirm('ดาวน์โหลดทุกตอนที่ยังไม่เก็บ มาไว้ที่เซิร์ฟเวอร์? อาจใช้เวลาสักครู่')">
                    @csrf
                    <button class="rounded-lg bg-white/5 px-3 py-1.5 text-xs hover:bg-white/10" title="ดาวน์โหลดทุกตอนมาเก็บ เพื่อเล่นจากไฟล์ในเซิร์ฟเวอร์ (ไม่ต้องขอลิงก์สด)">⬇ โหลดทั้งเรื่อง</button>
                </form>
            @endif
            <form method="POST" action="{{ route('admin.contents.reset-thumbs', $content) }}"
                  onsubmit="return confirm('รีเซ็ตปกของทุกตอนในเรื่องนี้? ระบบจะจับภาพใหม่จากวิดีโอเมื่อมีคนดูตอนนั้น')">
                @csrf
                <button class="rounded-lg bg-white/5 px-3 py-1.5 text-xs hover:bg-white/10" title="ลบปกตอนที่จับไว้ แล้วให้จับใหม่เมื่อมีคนดู">↺ รีเซ็ตปกตอน</button>
            </form>
        </div>
    </div>

    @if ($content->episodes->isNotEmpty())
        <div class="mb-5 flex flex-col gap-1.5">
            @foreach ($content->episodes as $ep)
                <div class="flex items-center gap-3 rounded-lg bg-white/[0.03] px-3 py-2.5">
                    <span class="w-16 text-xs text-cream/45">
                        @if ($content->type === 'series' && $ep->season_id)S{{ $ep->season?->number }} @endif EP{{ $ep->number }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm">{{ $ep->title }}</div>
                        <div class="truncate text-xs text-cream/40">{{ $ep->video_url ?: 'ยังไม่มีวิดีโอ' }}</div>
                    </div>
                    @if ($ep->is_mirrored)
                        <span class="rounded-full bg-success/15 px-2 py-0.5 text-[11px] text-success" title="เก็บไฟล์ในเซิร์ฟเวอร์แล้ว">● มิเรอร์แล้ว</span>
                        @if ($ep->file_size)<span class="text-[11px] text-cream/45">{{ number_format($ep->file_size / 1e6, 1) }} MB</span>@endif
                    @elseif ($ep->source)
                        <span class="rounded-full bg-white/10 px-2 py-0.5 text-[11px] text-cream/50" title="ยังไม่ได้ดาวน์โหลดมาเก็บ">○ ต้นทาง</span>
                    @endif
                    <span class="text-xs text-cream/45">{{ $ep->duration_label }}</span>
                    @php
                        $thumbSrc = $ep->video_url
                            ?: ($ep->source
                                ? (in_array($ep->source, ['wowdrama', 'anime108'], true) ? route('stream.manifest', $ep) : route('stream.mp4', $ep))
                                : null);
                    @endphp
                    <button type="button"
                            @click="open({ num: {{ $ep->number }}, src: @js($thumbSrc), hls: @js($thumbSrc && (str_ends_with($thumbSrc, '.m3u8') || str_contains($thumbSrc, '/index.m3u8'))), post: @js(route('admin.storage.set-thumb', $ep)) })"
                            class="rounded-md bg-white/5 px-2.5 py-1 text-xs hover:bg-white/10" title="เลือกปกจากเฟรมในวิดีโอ หรืออัปโหลดรูปเอง">🖼 ปก</button>
                    @if ($ep->is_mirrored)
                        <form method="POST" action="{{ route('admin.storage.unmirror', $ep) }}" onsubmit="return confirm('ลบไฟล์ตอนนี้ออกจากเซิร์ฟเวอร์? (กลับไปสตรีมสด)')">
                            @csrf @method('DELETE')
                            <button class="rounded-md bg-white/5 px-2.5 py-1 text-xs hover:bg-white/10" title="ลบไฟล์ที่เก็บไว้ในเซิร์ฟเวอร์">ลบไฟล์</button>
                        </form>
                    @elseif ($ep->source && $canMirror)
                        <form method="POST" action="{{ route('admin.storage.mirror', $ep) }}">
                            @csrf
                            <button class="rounded-md bg-brand/15 px-2.5 py-1 text-xs text-brand hover:bg-brand/25" title="ดาวน์โหลดตอนนี้มาเก็บที่เซิร์ฟเวอร์">⬇ โหลดเก็บ</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('admin.contents.episodes.destroy', [$content, $ep]) }}" onsubmit="return confirm('ลบตอนนี้?')">
                        @csrf @method('DELETE')
                        <button class="rounded-md bg-[#e5484d]/15 px-2.5 py-1 text-xs text-[#ff6b81] hover:bg-[#e5484d]/25">ลบ</button>
                    </form>
                </div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('admin.contents.episodes.store', $content) }}" class="grid gap-3 rounded-lg border border-white/5 p-4 sm:grid-cols-2">
        @csrf
        <h4 class="col-span-full text-sm font-semibold text-cream/70">เพิ่มตอนใหม่</h4>
        @if ($content->type === 'series')
            <div><label class="mb-1 block text-xs text-cream/60">ซีซั่น</label><input name="season_number" type="number" min="1" value="1" class="nx-input"></div>
        @endif
        <div><label class="mb-1 block text-xs text-cream/60">ตอนที่ *</label><input name="number" type="number" min="1" value="{{ $content->episodes->max('number') + 1 }}" class="nx-input" required></div>
        <div class="sm:col-span-2"><label class="mb-1 block text-xs text-cream/60">ชื่อตอน *</label><input name="title" class="nx-input" required></div>
        <div class="sm:col-span-2"><label class="mb-1 block text-xs text-cream/60">รายละเอียด</label><input name="description" class="nx-input"></div>
        <div><label class="mb-1 block text-xs text-cream/60">ความยาว (นาที)</label><input name="duration_minutes" type="number" class="nx-input"></div>
        <div><label class="mb-1 block text-xs text-cream/60">วิดีโอ (mp4 / m3u8 / YouTube)</label><input name="video_url" class="nx-input"></div>
        <div class="col-span-full"><button class="rounded-lg bg-white/10 px-5 py-2.5 text-sm font-semibold hover:bg-white/15">+ เพิ่มตอน</button></div>
    </form>

    {{-- Thumbnail picker: play the episode, scrub to a frame, use it as the cover --}}
    <div x-show="ep" x-cloak @keydown.escape.window="close()" @click.self="close()"
         class="fixed inset-0 z-[60] flex items-center justify-center bg-black/80 p-4">
        <div class="nx-card w-full max-w-2xl p-5" @click.stop>
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-base font-semibold">เลือกปกจากวิดีโอ · ตอนที่ <span x-text="ep?.num"></span></h3>
                <button type="button" @click="close()" class="flex h-9 w-9 items-center justify-center rounded-full bg-white/10 hover:bg-white/20">✕</button>
            </div>
            <video x-ref="vid" x-show="ep?.src" controls playsinline class="mb-3 max-h-[58vh] w-full rounded-lg bg-black"></video>
            <div x-show="!ep?.src" class="mb-3 rounded-lg border border-dashed border-white/10 bg-white/[0.02] py-10 text-center text-sm text-cream/40">ตอนนี้ยังไม่มีวิดีโอ — อัปโหลดรูปปกเองได้เลย</div>
            <div class="flex flex-wrap items-center gap-3">
                <button type="button" x-show="ep?.src" @click="capture()" x-bind:disabled="saving"
                        class="btn-brand px-5 py-2.5 text-sm disabled:opacity-50" x-text="saving ? 'กำลังบันทึก…' : '📸 ใช้เฟรมนี้เป็นปก'"></button>
                <button type="button" @click="$refs.file.click()" x-bind:disabled="saving"
                        class="rounded-lg bg-white/10 px-4 py-2.5 text-sm hover:bg-white/15 disabled:opacity-50" title="อัปโหลดรูปปกจากเครื่อง (jpg/png/webp/gif…) ระบบจะแปลงเป็น WebP ให้">⬆ อัปโหลดรูป</button>
                <input x-ref="file" type="file" accept="image/*" class="hidden" @change="upload($event)">
                <span class="text-sm" :class="ok ? 'text-success' : 'text-[#ff6b81]'" x-text="msg"></span>
                <span class="ml-auto text-xs text-cream/40">จับเฟรมจากวิดีโอ หรืออัปโหลดรูปเอง</span>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        function thumbPicker() {
            return {
                ep: null, saving: false, ok: false, msg: '',
                open(ep) {
                    this.ep = ep; this.msg = ''; this.ok = false;
                    if (!ep.src) return;
                    this.$nextTick(() => {
                        const v = this.$refs.vid;
                        window.nxAttachVideo ? window.nxAttachVideo(v, ep.src) : (v.src = ep.src);
                        v.play?.().catch(() => {});
                    });
                },
                close() {
                    const v = this.$refs.vid;
                    if (v) { try { v.pause(); v.removeAttribute('src'); v.load(); } catch (e) {} }
                    this.ep = null;
                },
                async capture() {
                    const v = this.$refs.vid;
                    if (!v || !v.videoWidth) { this.ok = false; this.msg = 'วิดีโอยังไม่พร้อม รอสักครู่'; return; }
                    this.saving = true; this.msg = '';
                    try {
                        const w = 480, h = Math.round(w * v.videoHeight / v.videoWidth) || 270;
                        const cv = document.createElement('canvas'); cv.width = w; cv.height = h;
                        cv.getContext('2d').drawImage(v, 0, 0, w, h);
                        const img = cv.toDataURL('image/jpeg', 0.72);
                        const r = await window.nxPost(this.ep.post, { image: img });
                        if (r && r.ok) { this.ok = true; this.msg = 'บันทึกปกใหม่แล้ว ✓ กำลังรีเฟรช…'; setTimeout(() => location.reload(), 700); }
                        else { this.ok = false; this.msg = 'บันทึกไม่สำเร็จ'; }
                    } catch (e) {
                        this.ok = false; this.msg = 'จับเฟรมไม่ได้ (วิดีโออาจข้ามโดเมนโดยไม่มี CORS)';
                    } finally { this.saving = false; }
                },
                async upload(e) {
                    const f = e.target.files && e.target.files[0];
                    e.target.value = '';
                    if (!f) return;
                    if (!f.type.startsWith('image/')) { this.ok = false; this.msg = 'ไฟล์ไม่ใช่รูปภาพ'; return; }
                    if (f.size > 7_000_000) { this.ok = false; this.msg = 'ไฟล์ใหญ่เกินไป (สูงสุด 7MB)'; return; }
                    this.saving = true; this.msg = '';
                    try {
                        const img = await new Promise((res, rej) => {
                            const r = new FileReader();
                            r.onload = () => res(r.result); r.onerror = rej;
                            r.readAsDataURL(f);
                        });
                        const r = await window.nxPost(this.ep.post, { image: img });
                        if (r && r.ok) { this.ok = true; this.msg = 'อัปโหลดปกใหม่แล้ว ✓ กำลังรีเฟรช…'; setTimeout(() => location.reload(), 700); }
                        else { this.ok = false; this.msg = 'อัปโหลดไม่สำเร็จ (รูปไม่ถูกต้อง?)'; }
                    } catch (err) {
                        this.ok = false; this.msg = 'อ่านไฟล์ไม่ได้';
                    } finally { this.saving = false; }
                },
            };
        }
    </script>
    @endpush
</div>
