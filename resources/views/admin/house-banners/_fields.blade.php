{{-- Shared create/edit fields for a house banner. $banner is null when creating. --}}
@php($labels = [
    'all' => 'ทุกช่อง',
    'header' => 'ใต้เมนูด้านบน',
    'infeed' => 'ในหน้ารายละเอียดเรื่อง',
    'sidebar' => 'ด้านข้าง',
    'footer' => 'เหนือส่วนท้ายเว็บ',
])

<div class="grid gap-3 sm:grid-cols-2">
    <div>
        <label class="mb-1 block text-[12px] text-cream/50">ชื่อเรียก (ไว้ดูในหลังบ้าน)</label>
        <input type="text" name="name" value="{{ old('name', $banner->name ?? '') }}" placeholder="เช่น โปรสมัคร Pro"
               class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-[13px]">
    </div>
    <div>
        <label class="mb-1 block text-[12px] text-cream/50">แสดงที่ช่องไหน</label>
        <select name="slot" class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-[13px]">
            @foreach (array_merge(['all'], $slots) as $s)
                <option value="{{ $s }}" @selected(old('slot', $banner->slot ?? 'all') === $s)>{{ $labels[$s] ?? $s }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <label class="mb-1 block text-[12px] text-cream/50">อัพโหลดรูปแบนเนอร์ (แนะนำกว้าง 970px)</label>
        <input type="file" name="image_file" accept="image/*"
               class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-[13px] file:mr-3 file:rounded file:border-0 file:bg-white/10 file:px-3 file:py-1 file:text-cream">
    </div>
    <div>
        <label class="mb-1 block text-[12px] text-cream/50">หรือใส่ลิงก์รูปภายนอก</label>
        <input type="url" name="image_url" value="{{ old('image_url', $banner->image_url ?? '') }}" placeholder="https://…"
               class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-[13px]">
    </div>

    <div>
        <label class="mb-1 block text-[12px] text-cream/50">คลิกแล้วไปที่ (เว้นว่าง = กดไม่ได้)</label>
        <input type="url" name="link_url" value="{{ old('link_url', $banner->link_url ?? '') }}" placeholder="https://…"
               class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-[13px]">
    </div>
    <div>
        <label class="mb-1 block text-[12px] text-cream/50">น้ำหนัก (ใช้เฉพาะโหมด "ตามน้ำหนัก" — มากกว่า = ออกบ่อยกว่า)</label>
        <input type="number" name="weight" min="1" max="1000" value="{{ old('weight', $banner->weight ?? 10) }}"
               class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-[13px]">
    </div>

    <div>
        <label class="mb-1 block text-[12px] text-cream/50">เริ่มแสดง (เว้นว่าง = ทันที)</label>
        <input type="datetime-local" name="starts_at"
               value="{{ old('starts_at', $banner?->starts_at?->format('Y-m-d\TH:i')) }}"
               class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-[13px]">
    </div>
    <div>
        <label class="mb-1 block text-[12px] text-cream/50">หยุดแสดง (เว้นว่าง = ไม่มีกำหนด)</label>
        <input type="datetime-local" name="ends_at"
               value="{{ old('ends_at', $banner?->ends_at?->format('Y-m-d\TH:i')) }}"
               class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-[13px]">
    </div>

    <div>
        <label class="mb-1 block text-[12px] text-cream/50">ลำดับความสำคัญ (มากขึ้นก่อน)</label>
        <input type="number" name="sort" min="0" value="{{ old('sort', $banner->sort ?? 0) }}"
               class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-[13px]">
    </div>
    <label class="flex items-end gap-2 pb-2 text-[13px]">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $banner->is_active ?? true))
               class="h-4 w-4 rounded border-white/20 bg-white/5">
        <span>เปิดใช้งาน</span>
    </label>
</div>
