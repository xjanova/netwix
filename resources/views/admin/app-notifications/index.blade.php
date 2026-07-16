@extends('layouts.admin')
@section('page-title', 'แจ้งเตือนในแอป')
@section('page-subtitle', 'ส่งข่าว/แจ้งเตือนเข้ากล่องแจ้งเตือนของแอปมือถือ — ผู้ใช้เลือกปิดเป็นรายหมวดได้')
@section('action')<span></span>@endsection

@section('content')
<div class="grid gap-6 lg:grid-cols-[2fr_1fr]">
    <div class="nx-card p-4">
        <div class="flex flex-col gap-2">
            @forelse ($notifications as $n)
                <div class="rounded-lg bg-white/[0.03] p-3">
                    <form method="POST" action="{{ route('admin.app-notifications.update', $n) }}" id="ntf-{{ $n->id }}"></form>
                    <input form="ntf-{{ $n->id }}" type="hidden" name="_token" value="{{ csrf_token() }}">
                    <input form="ntf-{{ $n->id }}" type="hidden" name="_method" value="PUT">
                    <div class="grid gap-2 md:grid-cols-[150px_1fr_auto]">
                        <select form="ntf-{{ $n->id }}" name="category" class="rounded-md border border-white/10 bg-surface-2 px-2.5 py-1.5 text-sm outline-none focus:border-brand">
                            @foreach ($categories as $key => $label)
                                <option value="{{ $key }}" @selected($n->category === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <input form="ntf-{{ $n->id }}" name="title" value="{{ $n->title }}" class="rounded-md border border-white/10 bg-surface-2 px-2.5 py-1.5 text-sm outline-none focus:border-brand" required>
                        <div class="flex items-center gap-2">
                            <label class="flex items-center gap-1.5 text-xs text-cream/60">
                                <input form="ntf-{{ $n->id }}" type="checkbox" name="is_active" value="1" @checked($n->is_active) class="h-4 w-4 accent-brand"> แสดง
                            </label>
                            <button form="ntf-{{ $n->id }}" class="rounded-md bg-white/5 px-3 py-1.5 text-xs hover:bg-white/10">บันทึก</button>
                            <form method="POST" action="{{ route('admin.app-notifications.destroy', $n) }}" onsubmit="return confirm('ลบแจ้งเตือนนี้?')">
                                @csrf @method('DELETE')
                                <button class="rounded-md bg-[#e5484d]/15 px-3 py-1.5 text-xs text-[#ff6b81] hover:bg-[#e5484d]/25">ลบ</button>
                            </form>
                        </div>
                    </div>
                    <textarea form="ntf-{{ $n->id }}" name="body" rows="2" class="mt-2 w-full rounded-md border border-white/10 bg-surface-2 px-2.5 py-1.5 text-sm outline-none focus:border-brand" required>{{ $n->body }}</textarea>
                    <div class="mt-2 grid gap-2 md:grid-cols-2">
                        <input form="ntf-{{ $n->id }}" name="image_url" value="{{ $n->image_url }}" placeholder="ลิงก์รูป (ไม่บังคับ)" class="rounded-md border border-white/10 bg-surface-2 px-2.5 py-1.5 text-xs outline-none focus:border-brand">
                        <input form="ntf-{{ $n->id }}" name="link_url" value="{{ $n->link_url }}" placeholder="ลิงก์เมื่อกด (ไม่บังคับ)" class="rounded-md border border-white/10 bg-surface-2 px-2.5 py-1.5 text-xs outline-none focus:border-brand">
                    </div>
                    <div class="mt-1.5 text-[11px] text-cream/40">
                        เผยแพร่ {{ ($n->published_at ?? $n->created_at)?->format('d/m/Y H:i') }} · หมวด: {{ $categories[$n->category] ?? $n->category }}
                    </div>
                </div>
            @empty
                <div class="px-2 py-10 text-center text-cream/45">ยังไม่มีแจ้งเตือน — ส่งได้จากแบบฟอร์มด้านขวา</div>
            @endforelse
        </div>
    </div>

    <div class="nx-card h-fit p-5">
        <h3 class="mb-4 text-base font-semibold">ส่งแจ้งเตือนใหม่</h3>
        <form method="POST" action="{{ route('admin.app-notifications.store') }}" class="flex flex-col gap-3">
            @csrf
            <select name="category" class="nx-input">
                @foreach ($categories as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
            <input name="title" placeholder="หัวข้อ เช่น หนังใหม่ประจำสัปดาห์" class="nx-input" maxlength="120" required>
            <textarea name="body" placeholder="ข้อความแจ้งเตือน" class="nx-input" rows="3" maxlength="2000" required></textarea>
            <input name="image_url" type="url" placeholder="ลิงก์รูปประกอบ (ไม่บังคับ)" class="nx-input">
            <input name="link_url" type="url" placeholder="ลิงก์เมื่อกด (ไม่บังคับ)" class="nx-input">
            <label class="flex items-center gap-2 text-sm text-cream/70">
                <input type="checkbox" name="is_active" value="1" checked class="h-4 w-4 accent-brand"> แสดงทันที
            </label>
            <button class="btn-brand py-2.5">📣 ส่งแจ้งเตือน</button>
            <p class="text-xs leading-relaxed text-cream/40">
                ส่งแล้วจะ push ถึงเครื่องผู้ใช้ทันที (ตามหมวดที่ผู้ใช้เปิดรับ) และแสดงในกล่องแจ้งเตือนของแอป
            </p>
        </form>

        <div class="mt-6 border-t border-white/10 pt-5">
            <div class="mb-2 flex items-center justify-between">
                <h3 class="text-sm font-semibold">Push (FCM)</h3>
                @if ($fcmConfigured)
                    <span class="rounded-full bg-success/15 px-2.5 py-0.5 text-[11px] font-bold text-success">เชื่อมต่อแล้ว</span>
                @else
                    <span class="rounded-full bg-[#e5484d]/15 px-2.5 py-0.5 text-[11px] font-bold text-[#ff6b81]">ยังไม่ตั้งค่า</span>
                @endif
            </div>
            <form method="POST" action="{{ route('admin.app-notifications.fcm') }}" class="flex flex-col gap-2.5">
                @csrf @method('PUT')
                <textarea name="fcm_service_account" rows="4" class="nx-input w-full font-mono text-[11px]"
                    placeholder='วางไฟล์ service account JSON ของ Firebase (netwix-online) ที่นี่ — {"type":"service_account", ...}'></textarea>
                <button class="rounded-lg bg-white/5 py-2 text-sm hover:bg-white/10">บันทึกคีย์ FCM</button>
                <p class="text-[11px] leading-relaxed text-cream/40">
                    คีย์ถูกเข้ารหัสในฐานข้อมูล ไม่แสดงซ้ำหลังบันทึก · วางค่าใหม่เพื่อเปลี่ยนคีย์ · บันทึกค่าว่างเพื่อปิด push
                </p>
            </form>
        </div>
    </div>
</div>
@endsection
