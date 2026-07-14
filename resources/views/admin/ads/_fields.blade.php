@php
    $a = $a ?? null;
    $val = fn ($f, $d = '') => old($f, $a?->$f ?? $d);
@endphp
<div class="grid gap-3 sm:grid-cols-2"
     x-data="{ mt: '{{ $val('media_type', 'image') }}', tg: '{{ $val('target', 'all') }}', skippable: {{ $a ? ($a->skippable ? 'true' : 'false') : 'true' }} }">

    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs text-cream/60">ชื่อโฆษณา / แคมเปญ *</label>
        <input name="name" value="{{ $val('name') }}" class="nx-input" placeholder="เช่น โปรสมัคร Pro กรกฎาคม" required>
    </div>

    {{-- creative --}}
    <div>
        <label class="mb-1 block text-xs text-cream/60">ชนิดสื่อ</label>
        <select name="media_type" x-model="mt" class="nx-input">
            <option value="image">รูปภาพนิ่ง</option>
            <option value="video">วิดีโอ (ไฟล์ / ลิงก์ / YouTube)</option>
        </select>
    </div>
    <div x-show="mt === 'image'" x-cloak>
        <label class="mb-1 block text-xs text-cream/60">โชว์ภาพนิ่งกี่วินาที</label>
        <input name="image_seconds" type="number" min="3" max="120" value="{{ $val('image_seconds', 8) }}" class="nx-input">
    </div>

    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs text-cream/60"
               x-text="mt === 'image' ? 'อัพโหลดรูป (jpg/png/webp/gif · ไม่เกิน 8MB)' : 'อัพโหลดวิดีโอ (mp4/webm/mov · ไม่เกิน 50MB)'"></label>
        <input type="file" name="media_file" :accept="mt === 'image' ? 'image/*' : 'video/*'"
               class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-white/10 file:px-3 file:py-1.5 file:text-sm file:text-cream">
        @if ($a && $a->hasCreative())
            <div class="mt-2 flex items-center gap-3 rounded-lg border border-white/10 bg-black/30 p-2">
                @if ($a->media_type === 'image' && $a->media_src)
                    <img src="{{ $a->media_src }}" alt="" class="h-14 w-24 rounded object-cover">
                    <span class="text-[11px] text-cream/50">รูปปัจจุบัน</span>
                @elseif ($a->youtubeId())
                    <span class="text-[11px] text-cream/60">🎬 YouTube: {{ $a->youtubeId() }}</span>
                @else
                    <span class="break-all text-[11px] text-cream/60">🎬 {{ $a->media_src }}</span>
                @endif
            </div>
        @endif
    </div>
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs text-cream/60">หรือใส่ลิงก์ (URL รูป / วิดีโอ mp4·m3u8 / YouTube)</label>
        <input name="media_url" value="{{ $val('media_url') }}" class="nx-input" placeholder="https://…">
        <p class="mt-1 text-[11px] text-cream/40">ถ้าอัพโหลดไฟล์ไว้ ระบบจะใช้ไฟล์ที่อัพโหลดก่อนลิงก์</p>
    </div>

    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs text-cream/60">แคปชั่น (ข้อความบนโฆษณา · ไม่บังคับ)</label>
        <input name="caption" value="{{ $val('caption') }}" maxlength="500" class="nx-input" placeholder="เช่น สนใจดูหนังไม่มีโฆษณา สมัคร Pro วันนี้!">
    </div>
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs text-cream/60">ลิงก์เมื่อคลิกโฆษณา (ไม่บังคับ)</label>
        <input name="link_url" value="{{ $val('link_url') }}" class="nx-input" placeholder="https://netwix.online/account">
    </div>

    {{-- skip --}}
    <label class="flex items-center gap-2 pt-5 text-sm">
        <input type="checkbox" name="skippable" value="1" x-model="skippable" class="accent-brand"> ให้ข้ามโฆษณาได้
    </label>
    <div x-show="skippable" x-cloak>
        <label class="mb-1 block text-xs text-cream/60">ข้ามได้หลังผ่านไปกี่วินาที</label>
        <input name="skip_after" type="number" min="0" max="120" value="{{ $val('skip_after', 5) }}" class="nx-input">
    </div>

    {{-- targeting --}}
    <div>
        <label class="mb-1 block text-xs text-cream/60">แสดงกับ</label>
        <select name="target" x-model="tg" class="nx-input">
            <option value="all">ทุกวิดีโอ</option>
            <option value="type">เฉพาะประเภท</option>
            <option value="genre">เฉพาะแนว / หมวด</option>
        </select>
    </div>
    <div x-show="tg === 'type'" x-cloak>
        <label class="mb-1 block text-xs text-cream/60">ประเภทคอนเทนต์</label>
        <select name="target_type" class="nx-input">
            @foreach ($types as $k => $lbl)
                <option value="{{ $k }}" @selected($val('target_type') === $k)>{{ $lbl }}</option>
            @endforeach
        </select>
    </div>
    <div x-show="tg === 'genre'" x-cloak>
        <label class="mb-1 block text-xs text-cream/60">แนว / หมวด</label>
        <select name="target_genre_id" class="nx-input">
            @foreach ($genres as $g)
                <option value="{{ $g->id }}" @selected((int) $val('target_genre_id') === $g->id)>{{ $g->name }}</option>
            @endforeach
        </select>
    </div>

    {{-- schedule + audience --}}
    <div>
        <label class="mb-1 block text-xs text-cream/60">ความถี่</label>
        <select name="frequency" class="nx-input">
            <option value="always" @selected($val('frequency', 'always') === 'always')>ทุกครั้งที่กดเล่น</option>
            <option value="session" @selected($val('frequency') === 'session')>ครั้งเดียวต่อการเข้าใช้</option>
            <option value="daily" @selected($val('frequency') === 'daily')>วันละครั้งต่อคน</option>
        </select>
    </div>
    <label class="flex items-center gap-2 pt-5 text-sm">
        <input type="checkbox" name="hide_for_pro" value="1" @checked($a ? $a->hide_for_pro : true) class="accent-brand"> ซ่อนสำหรับสมาชิก Pro
    </label>

    <div>
        <label class="mb-1 block text-xs text-cream/60">เริ่มแสดง (ไม่บังคับ)</label>
        <input name="starts_at" type="datetime-local" value="{{ old('starts_at', $a?->starts_at?->format('Y-m-d\TH:i')) }}" class="nx-input">
    </div>
    <div>
        <label class="mb-1 block text-xs text-cream/60">สิ้นสุด (ไม่บังคับ)</label>
        <input name="ends_at" type="datetime-local" value="{{ old('ends_at', $a?->ends_at?->format('Y-m-d\TH:i')) }}" class="nx-input">
    </div>

    <div>
        <label class="mb-1 block text-xs text-cream/60">ลำดับความสำคัญ (มากแสดงก่อน)</label>
        <input name="sort" type="number" min="0" value="{{ $val('sort', 0) }}" class="nx-input">
    </div>
    <label class="flex items-center gap-2 pt-5 text-sm">
        <input type="checkbox" name="is_active" value="1" @checked($a ? $a->is_active : true) class="accent-brand"> เปิดใช้งาน
    </label>
</div>
