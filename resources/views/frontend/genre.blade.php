@extends('layouts.app')
@section('title', $heading)

@section('content')
<div class="px-[4vw] pt-24 pb-12">
    <h1 class="mb-7 flex flex-wrap items-center gap-3 text-2xl font-bold sm:text-3xl">
        <span class="nx-gradient h-7 w-1.5 shrink-0 rounded-full" aria-hidden="true"></span>
        <span>{{ $heading }}</span>
        @if ($headingEn ?? null)<span class="text-lg font-normal text-cream/45">{{ $headingEn }}</span>@endif
        <span class="text-base font-normal text-cream/40">{{ $items->count() }} เรื่อง</span>
    </h1>

    @if ($items->isEmpty())
        <div class="rounded-xl border border-white/5 bg-white/[0.02] py-20 text-center text-cream/50">ยังไม่มีเรื่องในหมวดนี้</div>
    @else
        <div class="flex flex-wrap gap-4">
            @foreach ($items as $content)
                <x-content-card :content="$content" :in-list="in_array($content->id, $myListIds)" />
            @endforeach
        </div>
    @endif
</div>
@endsection
