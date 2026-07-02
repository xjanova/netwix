@extends('layouts.admin')
@section('page-title', 'นำเข้าหนัง')
@section('page-subtitle', 'ดึงคอนเทนต์จากแหล่งภายนอกเข้าคลัง NetWix')
@section('action')
    <form method="POST" action="{{ route('admin.import.sync') }}"
          onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='กำลังซิงค์…'">
        @csrf
        <input type="hidden" name="source" value="{{ $sourceId }}">
        <input type="hidden" name="max_pages" value="30">
        <button class="nx-gradient flex items-center gap-1.5 rounded-lg px-4 py-2.5 text-sm font-semibold" style="box-shadow:0 8px 22px rgba(176,38,255,0.32)">
            ⟳ ซิงค์แคตตาล็อก
        </button>
    </form>
@endsection

@section('content')
{{-- Source tabs --}}
<div class="mb-5 flex flex-wrap gap-2">
    @foreach ($sources as $s)
        <a href="{{ route('admin.import.index', ['source' => $s['id']]) }}"
           class="flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm transition {{ $sourceId === $s['id'] ? 'nx-gradient font-semibold' : 'bg-white/5 text-cream/60 hover:text-cream' }}">
            {{ $s['name'] }}
            <span class="rounded-full bg-black/25 px-2 py-0.5 text-[11px]">ซิงค์ {{ number_format($s['synced']) }} · นำเข้า {{ number_format($s['imported']) }}</span>
        </a>
    @endforeach
</div>

@if ($titles->total() === 0)
    <div class="nx-card p-10 text-center text-cream/55">
        ยังไม่มีข้อมูลจาก {{ $currentSource?->displayName() }} — กด <span class="text-cream">⟳ ซิงค์แคตตาล็อก</span> ด้านบนมุมขวาเพื่อดึงรายการหนังก่อน
        <div class="mt-2 text-xs text-cream/40">การซิงค์ครั้งแรกอาจใช้เวลาสักครู่ (ดึงจากเว็บต้นทางโดยตรง)</div>
    </div>
@else
    {{-- Filter / search --}}
    <form method="GET" action="{{ route('admin.import.index') }}" class="mb-4 flex flex-wrap items-center gap-2">
        <input type="hidden" name="source" value="{{ $sourceId }}">
        @foreach (['all' => 'ทั้งหมด', 'new' => 'ยังไม่นำเข้า', 'imported' => 'นำเข้าแล้ว'] as $k => $lbl)
            <a href="{{ route('admin.import.index', ['source' => $sourceId, 'filter' => $k, 'q' => $q]) }}"
               class="rounded-lg px-3.5 py-2 text-sm {{ $filter === $k ? 'nx-gradient font-semibold' : 'bg-white/5 text-cream/60 hover:text-cream' }}">{{ $lbl }}</a>
        @endforeach
        <input type="text" name="q" value="{{ $q }}" placeholder="ค้นหาชื่อเรื่อง…"
               class="ml-auto rounded-lg border border-white/10 bg-surface-2 px-3.5 py-2 text-sm outline-none focus:border-brand">
    </form>

    <form method="POST" action="{{ route('admin.import.store') }}"
          x-data="{ sel: [] }"
          onsubmit="this.querySelector('[type=submit]').disabled=true;this.querySelector('[type=submit]').textContent='กำลังนำเข้า…'">
        @csrf
        <input type="hidden" name="source" value="{{ $sourceId }}">

        {{-- Options toolbar --}}
        <div class="nx-card sticky top-[73px] z-10 mb-4 flex flex-wrap items-center gap-4 p-4">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" class="accent-brand"
                       @change="sel = $event.target.checked ? [...document.querySelectorAll('.imp-cb')].map(c=>{c.checked=true;return c.value}) : (document.querySelectorAll('.imp-cb').forEach(c=>c.checked=false),[])">
                เลือกทั้งหน้า
            </label>
            <span class="text-sm text-cream/60">เลือกแล้ว <span class="font-bold text-cream" x-text="sel.length"></span> เรื่อง</span>

            <div class="flex items-center gap-2 text-sm">
                <span class="text-cream/50">ประเภท:</span>
                <select name="type" class="rounded-md border border-white/10 bg-surface-2 px-2.5 py-1.5 text-sm outline-none">
                    @php $dt = $currentSource?->defaultContentType(); @endphp
                    <option value="vertical" @selected($dt === 'vertical')>ซีรีส์แนวตั้ง</option>
                    <option value="series" @selected($dt === 'series')>ซีรี่ส์</option>
                    <option value="movie" @selected($dt === 'movie')>ภาพยนตร์</option>
                </select>
            </div>

            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="publish" value="1" checked class="accent-brand"> เผยแพร่ทันที</label>

            <button type="submit" x-bind:disabled="sel.length === 0"
                    class="btn-brand ml-auto px-6 py-2.5 text-sm disabled:opacity-40">นำเข้าที่เลือก →</button>
        </div>

        {{-- Genre assignment --}}
        <div class="nx-card mb-4 p-4">
            <div class="mb-2 text-sm text-cream/60">จัดหมวดหมู่ให้เรื่องที่นำเข้า (เลือกได้หลายหมวด, จุดคือหมวดหลัก)</div>
            <div class="flex flex-wrap gap-2">
                @forelse ($genres as $g)
                    <label class="flex items-center gap-1.5 rounded-lg bg-white/5 px-3 py-1.5 text-sm hover:bg-white/10">
                        <input type="checkbox" name="genres[]" value="{{ $g->id }}" class="accent-brand"> {{ $g->name }}
                        <input type="radio" name="primary_genre" value="{{ $g->id }}" class="ml-1 accent-brand" title="หมวดหลัก">
                    </label>
                @empty
                    <a href="{{ route('admin.genres.index') }}" class="text-sm text-brand">+ เพิ่มหมวดก่อน</a>
                @endforelse
            </div>
        </div>

        {{-- Titles grid --}}
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-6">
            @foreach ($titles as $t)
                <label class="group relative block cursor-pointer">
                    <input type="checkbox" name="ids[]" value="{{ $t->id }}" x-model="sel"
                           class="imp-cb absolute left-2 top-2 z-10 h-5 w-5 accent-brand">
                    <div class="relative aspect-[2/3] overflow-hidden rounded-lg ring-1 ring-white/10 transition group-hover:ring-2 group-hover:ring-brand"
                         style="background:linear-gradient(160deg,#1c1626,#120e1a)">
                        @if ($t->poster_url)
                            <img src="{{ $t->poster_url }}" alt="" loading="lazy" referrerpolicy="no-referrer"
                                 class="absolute inset-0 h-full w-full object-cover"
                                 onerror="this.style.display='none'">
                        @endif
                        @if ($t->content_id)
                            <span class="absolute right-2 top-2 rounded bg-success/90 px-1.5 py-0.5 text-[9px] font-bold text-black">นำเข้าแล้ว</span>
                        @endif
                        @if ($t->dubLabel())
                            <span class="absolute bottom-9 left-2 rounded bg-black/60 px-1.5 py-0.5 text-[9px]">{{ $t->dubLabel() }}</span>
                        @endif
                        <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/90 to-transparent p-2">
                            <div class="line-clamp-2 text-[12px] font-semibold leading-tight">{{ $t->displayTitle() }}</div>
                            <div class="text-[10px] text-cream/50">{{ $t->year }} @if ($t->view_count) · 👁 {{ number_format($t->view_count) }} @endif</div>
                        </div>
                    </div>
                </label>
            @endforeach
        </div>

        <div class="mt-5">{{ $titles->links() }}</div>
    </form>
@endif
@endsection
