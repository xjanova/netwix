{{-- Shared create/edit fields for a sellable placement. $p is null when creating. --}}
@php($labels = [
    'header' => 'ใต้เมนูด้านบน (เห็นทุกหน้า)',
    'infeed' => 'ในหน้ารายละเอียดเรื่อง',
    'sidebar' => 'ด้านข้าง',
    'footer' => 'เหนือส่วนท้ายเว็บ (เห็นทุกหน้า)',
])

<div class="grid gap-3 sm:grid-cols-2">
    <div>
        <label class="mb-1 block text-[12px] text-cream/50">ชื่อที่ลูกค้าเห็น</label>
        <input type="text" name="name" required maxlength="120" value="{{ old('name', $p->name ?? '') }}"
               placeholder="เช่น แบนเนอร์บนสุด เห็นทุกหน้า"
               class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-[13px]">
    </div>
    <div>
        <label class="mb-1 block text-[12px] text-cream/50">แสดงที่ช่องไหน</label>
        <select name="slot" class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-[13px]">
            @foreach ($slots as $s)
                <option value="{{ $s }}" @selected(old('slot', $p->slot ?? 'header') === $s)>{{ $labels[$s] ?? $s }}</option>
            @endforeach
        </select>
    </div>

    <div class="sm:col-span-2">
        <label class="mb-1 block text-[12px] text-cream/50">คำอธิบายสั้น</label>
        <input type="text" name="blurb" maxlength="255" value="{{ old('blurb', $p->blurb ?? '') }}"
               placeholder="เช่น เห็นทุกหน้าทั้งเว็บและมือถือ"
               class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-[13px]">
    </div>

    <div>
        <label class="mb-1 block text-[12px] text-cream/50">ราคา (USDT ต่อวัน)</label>
        <input type="number" name="price_usdt_per_day" required step="0.01" min="0.01" max="10000"
               value="{{ old('price_usdt_per_day', $p->price_usdt_per_day ?? '1.00') }}"
               class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-[13px]">
    </div>
    <div>
        <label class="mb-1 block text-[12px] text-cream/50">กี่เจ้าแสดงหมุนเวียนพร้อมกันได้</label>
        <input type="number" name="max_concurrent" required min="1" max="20"
               value="{{ old('max_concurrent', $p->max_concurrent ?? 3) }}"
               class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-[13px]">
        <div class="mt-1 text-[11.5px] text-cream/35">ยิ่งมาก = ขายได้หลายเจ้า แต่แต่ละเจ้าได้ยอดวิวน้อยลง</div>
    </div>

    <div>
        <label class="mb-1 block text-[12px] text-cream/50">กว้าง (px)</label>
        <input type="number" name="width" required min="100" max="2000" value="{{ old('width', $p->width ?? 970) }}"
               class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-[13px]">
    </div>
    <div>
        <label class="mb-1 block text-[12px] text-cream/50">สูง (px)</label>
        <input type="number" name="height" required min="50" max="1200" value="{{ old('height', $p->height ?? 250) }}"
               class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-[13px]">
    </div>

    <div>
        <label class="mb-1 block text-[12px] text-cream/50">จองได้สูงสุดกี่วัน</label>
        <input type="number" name="max_days" required min="1" max="365" value="{{ old('max_days', $p->max_days ?? 90) }}"
               class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-[13px]">
    </div>
    <div>
        <label class="mb-1 block text-[12px] text-cream/50">ไฟล์อัพโหลดสูงสุด (KB)</label>
        <input type="number" name="max_upload_kb" required min="50" max="5000"
               value="{{ old('max_upload_kb', $p->max_upload_kb ?? 600) }}"
               class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-[13px]">
    </div>

    <div>
        <label class="mb-1 block text-[12px] text-cream/50">ลำดับแสดงในหน้าลูกค้า</label>
        <input type="number" name="sort" min="0" value="{{ old('sort', $p->sort ?? 0) }}"
               class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-[13px]">
    </div>
    <label class="flex items-end gap-2 pb-2 text-[13px]">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $p->is_active ?? true))
               class="h-4 w-4 rounded border-white/20 bg-white/5">
        <span>เปิดขาย</span>
    </label>
</div>
