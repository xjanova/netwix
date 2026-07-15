@extends('layouts.admin')
@section('page-title', 'DM ชวนดูหนัง (Facebook)')
@section('page-subtitle', 'ตอบข้อความส่วนตัวคนที่คอมเมนต์บนคลิปเรา ชวนไปดูเรื่องนั้นบนเว็บ/แอป')

@section('content')
@if (session('status'))
    <div class="mb-5 rounded-lg border border-emerald-400/30 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200">{{ session('status') }}</div>
@endif

{{-- Connection + permission status --}}
<div class="nx-card mb-5 p-5">
    <div class="flex flex-wrap items-center gap-3 text-sm">
        <span class="rounded-full px-3 py-1 {{ $connected ? 'bg-emerald-400/15 text-emerald-200' : 'bg-rose-400/15 text-rose-200' }}">
            {{ $connected ? '● เชื่อมต่อเพจแล้ว' . ($pageName ? " ($pageName)" : '') : '● ยังไม่ได้เชื่อมต่อเพจ' }}
        </span>
        <span class="text-cream/50">ตอบคนที่คอมเมนต์คลิปเรา ชวนไปดูเรื่องนั้นบนเว็บ/แอป</span>
    </div>
    <div class="mt-3 rounded-lg border border-amber-400/25 bg-amber-400/5 px-4 py-3 text-[13px] leading-relaxed text-amber-100/90">
        <b>โหมด “ตอบใต้คอมเมนต์ (สาธารณะ)”</b> ใช้ได้เลยด้วยสิทธิ์ที่มีอยู่ (ไม่ต้องรีวิว) —
        ส่วนโหมด <b>“ทัก DM ส่วนตัว”</b> ต้องขอสิทธิ์ <code>pages_messaging</code> (App Review) ก่อน มิฉะนั้นจะขึ้นสถานะ failed.
        ทั้งสองโหมดต้องตั้ง <b>Webhook</b> (ด้านล่าง) ให้เพจส่งคอมเมนต์เข้ามาก่อน แล้วเปิดสวิตช์
    </div>
</div>

{{-- Webhook setup (paste into the Facebook App → Webhooks) --}}
<div class="nx-card mb-5 p-5">
    <h3 class="mb-3 text-base font-semibold">ตั้งค่า Webhook (วางค่าเหล่านี้ในหน้า Facebook App → Webhooks → Page)</h3>
    <div class="grid gap-3 sm:grid-cols-2">
        <label class="block text-sm">
            <span class="mb-1 block text-cream/60">Callback URL</span>
            <input type="text" readonly value="{{ $webhookUrl }}" onclick="this.select()" class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-sm outline-none">
        </label>
        <label class="block text-sm">
            <span class="mb-1 block text-cream/60">Verify Token</span>
            <input type="text" readonly value="{{ $verifyToken }}" onclick="this.select()" class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-sm outline-none">
        </label>
    </div>
    <p class="mt-2 text-[12px] text-cream/45">Subscribe ฟิลด์ <code>feed</code> ของเพจ แล้วยืนยัน (ระบบจะตอบ challenge ให้อัตโนมัติ)</p>
</div>

{{-- Stats --}}
<div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-5">
    @foreach ([['คอมเมนต์ทั้งหมด', $stats['total']], ['ส่ง DM สำเร็จ', $stats['sent']], ['ข้าม', $stats['skipped']], ['ล้มเหลว', $stats['failed']], ['วันนี้', $stats['today']]] as [$lbl, $val])
        <div class="nx-card p-4">
            <div class="text-2xl font-extrabold">{{ number_format($val) }}</div>
            <div class="text-xs text-cream/50">{{ $lbl }}</div>
        </div>
    @endforeach
</div>

