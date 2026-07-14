@extends('layouts.admin')
@section('page-title', 'สมาชิก')
@section('page-subtitle', 'จัดการบัญชีสมาชิก สิทธิ์ วันหมดอายุ และการใช้งาน')
@section('action')
    <form method="GET" action="{{ route('admin.users.index') }}">
        @if ($filter)<input type="hidden" name="filter" value="{{ $filter }}">@endif
        <input type="text" name="q" value="{{ $q }}" placeholder="ค้นหาชื่อ อีเมล เบอร์…" class="rounded-lg border border-white/10 bg-surface-2 px-3.5 py-2.5 text-sm outline-none focus:border-brand">
    </form>
@endsection

@section('content')
{{-- filter tabs --}}
<div class="mb-4 flex flex-wrap gap-2 text-sm">
    @foreach (['' => 'ทั้งหมด', 'active' => 'ใช้งานอยู่', 'inactive' => 'ถูกระงับ', 'pro' => 'Pro'] as $k => $lbl)
        <a href="{{ route('admin.users.index', array_filter(['filter' => $k, 'q' => $q])) }}"
           class="rounded-lg px-3.5 py-2 {{ $filter === $k ? 'nx-gradient font-semibold' : 'bg-white/5 text-cream/60 hover:text-cream' }}">{{ $lbl }}</a>
    @endforeach
</div>

<div class="nx-card p-4">
    <div class="hidden grid-cols-[2.2fr_1fr_1.3fr_90px_auto] gap-3 px-2 pb-3 text-xs uppercase text-cream/40 sm:grid">
        <span>สมาชิก</span><span>แพ็กเกจ</span><span>Pro / หมดอายุ</span><span>สถานะ</span><span></span>
    </div>
    <div class="flex flex-col gap-1.5">
        @forelse ($users as $u)
            @php
                $proActive = $u->plan !== 'basic' || ($u->pro_until && $u->pro_until->isFuture());
            @endphp
            <div class="grid grid-cols-1 items-center gap-3 rounded-lg bg-white/[0.03] px-3 py-3 sm:grid-cols-[2.2fr_1fr_1.3fr_90px_auto]">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg font-bold text-black/60" style="background:#8b2ff0">{{ mb_substr($u->name, 0, 1) }}</span>
                    <div class="min-w-0">
                        <div class="truncate text-sm font-medium">{{ $u->name }} @if ($u->isAdmin())<span class="ml-1 rounded bg-brand/20 px-1.5 py-0.5 text-[10px] text-brand">ผู้ดูแล</span>@endif</div>
                        <div class="truncate text-xs text-cream/45">{{ $u->email }}</div>
                    </div>
                </div>
                <span class="text-sm capitalize text-cream/70">{{ $u->plan }}</span>
                <span class="text-xs">
                    @if ($proActive)
                        <span class="text-success">● Pro</span>
                        @if ($u->pro_until)<span class="text-cream/45">ถึง {{ $u->pro_until->format('d/m/y') }}</span>@else<span class="text-cream/45">(แพ็กเกจ)</span>@endif
                    @else
                        <span class="text-cream/40">ฟรี</span>
                    @endif
                </span>
                <form method="POST" action="{{ route('admin.users.active', $u) }}" title="เปิด/ปิดการใช้งานบัญชี">
                    @csrf
                    <input type="hidden" name="active" value="{{ $u->is_active ? '0' : '1' }}">
                    <button class="flex items-center gap-1.5 text-xs font-semibold {{ $u->is_active ? 'text-success' : 'text-[#ff6b81]' }}">
                        <span class="relative inline-flex h-4 w-7 shrink-0 items-center rounded-full {{ $u->is_active ? 'bg-success/70' : 'bg-white/15' }}">
                            <span class="absolute h-3 w-3 rounded-full bg-white transition-all" style="{{ $u->is_active ? 'left:14px' : 'left:2px' }}"></span>
                        </span>
                        {{ $u->is_active ? 'ใช้งาน' : 'ระงับ' }}
                    </button>
                </form>
                <a href="{{ route('admin.users.edit', $u) }}" class="rounded-md bg-white/5 px-3.5 py-1.5 text-center text-xs hover:bg-white/10">แก้ไข →</a>
            </div>
        @empty
            <div class="px-2 py-10 text-center text-cream/45">ไม่พบสมาชิก</div>
        @endforelse
    </div>
</div>

<div class="mt-5">{{ $users->links() }}</div>
@endsection
