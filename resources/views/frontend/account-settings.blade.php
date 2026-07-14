@extends('layouts.app')
@section('title', 'จัดการโปรไฟล์')

@section('content')
<div class="mx-auto max-w-3xl px-4 py-8">
    <div class="mb-6 flex items-center gap-3">
        <a href="{{ route('account') }}" class="flex h-9 w-9 items-center justify-center rounded-full bg-white/10 hover:bg-white/20">‹</a>
        <h1 class="text-2xl font-bold">จัดการโปรไฟล์ / บัญชี</h1>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-lg border border-success/30 bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</div>
    @endif

    {{-- account contact --}}
    <form method="POST" action="{{ route('account.contact') }}" class="nx-card mb-5 p-5">
        @csrf
        <h2 class="mb-4 text-base font-semibold">ข้อมูลบัญชี</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="mb-1.5 block text-sm text-cream/60">ชื่อ</label>
                <input name="name" value="{{ old('name', $user->name) }}" class="nx-input" required>
            </div>
            <div>
                <label class="mb-1.5 block text-sm text-cream/60">อีเมล</label>
                <input value="{{ $user->email }}" class="nx-input opacity-60" disabled>
                <p class="mt-1 text-[11px] text-cream/40">แก้อีเมลได้เฉพาะผู้ดูแลระบบ</p>
            </div>
            <div>
                <label class="mb-1.5 block text-sm text-cream/60">เบอร์โทร <span class="text-cream/35">(ไม่บังคับ)</span></label>
                <input name="phone" value="{{ old('phone', $user->phone) }}" class="nx-input" placeholder="เช่น 08x-xxx-xxxx">
            </div>
            <div>
                <label class="mb-1.5 block text-sm text-cream/60">ที่อยู่ <span class="text-cream/35">(ไม่บังคับ)</span></label>
                <input name="address" value="{{ old('address', $user->address) }}" class="nx-input" placeholder="ที่อยู่สำหรับจัดส่ง/ติดต่อ">
            </div>
        </div>
        <div class="mt-4 flex justify-end">
            <button class="btn-brand px-6 py-2.5 text-sm">บันทึกข้อมูล</button>
        </div>
    </form>

    {{-- viewing profiles --}}
    <div class="nx-card p-5">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-semibold">โปรไฟล์การรับชม</h2>
            <a href="{{ route('profiles.index') }}" class="text-xs text-brand hover:underline">เพิ่ม / ลบโปรไฟล์ →</a>
        </div>
        <div class="flex flex-col gap-3">
            @foreach ($profiles as $p)
                <div class="flex flex-wrap items-center gap-4 rounded-lg bg-white/[0.03] p-4"
                     x-data="avatarUploader('{{ route('profiles.avatar', $p) }}', @js($p->avatar_url))">
                    <span class="relative flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-xl text-2xl font-bold text-black/60" style="background:{{ $p->avatar_color ?: '#8b2ff0' }}">
                        <img x-show="url" x-cloak :src="url" class="absolute inset-0 h-full w-full object-cover" alt="">
                        <span x-show="!url">{{ $p->initial }}</span>
                    </span>
                    <div class="min-w-0 flex-1">
                        <button type="button" @click="$refs.f.click()" x-bind:disabled="busy" class="rounded-lg bg-white/10 px-4 py-2 text-sm hover:bg-white/15 disabled:opacity-50" x-text="busy ? 'กำลังอัปโหลด…' : '⬆ เปลี่ยนรูปโปรไฟล์'"></button>
                        <input x-ref="f" type="file" accept="image/*" class="hidden" @change="up($event)">
                        <span class="ml-2 text-xs" :class="ok ? 'text-success' : 'text-[#ff6b81]'" x-text="msg"></span>
                    </div>
                    <form method="POST" action="{{ route('profiles.update', $p) }}" class="flex flex-wrap items-center gap-2">
                        @csrf
                        <input name="name" value="{{ $p->name }}" maxlength="40" class="w-40 rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-sm outline-none focus:border-brand">
                        <label class="flex items-center gap-1.5 text-xs text-cream/60"><input type="checkbox" name="is_kids" value="1" class="accent-brand" @checked($p->is_kids)> เด็ก</label>
                        <button class="rounded-lg bg-white/10 px-4 py-2 text-sm font-semibold hover:bg-white/15">บันทึก</button>
                    </form>
                </div>
            @endforeach
        </div>
    </div>
</div>

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
                        if (res && res.ok) { this.url = res.url; this.ok = true; this.msg = '✓ อัปเดตแล้ว'; }
                        else { this.ok = false; this.msg = 'อัปโหลดไม่สำเร็จ'; }
                    } catch (err) { this.ok = false; this.msg = 'ผิดพลาด'; }
                    finally { this.busy = false; }
                };
                r.readAsDataURL(f);
            },
        };
    }
</script>
@endpush
@endsection
