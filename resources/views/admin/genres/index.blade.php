@extends('layouts.admin')
@section('page-title', 'หมวดหมู่')
@section('page-subtitle', 'จัดการหมวดหมู่คอนเทนต์')
@section('action')<span></span>@endsection

@section('content')
<div class="grid gap-6 lg:grid-cols-[2fr_1fr]">
    <div class="nx-card p-4">
        <div class="mb-3 grid grid-cols-[1fr_80px_70px_auto] gap-3 px-2 text-xs uppercase text-cream/40">
            <span>หมวด</span><span>ลำดับ</span><span>เรื่อง</span><span></span>
        </div>
        <div class="flex flex-col gap-1.5">
            @forelse ($genres as $g)
                <div class="grid grid-cols-[1fr_80px_70px_auto] items-center gap-3 rounded-lg bg-white/[0.03] px-2 py-2">
                    <form method="POST" action="{{ route('admin.genres.update', $g) }}" id="genre-{{ $g->id }}"></form>
                    <input form="genre-{{ $g->id }}" name="name" value="{{ $g->name }}" class="rounded-md border border-white/10 bg-surface-2 px-2.5 py-1.5 text-sm outline-none focus:border-brand">
                    <input form="genre-{{ $g->id }}" name="sort" type="number" value="{{ $g->sort }}" class="w-full rounded-md border border-white/10 bg-surface-2 px-2.5 py-1.5 text-sm outline-none focus:border-brand">
                    <span class="text-sm text-cream/60">{{ $g->contents_count }}</span>
                    <div class="flex items-center gap-2">
                        <button form="genre-{{ $g->id }}" class="rounded-md bg-white/5 px-3 py-1.5 text-xs hover:bg-white/10">บันทึก</button>
                        <form method="POST" action="{{ route('admin.genres.destroy', $g) }}" onsubmit="return confirm('ลบหมวด {{ $g->name }}?')">
                            @csrf @method('DELETE')
                            <button class="rounded-md bg-[#e5484d]/15 px-3 py-1.5 text-xs text-[#ff6b81] hover:bg-[#e5484d]/25">ลบ</button>
                        </form>
                    </div>
                    {{-- hidden fields for the edit form --}}
                    <input form="genre-{{ $g->id }}" type="hidden" name="_token" value="{{ csrf_token() }}">
                    <input form="genre-{{ $g->id }}" type="hidden" name="_method" value="PUT">
                </div>
            @empty
                <div class="px-2 py-10 text-center text-cream/45">ยังไม่มีหมวด</div>
            @endforelse
        </div>
    </div>

    <div class="nx-card h-fit p-5">
        <h3 class="mb-4 text-base font-semibold">เพิ่มหมวดใหม่</h3>
        <form method="POST" action="{{ route('admin.genres.store') }}" class="flex flex-col gap-3">
            @csrf
            <input name="name" placeholder="ชื่อหมวด เช่น สารคดี" class="nx-input" required>
            <input name="sort" type="number" value="{{ $genres->count() }}" placeholder="ลำดับ" class="nx-input">
            <button class="btn-brand py-2.5">+ เพิ่มหมวด</button>
        </form>
    </div>
</div>
@endsection
