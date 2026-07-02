<div class="nx-card p-5">
    <h3 class="mb-4 text-base font-semibold">ตอน ({{ $content->episodes->count() }})</h3>

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
                    @elseif ($ep->source)
                        <span class="rounded-full bg-white/10 px-2 py-0.5 text-[11px] text-cream/50" title="ยังไม่ได้ดาวน์โหลดมาเก็บ">○ ต้นทาง</span>
                    @endif
                    <span class="text-xs text-cream/45">{{ $ep->duration_label }}</span>
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
</div>
