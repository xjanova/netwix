@extends('layouts.admin')
@section('page-title', 'แก้ไขสมาชิก')
@section('page-subtitle', $user->name.' · '.$user->email)
@section('action')<a href="{{ route('admin.users.index') }}" class="rounded-lg bg-white/5 px-4 py-2.5 text-sm hover:bg-white/10">← กลับ</a>@endsection

@section('content')
@php $val = fn ($f, $d = '') => old($f, $user->$f ?? $d); @endphp

<form method="POST" action="{{ route('admin.users.update', $user) }}" class="grid gap-6 lg:grid-cols-[2fr_1fr]">
    @csrf @method('PUT')

    <div class="flex flex-col gap-5">
        {{-- account --}}
        <div class="nx-card p-5">
            <h3 class="mb-4 text-base font-semibold">ข้อมูลบัญชี</h3>
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label class="mb-1.5 block text-sm text-cream/60">ชื่อ *</label><input name="name" value="{{ $val('name') }}" class="nx-input" required></div>
                <div><label class="mb-1.5 block text-sm text-cream/60">อีเมล (ล็อกอิน) *</label><input name="email" type="email" value="{{ $val('email') }}" class="nx-input" required></div>
                <div><label class="mb-1.5 block text-sm text-cream/60">เบอร์โทร</label><input name="phone" value="{{ $val('phone') }}" class="nx-input" placeholder="ไม่บังคับ"></div>
                <div><label class="mb-1.5 block text-sm text-cream/60">ที่อยู่</label><input name="address" value="{{ $val('address') }}" class="nx-input" placeholder="ไม่บังคับ"></div>
            </div>
            @error('email')<p class="mt-2 text-xs text-[#ff6b81]">{{ $message }}</p>@enderror
        </div>

        {{-- viewing profiles + avatar upload --}}
        <div class="nx-card p-5">
            <h3 class="mb-1 text-base font-semibold">โปรไฟล์การรับชม ({{ $user->profiles->count() }})</h3>
            <p class="mb-4 text-xs text-cream/45">อัปโหลดรูปแทนวงกลมสีให้แต่ละโปรไฟล์ได้ (ไม่บังคับ)</p>
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach ($user->profiles as $p)
                    <div class="flex items-center gap-3 rounded-lg bg-white/[0.03] p-3"
                         x-data="avatarUploader('{{ route('admin.users.avatar', [$user, $p]) }}', @js($p->avatar_url))">
                        <span class="relative flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-xl text-lg font-bold text-black/60" style="background:{{ $p->avatar_color ?: '#8b2ff0' }}">
                            <img x-show="url" x-cloak :src="url" class="absolute inset-0 h-full w-full object-cover" alt="">
                            <span x-show="!url">{{ $p->initial }}</span>
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-medium">{{ $p->name }} @if ($p->is_kids)<span class="text-[10px] text-cream/45">· เด็ก</span>@endif</div>
                            <button type="button" @click="$refs.f.click()" x-bind:disabled="busy" class="mt-1 rounded-md bg-white/10 px-2.5 py-1 text-xs hover:bg-white/15 disabled:opacity-50" x-text="busy ? 'กำลังอัปโหลด…' : '⬆ อัปโหลดรูป'"></button>
                            <input x-ref="f" type="file" accept="image/*" class="hidden" @change="up($event)">
                            <span class="ml-1 text-[11px]" :class="ok ? 'text-success' : 'text-[#ff6b81]'" x-text="msg"></span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- right column: membership + status --}}
    <div class="flex flex-col gap-5">
        <div class="nx-card p-5">
            <button type="submit" class="btn-brand w-full py-3">บันทึกการเปลี่ยนแปลง</button>
            @if (session('status'))<p class="mt-2 text-center text-xs text-success">{{ session('status') }}</p>@endif
        </div>

        <div class="nx-card p-5">
            <h3 class="mb-3 text-base font-semibold">สถานะ & สิทธิ์</h3>

            <label class="mb-3 flex items-center justify-between gap-2 rounded-lg bg-white/[0.03] px-3 py-2.5 text-sm">
                <span>เปิดใช้งานบัญชี</span>
                <input type="checkbox" name="is_active" value="1" class="accent-brand h-5 w-5" @checked($val('is_active', true))>
            </label>
            <p class="-mt-1 mb-3 text-[11px] leading-tight text-cream/40">ปิด = ระงับบัญชี (ล็อกอินไม่ได้ และถูกดีดออกทันที)</p>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="mb-1.5 block text-xs text-cream/60">บทบาท</label>
                    <select name="role" class="nx-input">
                        <option value="user" @selected($val('role') === 'user')>สมาชิก</option>
                        <option value="admin" @selected($val('role') === 'admin')>ผู้ดูแล</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs text-cream/60">แพ็กเกจ</label>
                    <select name="plan" class="nx-input">
                        @foreach (['basic' => 'Basic', 'standard' => 'Standard', 'premium' => 'Premium'] as $k => $lbl)
                            <option value="{{ $k }}" @selected($val('plan') === $k)>{{ $lbl }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-span-2">
                    <label class="mb-1.5 block text-xs text-cream/60">Pro หมดอายุ (เว้นว่าง = ไม่กำหนด)</label>
                    <input id="pro_until_input" name="pro_until" type="datetime-local" value="{{ old('pro_until', optional($user->pro_until)->format('Y-m-d\TH:i')) }}" class="nx-input">
                    <div class="mt-1.5 flex flex-wrap items-center gap-2">
                        @if ($freeDays > 0)
                            <button type="button" onclick="nxSetProExpiry({{ $freeDays }})" class="rounded-md bg-brand/15 px-2.5 py-1 text-[11px] font-semibold text-brand hover:bg-brand/25">ตั้งตามแพ็กเกจ (+{{ $freeDays }} วัน)</button>
                        @endif
                        <button type="button" onclick="nxSetProExpiry(30)" class="rounded-md bg-white/5 px-2.5 py-1 text-[11px] hover:bg-white/10">+30 วัน</button>
                        <button type="button" onclick="document.getElementById('pro_until_input').value=''" class="rounded-md bg-white/5 px-2.5 py-1 text-[11px] text-cream/50 hover:bg-white/10">ล้าง</button>
                        <span class="text-[11px] text-cream/40">สถานะตอนนี้: {{ $isPro ? 'เป็น Pro อยู่' : 'ไม่ใช่ Pro' }}</span>
                    </div>
                </div>
                <div><label class="mb-1.5 block text-xs text-cream/60">เหรียญเงิน</label><input name="coins" type="number" min="0" value="{{ $val('coins', 0) }}" class="nx-input"></div>
                <div><label class="mb-1.5 block text-xs text-cream/60">เหรียญทอง</label><input name="gold_coins" type="number" min="0" value="{{ $val('gold_coins', 0) }}" class="nx-input"></div>
            </div>
            @error('role')<p class="mt-2 text-xs text-[#ff6b81]">{{ $message }}</p>@enderror
        </div>
    </div>
</form>

@push('scripts')
<script>
    function avatarUploader(url, initial) {
        return {
            url: initial || null, busy: false, ok: false, msg: '',
            up(e) {
                const f = e.target.files && e.target.files[0];
                e.target.value = '';
                if (!f) return;
                if (!f.type.startsWith('image/')) { this.ok = false; this.msg = 'ไม่ใช่รูป'; return; }
                if (f.size > 6_000_000) { this.ok = false; this.msg = 'ใหญ่เกิน 6MB'; return; }
                this.busy = true; this.msg = '';
                const r = new FileReader();
                r.onload = async () => {
                    try {
                        const res = await window.nxPost(url, { image: r.result });
                        if (res && res.ok) { this.url = res.url; this.ok = true; this.msg = '✓'; }
                        else { this.ok = false; this.msg = 'ไม่สำเร็จ'; }
                    } catch (err) { this.ok = false; this.msg = 'ผิดพลาด'; }
                    finally { this.busy = false; }
                };
                r.readAsDataURL(f);
            },
        };
    }

    // Fill the Pro-expiry field with (now + N days) in the input's local datetime-local format.
    function nxSetProExpiry(days) {
        const d = new Date(Date.now() + days * 86400000);
        const p = (n) => String(n).padStart(2, '0');
        document.getElementById('pro_until_input').value =
            d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes());
    }
</script>
@endpush
@endsection
