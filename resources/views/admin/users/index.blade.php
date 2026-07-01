@extends('layouts.admin')
@section('page-title', 'สมาชิก')
@section('page-subtitle', 'จัดการบัญชีสมาชิกและสิทธิ์การใช้งาน')
@section('action')
    <form method="GET" action="{{ route('admin.users.index') }}">
        <input type="text" name="q" value="{{ $q }}" placeholder="ค้นหาชื่อ อีเมล…" class="rounded-lg border border-white/10 bg-surface-2 px-3.5 py-2.5 text-sm outline-none focus:border-brand">
    </form>
@endsection

@section('content')
<div class="nx-card p-4">
    <div class="hidden grid-cols-[2fr_1fr_1fr_80px_auto] gap-3 px-2 pb-3 text-xs uppercase text-cream/40 sm:grid">
        <span>สมาชิก</span><span>บทบาท</span><span>แพ็กเกจ</span><span>โปรไฟล์</span><span></span>
    </div>
    <div class="flex flex-col gap-1.5">
        @forelse ($users as $u)
            <div class="grid grid-cols-1 items-center gap-3 rounded-lg bg-white/[0.03] px-3 py-3 sm:grid-cols-[2fr_1fr_1fr_80px_auto]">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 items-center justify-center rounded-lg font-bold text-black/60" style="background:#8b2ff0">{{ mb_substr($u->name, 0, 1) }}</span>
                    <div class="min-w-0">
                        <div class="truncate text-sm font-medium">{{ $u->name }}</div>
                        <div class="truncate text-xs text-cream/45">{{ $u->email }}</div>
                    </div>
                </div>
                <form method="POST" action="{{ route('admin.users.update', $u) }}" id="user-{{ $u->id }}" class="contents">
                    @csrf @method('PUT')
                    <select name="role" class="rounded-md border border-white/10 bg-surface-2 px-2.5 py-1.5 text-sm outline-none focus:border-brand">
                        <option value="user" @selected($u->role === 'user')>สมาชิก</option>
                        <option value="admin" @selected($u->role === 'admin')>ผู้ดูแล</option>
                    </select>
                    <select name="plan" class="rounded-md border border-white/10 bg-surface-2 px-2.5 py-1.5 text-sm outline-none focus:border-brand">
                        @foreach (['basic' => 'Basic', 'standard' => 'Standard', 'premium' => 'Premium'] as $k => $lbl)
                            <option value="{{ $k }}" @selected($u->plan === $k)>{{ $lbl }}</option>
                        @endforeach
                    </select>
                    <span class="text-sm text-cream/55">{{ $u->profiles_count }}</span>
                    <button class="rounded-md bg-white/5 px-3 py-1.5 text-xs hover:bg-white/10">บันทึก</button>
                </form>
            </div>
        @empty
            <div class="px-2 py-10 text-center text-cream/45">ไม่พบสมาชิก</div>
        @endforelse
    </div>
</div>

<div class="mt-5">{{ $users->links() }}</div>
@endsection
