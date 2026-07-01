@extends('layouts.admin')
@section('page-title', 'จัดการคอนเทนต์')
@section('page-subtitle', 'ภาพยนตร์ ซีรีส์ และซีรีส์แนวตั้งทั้งหมด')

@section('content')
@php
    $tabs = ['all' => 'ทั้งหมด', 'series' => 'ซีรี่ส์', 'movie' => 'ภาพยนตร์', 'vertical' => 'แนวตั้ง'];
@endphp

<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div class="flex flex-wrap gap-2">
        @foreach ($tabs as $key => $label)
            <a href="{{ route('admin.contents.index', ['type' => $key, 'q' => $q]) }}"
               class="rounded-lg px-4 py-2 text-sm transition {{ $type === $key ? 'nx-gradient font-semibold' : 'bg-white/5 text-cream/60 hover:text-cream' }}">
                {{ $label }} <span class="opacity-60">{{ $counts[$key] }}</span>
            </a>
        @endforeach
    </div>
    <form method="GET" action="{{ route('admin.contents.index') }}" class="flex gap-2">
        <input type="hidden" name="type" value="{{ $type }}">
        <input type="text" name="q" value="{{ $q }}" placeholder="ค้นหาชื่อเรื่อง…"
               class="rounded-lg border border-white/10 bg-surface-2 px-3.5 py-2 text-sm outline-none focus:border-brand">
    </form>
</div>

<div class="nx-card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full min-w-[720px] text-sm">
            <thead class="border-b border-white/5 text-left text-xs uppercase text-cream/40">
                <tr>
                    <th class="px-4 py-3 font-medium">เรื่อง</th>
                    <th class="px-4 py-3 font-medium">ประเภท</th>
                    <th class="px-4 py-3 font-medium">หมวด</th>
                    <th class="px-4 py-3 font-medium">ตอน</th>
                    <th class="px-4 py-3 font-medium">ผู้ชม</th>
                    <th class="px-4 py-3 font-medium">สถานะ</th>
                    <th class="px-4 py-3 font-medium"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($contents as $c)
                    <tr class="border-b border-white/[0.04] hover:bg-white/[0.02]">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <div class="h-10 w-[68px] flex-shrink-0 rounded" style="background:{{ $c->gradient }}"></div>
                                <div>
                                    <div class="font-medium">{{ $c->title }}</div>
                                    <div class="text-xs text-cream/40">{{ $c->year }} · {{ $c->maturity }} @if ($c->is_original)· <span class="text-brand">Original</span>@endif</div>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-cream/70">{{ ['series' => 'ซีรี่ส์', 'movie' => 'ภาพยนตร์', 'vertical' => 'แนวตั้ง'][$c->type] }}</td>
                        <td class="px-4 py-3 text-cream/60">{{ $c->genres->pluck('name')->take(2)->join(', ') }}</td>
                        <td class="px-4 py-3 text-cream/70">{{ $c->episodes_count }}</td>
                        <td class="px-4 py-3 text-cream/70">{{ number_format($c->views) }}</td>
                        <td class="px-4 py-3">
                            @if ($c->is_published)
                                <span class="rounded-full bg-success/15 px-2.5 py-0.5 text-xs text-success">เผยแพร่</span>
                            @else
                                <span class="rounded-full bg-white/10 px-2.5 py-0.5 text-xs text-cream/50">ฉบับร่าง</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-2">
                                <a href="{{ route('admin.contents.edit', $c) }}" class="rounded-md bg-white/5 px-3 py-1.5 text-xs hover:bg-white/10">แก้ไข</a>
                                <form method="POST" action="{{ route('admin.contents.destroy', $c) }}" onsubmit="return confirm('ลบ {{ $c->title }}?')">
                                    @csrf @method('DELETE')
                                    <button class="rounded-md bg-[#e5484d]/15 px-3 py-1.5 text-xs text-[#ff6b81] hover:bg-[#e5484d]/25">ลบ</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-12 text-center text-cream/45">ไม่พบคอนเทนต์ — <a href="{{ route('admin.contents.create') }}" class="text-brand">เพิ่มเรื่องใหม่</a></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-5">{{ $contents->links() }}</div>
@endsection
