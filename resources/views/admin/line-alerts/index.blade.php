@extends('layouts.admin')
@section('page-title', 'แจ้งเตือนปัญหาเข้า LINE')
@section('page-subtitle', 'ส่งแจ้งเตือนเข้า LINE OA เมื่อแหล่งหนังล่ม หรือมีหนังเล่นไม่ได้ถูกปิดอัตโนมัติ')

@section('content')
@if (session('status'))
    <div class="mb-5 rounded-xl border border-success/30 bg-success/10 px-4 py-3 text-[13px] text-success">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="mb-5 rounded-xl border border-brand/40 bg-brand/10 px-4 py-3 text-[13px] text-brand">
        @foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach
    </div>
@endif

<div class="mb-5 rounded-xl border border-white/5 bg-white/[0.02] px-4 py-4 text-[13px] leading-relaxed text-cream/55">
    <div class="mb-1 font-semibold text-cream/80">ระบบจะแจ้งอะไรบ้าง</div>
    <div>🚨 <b class="text-cream/75">แหล่งหนังดึงลิ้งค์ไม่ได้ทั้งแหล่ง</b> — ส่งทันทีที่ตรวจเจอ (ตรวจทุก 2 ชม.) แจ้งซ้ำอย่างมากทุก 6 ชม. ต่อ 1 แหล่ง</div>
    <div>⚠️ <b class="text-cream/75">หนังเล่นไม่ได้ถูกหยุดเผยแพร่</b> — รวบยอดส่งชั่วโมงละครั้ง เป็นข้อความเดียว ไม่ยิงทีละเรื่อง</div>
    <div>❗ <b class="text-cream/75">ระบบตรวจสอบเพี้ยนเอง</b> — ถ้ารายงานว่าแหล่งล่มพร้อมกันเกินครึ่ง แปลว่าน่าจะเป็นฝั่งเรา จะแจ้งให้ไปดู</div>
    <div class="mt-2 text-[12px] text-cream/40">
        ตั้งใจไม่ให้ยิงถี่ เพราะถ้าแจ้งทีละเรื่องตอนแหล่งล่ม จะได้เป็นพันข้อความในไม่กี่นาที แล้วสุดท้ายก็ต้องปิดแจ้งเตือนทิ้ง
    </div>
</div>

@if ($ready)
    <div class="mb-5 rounded-xl border border-success/30 bg-success/10 px-4 py-3 text-[13px] text-success">
        พร้อมใช้งาน — ระบบจะส่งแจ้งเตือนเข้า LINE ให้อัตโนมัติ
        @if (!empty($sourcesDown))
            <div class="mt-1 text-cream/70">ตอนนี้มีแหล่งที่ดึงลิ้งค์ไม่ได้: <b>{{ implode(', ', $sourcesDown) }}</b></div>
        @endif
    </div>
@endif

<form method="POST" action="{{ route('admin.line-alerts.update') }}" class="nx-card space-y-4 p-5">
    @csrf @method('PUT')

    <label class="flex cursor-pointer items-center gap-2 text-[14px]">
        <input type="checkbox" name="line_alerts_enabled" value="1" @checked(old('line_alerts_enabled', $enabled))
               class="h-4 w-4 rounded border-white/20 bg-white/5">
        <span class="font-semibold">เปิดการแจ้งเตือนเข้า LINE</span>
    </label>

    <div>
        <label class="mb-1 block text-[13px] text-cream/60">
            Channel access token (Messaging API)
            @if ($hasToken)
                <span class="ml-1 rounded-full bg-success/15 px-2 py-0.5 text-[11px] text-success">ตั้งค่าไว้แล้ว</span>
            @endif
        </label>
        <input type="password" name="line_oa_token" autocomplete="new-password"
               placeholder="{{ $hasToken ? 'เว้นว่างไว้ = ใช้ค่าเดิม' : 'วาง token จาก LINE Developers' }}"
               class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2.5 text-[14px]">
        <div class="mt-1 text-[12px] text-cream/40">
            เก็บแบบเข้ารหัส และไม่แสดงค่ากลับมาอีก — ถ้าต้องการเปลี่ยนให้วางค่าใหม่ทับ
        </div>
    </div>

    <div>
        <label class="mb-1 block text-[13px] text-cream/60">ส่งหาใคร (User ID หรือ Group ID)</label>
        <input type="text" name="line_oa_to" value="{{ old('line_oa_to', $to) }}" placeholder="Uxxxxxxxxxxxxxxxx"
               class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2.5 text-[14px]">
        <div class="mt-1 text-[12px] text-cream/40">
            หา User ID ได้จาก LINE Developers → Basic settings → Your user ID (ต้องเพิ่ม OA เป็นเพื่อนก่อน)
        </div>
    </div>

    <div class="flex flex-wrap gap-2 pt-1">
        <button class="rounded-lg bg-brand px-5 py-2.5 text-[14px] font-semibold text-white hover:bg-brand/90">บันทึก</button>
    </div>
</form>

<div class="mt-4 flex flex-wrap gap-2">
    <form method="POST" action="{{ route('admin.line-alerts.test') }}">@csrf
        <button class="rounded-lg bg-white/10 px-4 py-2.5 text-[13px] font-semibold hover:bg-white/15">ทดสอบส่งข้อความ</button>
    </form>
    @if ($hasToken)
        <form method="POST" action="{{ route('admin.line-alerts.forget') }}"
              onsubmit="return confirm('ลบ Token และปิดการแจ้งเตือน?')">@csrf @method('DELETE')
            <button class="rounded-lg bg-brand/15 px-4 py-2.5 text-[13px] text-brand hover:bg-brand/25">ลบ Token</button>
        </form>
    @endif
</div>
@endsection
