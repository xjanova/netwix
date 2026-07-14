@php
    $m = $m ?? null;
    $val = fn ($f, $d = '') => old($f, $m?->$f ?? $d);
@endphp
<div class="grid gap-3 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs text-cream/60">ชื่อภารกิจ *</label>
        <input name="title" value="{{ $val('title') }}" class="nx-input" required>
    </div>
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs text-cream/60">คำอธิบาย</label>
        <input name="description" value="{{ $val('description') }}" class="nx-input" placeholder="เช่น ดูตัวอย่างหนังใหม่รับเหรียญ">
    </div>
    <div>
        <label class="mb-1 block text-xs text-cream/60">แหล่งวิดีโอ</label>
        <select name="video_source" class="nx-input">
            <option value="youtube" @selected($val('video_source', 'youtube') === 'youtube')>YouTube</option>
            <option value="url" @selected($val('video_source') === 'url')>ลิงก์วิดีโอ (mp4/m3u8 · ในเว็บหรือภายนอก)</option>
        </select>
    </div>
    <div>
        <label class="mb-1 block text-xs text-cream/60">ลิงก์ / ไอดีวิดีโอ *</label>
        <input name="video_ref" value="{{ $val('video_ref') }}" class="nx-input" placeholder="YouTube URL/ID หรือ https://…mp4" required>
    </div>
    <div>
        <label class="mb-1 block text-xs text-cream/60">ต้องดูกี่วินาที *</label>
        <input name="required_seconds" type="number" min="5" max="7200" value="{{ $val('required_seconds', 60) }}" class="nx-input" required>
    </div>
    <div>
        <label class="mb-1 block text-xs text-cream/60">รูปปก (URL · ไม่บังคับ)</label>
        <input name="poster" value="{{ $val('poster') }}" class="nx-input">
    </div>
    <div>
        <label class="mb-1 block text-xs text-cream/60">รางวัล</label>
        <select name="reward_kind" class="nx-input">
            <option value="silver" @selected($val('reward_kind', 'silver') === 'silver')>เหรียญเงิน</option>
            <option value="gold" @selected($val('reward_kind') === 'gold')>เหรียญทอง</option>
        </select>
    </div>
    <div>
        <label class="mb-1 block text-xs text-cream/60">จำนวนเหรียญ *</label>
        <input name="reward_amount" type="number" min="1" value="{{ $val('reward_amount', 5) }}" class="nx-input" required>
    </div>
    <div>
        <label class="mb-1 block text-xs text-cream/60">ทำซ้ำ</label>
        <select name="repeat" class="nx-input">
            <option value="once" @selected($val('repeat', 'once') === 'once')>ครั้งเดียว</option>
            <option value="daily" @selected($val('repeat') === 'daily')>ทุกวัน</option>
        </select>
    </div>
    <div>
        <label class="mb-1 block text-xs text-cream/60">ลำดับ</label>
        <input name="sort" type="number" min="0" value="{{ $val('sort', 0) }}" class="nx-input">
    </div>
    <label class="flex items-center gap-2 text-sm sm:col-span-2">
        <input type="checkbox" name="is_active" value="1" class="accent-brand" @checked($m ? $m->is_active : true)> เปิดใช้งาน
    </label>
</div>
