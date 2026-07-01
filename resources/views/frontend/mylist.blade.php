@extends('layouts.app')
@section('title', 'รายการของฉัน')

@section('content')
<div class="px-[4vw] pt-24 pb-12">
    <h1 class="mb-7 text-2xl font-bold sm:text-3xl">รายการของฉัน</h1>

    @if ($items->isEmpty())
        <div class="rounded-xl border border-white/5 bg-white/[0.02] py-20 text-center text-cream/50">
            ยังไม่มีรายการที่บันทึกไว้ — กด <span class="text-cream">+</span> บนการ์ดเพื่อเพิ่มเรื่องที่อยากดู
        </div>
    @else
        <div class="flex flex-wrap gap-4">
            @foreach ($items as $content)
                <x-content-card :content="$content" :in-list="true" />
            @endforeach
        </div>
    @endif
</div>
@endsection
