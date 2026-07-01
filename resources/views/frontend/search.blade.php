@extends('layouts.app')
@section('title', $q !== '' ? 'ค้นหา: '.$q : 'ค้นหา')

@section('content')
<div class="px-[4vw] pt-24 pb-12">
    <form method="GET" action="{{ route('search') }}" class="mb-8 max-w-xl">
        <input type="text" name="q" value="{{ $q }}" placeholder="ค้นหา ชื่อเรื่อง แนว…" autofocus
               class="nx-input text-lg">
    </form>

    @if ($q === '')
        <div class="py-16 text-center text-cream/50">พิมพ์เพื่อค้นหาภาพยนตร์และซีรีส์</div>
    @elseif ($results->isEmpty())
        <div class="py-16 text-center text-cream/50">ไม่พบผลลัพธ์สำหรับ "{{ $q }}"</div>
    @else
        <h1 class="mb-6 text-lg text-cream/70">ผลการค้นหา "{{ $q }}" · {{ $results->count() }} รายการ</h1>
        <div class="flex flex-wrap gap-4">
            @foreach ($results as $content)
                <x-content-card :content="$content" :in-list="in_array($content->id, $myListIds)" />
            @endforeach
        </div>
    @endif
</div>
@endsection
