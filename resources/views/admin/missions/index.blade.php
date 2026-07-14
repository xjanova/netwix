@extends('layouts.admin')
@section('page-title', 'ภารกิจ (ดูคลิปรับเหรียญ)')
@section('page-subtitle', 'ตั้งภารกิจให้สมาชิกดูวิดีโอครบเวลาแล้วรับเหรียญเงิน/ทอง')

@section('content')
{{-- add new --}}
<div class="mb-6 nx-card p-5" x-data="{ open: false }">
    <button @click="open = !open" class="btn-brand px-4 py-2 text-sm">＋ เพิ่มภารกิจใหม่</button>
    <form x-show="open" x-cloak method="POST" action="{{ route('admin.missions.store') }}" class="mt-4">
        @csrf
        @include('admin.missions._fields', ['m' => null])
        <div class="mt-4 flex justify-end"><button class="btn-brand px-5 py-2 text-sm">บันทึกภารกิจ</button></div>
    </form>
</div>

@if ($missions->isEmpty())
    <div class="nx-card p-10 text-center text-cream/50">ยังไม่มีภารกิจ — กด “เพิ่มภารกิจใหม่” ด้านบน</div>
@endif

<div class="flex flex-col gap-3">
    @foreach ($missions as $mission)
        <div class="nx-card p-4" x-data="{ edit: false }">
            <div class="flex flex-wrap items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white/5 text-lg">{{ $mission->reward_kind === 'gold' ? '👑' : '🪙' }}</span>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-semibold">{{ $mission->title }}</span>
                        @unless ($mission->is_active)<span class="rounded bg-white/10 px-1.5 py-0.5 text-[10px] text-cream/50">ปิดอยู่</span>@endunless
                        <span class="rounded bg-white/5 px-1.5 py-0.5 text-[10px] text-cream/50">{{ $mission->repeat === 'daily' ? 'ทุกวัน' : 'ครั้งเดียว' }}</span>
                    </div>
                    <div class="mt-0.5 text-xs text-cream/45">
                        {{ $mission->video_source === 'youtube' ? 'YouTube' : 'ลิงก์วิดีโอ' }} · ดู {{ $mission->required_seconds }} วิ · รับ {{ $mission->rewardLabel() }}
                    </div>
                </div>
                <button type="button" @click="edit = !edit" class="rounded-md bg-white/5 px-3 py-1.5 text-xs hover:bg-white/10">แก้ไข</button>
                <form method="POST" action="{{ route('admin.missions.toggle', $mission) }}">
                    @csrf
                    <button class="rounded-md px-3 py-1.5 text-xs font-semibold {{ $mission->is_active ? 'text-success' : 'text-cream/45' }} hover:bg-white/5">{{ $mission->is_active ? 'เปิด' : 'ปิด' }}</button>
                </form>
                <form method="POST" action="{{ route('admin.missions.destroy', $mission) }}" onsubmit="return confirm('ลบภารกิจนี้?')">
                    @csrf @method('DELETE')
                    <button class="rounded-md bg-[#e5484d]/15 px-3 py-1.5 text-xs text-[#ff6b81] hover:bg-[#e5484d]/25">ลบ</button>
                </form>
            </div>
            <form x-show="edit" x-cloak method="POST" action="{{ route('admin.missions.update', $mission) }}" class="mt-4 border-t border-white/5 pt-4">
                @csrf @method('PUT')
                @include('admin.missions._fields', ['m' => $mission])
                <div class="mt-4 flex justify-end"><button class="btn-brand px-5 py-2 text-sm">บันทึกการแก้ไข</button></div>
            </form>
        </div>
    @endforeach
</div>
@endsection
