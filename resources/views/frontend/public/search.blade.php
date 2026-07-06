@extends('layouts.public')

@section('title', $q !== '' ? 'ค้นหา: '.$q : 'ค้นหา')
@section('meta_description', 'ค้นหาหนัง ซีรีส์ อนิเมะ และซีรีส์แนวตั้งบน NetWix')
{{-- Search-result pages are thin/near-infinite — noindex, but FOLLOW so Google still crawls out to titles. --}}
@section('meta_robots', 'noindex,follow')

@section('content')
<div class="px-[4vw] pt-24 pb-12">
    {{-- Search box (server GET → same page) --}}
    <form method="GET" action="{{ route('search') }}" class="mx-auto mb-8 flex max-w-2xl gap-2">
        <input type="text" name="q" value="{{ $q }}" autofocus placeholder="ค้นหา ชื่อเรื่อง แนว…"
               class="flex-1 rounded-lg border border-white/15 bg-ink/80 px-4 py-3 text-sm outline-none focus:border-brand">
        <button type="submit" class="btn-brand rounded-lg px-6 text-sm font-semibold">ค้นหา</button>
    </form>

    @if ($q === '')
        <p class="py-16 text-center text-cream/45">พิมพ์ชื่อเรื่องหรือแนวที่อยากดู แล้วกดค้นหา</p>
    @else
        <h1 class="mb-5 text-xl font-bold sm:text-2xl">
            ผลการค้นหา “{{ $q }}” <span class="text-base font-normal text-cream/45">{{ number_format($results->count()) }} เรื่อง</span>
        </h1>

        @if ($results->isEmpty())
            <div class="rounded-xl border border-white/5 bg-white/[0.02] py-20 text-center text-cream/50">
                ไม่พบเรื่องที่ตรงกับ “{{ $q }}” — ลองคำอื่น หรือ<a href="{{ route('home') }}" class="text-brand-2 hover:underline"> กลับหน้าแรก</a>
            </div>
        @else
            <div class="flex flex-wrap gap-4">
                @foreach ($results as $c)
                    <x-public-card :content="$c" />
                @endforeach
            </div>
        @endif
    @endif
</div>
@endsection