{{-- Config form --}}
<form method="POST" action="{{ route('admin.fb-dm.update') }}" class="nx-card p-5">
    @csrf @method('PUT')

    <label class="mb-4 flex items-center gap-3">
        <input type="checkbox" name="enabled" value="1" @checked($cfg['enabled'] ?? false) class="h-5 w-5 rounded border-white/20 bg-surface-2">
        <span class="text-sm font-semibold">เปิดใช้งานระบบ DM ชวนดูหนัง</span>
    </label>

    <label class="mb-4 block text-sm">
        <span class="mb-1 block text-cream/60">วิธีตอบ</span>
        <select name="reply_mode" class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-sm outline-none focus:border-brand">
            <option value="public" @selected(($cfg['reply_mode'] ?? 'public') === 'public')>ตอบใต้คอมเมนต์ (สาธารณะ) — ใช้ได้เลย ไม่ต้องรีวิว</option>
            <option value="dm" @selected(($cfg['reply_mode'] ?? 'public') === 'dm')>ทัก DM ส่วนตัว — ต้องมีสิทธิ์ pages_messaging (App Review)</option>
        </select>
    </label>

    <div class="mb-4 grid gap-4 sm:grid-cols-2">
        <label class="block text-sm">
            <span class="mb-1 block text-cream/60">ไม่ชวนคนเดิม–เรื่องเดิม ซ้ำภายใน (วัน)</span>
            <input type="number" name="cooldown_days" min="0" max="365" value="{{ $cfg['cooldown_days'] ?? 7 }}" class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-sm outline-none focus:border-brand">
        </label>
        <label class="block text-sm">
            <span class="mb-1 block text-cream/60">ส่งให้คนหนึ่งได้สูงสุด/วัน (0 = ไม่จำกัด)</span>
            <input type="number" name="daily_cap_per_user" min="0" max="1000" value="{{ $cfg['daily_cap_per_user'] ?? 3 }}" class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-sm outline-none focus:border-brand">
        </label>
    </div>

    <label class="mb-4 block text-sm">
        <span class="mb-1 block text-cream/60">ข้อความเชิญ (สุ่มสลับ, คั่นแต่ละแบบด้วยบรรทัด <code>---</code>) — ใช้ตัวแปร <code>{title}</code> <code>{web}</code> <code>{app}</code></span>
        <textarea name="messages" rows="10" class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 font-mono text-[13px] outline-none focus:border-brand">{{ implode("\n---\n", (array) ($cfg['messages'] ?? [])) }}</textarea>
    </label>

    <label class="mb-4 block text-sm">
        <span class="mb-1 block text-cream/60">เปลี่ยน Verify Token (เว้นว่างไว้ถ้าไม่เปลี่ยน)</span>
        <input type="text" name="verify_token" placeholder="••••••••" class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-sm outline-none focus:border-brand">
    </label>

    <button class="rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white hover:opacity-90">บันทึก</button>
</form>

{{-- Recent engagements --}}
<div class="nx-card mt-6 p-5">
    <h3 class="mb-3 text-base font-semibold">คอมเมนต์ล่าสุด</h3>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="text-cream/45">
                <tr><th class="px-3 py-2">เวลา</th><th class="px-3 py-2">ผู้ใช้</th><th class="px-3 py-2">เรื่อง</th><th class="px-3 py-2">สถานะ DM</th></tr>
            </thead>
            <tbody>
                @forelse ($recent as $e)
                    <tr class="border-t border-white/5">
                        <td class="px-3 py-2 text-cream/55">{{ $e->created_at->diffForHumans() }}</td>
                        <td class="px-3 py-2">{{ $e->fb_user_name ?: $e->fb_user_id }}</td>
                        <td class="px-3 py-2 text-cream/70">{{ $e->content?->title ?? '—' }}</td>
                        <td class="px-3 py-2">
                            <span class="rounded-full px-2 py-0.5 text-xs {{ ['sent'=>'bg-emerald-400/15 text-emerald-200','failed'=>'bg-rose-400/15 text-rose-200','skipped'=>'bg-white/10 text-cream/50','pending'=>'bg-amber-400/15 text-amber-100'][$e->dm_status] ?? 'bg-white/10' }}">
                                {{ $e->dm_status }}@if($e->dm_error) · {{ $e->dm_error }}@endif
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-3 py-6 text-center text-cream/40">ยังไม่มีคอมเมนต์เข้ามา</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
