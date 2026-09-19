@extends('layouts.public')

@php
    use App\Support\TitleIndex;
@endphp

@section('title', 'รายชื่อหนังและซีรีส์ทั้งหมด เรียงตามตัวอักษร')
@section('meta_description', 'สารบัญรวมหนัง ซีรีส์ อนิเมะ และซีรีส์แนวตั้งทั้งหมด '.number_format($total).' เรื่องบน NetWix เรียงตามตัวอักษร ก-ฮ และ A-Z หาชื่อเรื่องที่ต้องการได้ทันที')
@section('meta_canonical', route('browse.all'))

@push('head')
<script type="application/ld+json">
{!! json_encode([
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'หน้าแรก', 'item' => route('home')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'รายชื่อทั้งหมด', 'item' => route('browse.all')],
            ],
        ],
        [
            '@type' => 'CollectionPage',
            '@id' => route('browse.all'),
            'name' => 'รายชื่อหนังและซีรีส์ทั้งหมด',
            'url' => route('browse.all'),
            'inLanguage' => 'th-TH',
            'isPartOf' => ['@id' => url('/').'#website'],
        ],
    ],
], JSON_UNESCAPED_UNICODE) !!}
</script>
@endpush

@section('content')
<div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
    <nav class="text-[13px] text-cream/40" aria-label="เส้นทาง">
        <a href="{{ route('home') }}" class="hover:text-cream">หน้าแรก</a>
        <span class="px-1.5">›</span>
        <span class="text-cream/70">รายชื่อทั้งหมด</span>
    </nav>

    <h1 class="mt-3 text-2xl font-bold sm:text-3xl">รายชื่อหนังและซีรีส์ทั้งหมด</h1>
    <p class="mt-2 max-w-3xl text-[15px] leading-relaxed text-cream/60">
        สารบัญรวมทุกเรื่องบน NetWix <b class="text-cream/80">{{ number_format($total) }} เรื่อง</b>
        เรียงตามตัวอักษรตัวแรกของชื่อเรื่อง ทั้งภาษาไทย ก-ฮ และภาษาอังกฤษ A-Z
        เลือกตัวอักษรเพื่อดูรายชื่อทั้งหมดในกลุ่มนั้น
    </p>

    @php
        $latin = array_filter($groups, fn ($n, $s) => preg_match('/^[a-z]$/', $s), ARRAY_FILTER_USE_BOTH);
        $thai = array_filter($groups, fn ($n, $s) => str_starts_with($s, 'th-'), ARRAY_FILTER_USE_BOTH);
        $rest = array_filter($groups, fn ($n, $s) => in_array($s, [TitleIndex::DIGITS, TitleIndex::OTHER], true), ARRAY_FILTER_USE_BOTH);
        $blocks = [
            ['ภาษาอังกฤษ A-Z', $latin],
            ['ภาษาไทย ก-ฮ', $thai],
            ['ตัวเลขและอื่น ๆ', $rest],
        ];
    @endphp

    @foreach ($blocks as [$blockLabel, $block])
        @if (count($block))
            <section class="mt-9">
                <h2 class="text-base font-semibold text-cream/80">{{ $blockLabel }}</h2>
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($block as $slug => $count)
                        <a href="{{ route('browse.all.group', $slug) }}"
                           class="flex min-w-[4.5rem] shrink-0 items-baseline gap-2 rounded-xl border border-white/10 bg-white/[0.04] px-3.5 py-2 transition hover:border-brand/50 hover:bg-white/[0.08]">
                            <span class="text-base font-semibold">{{ TitleIndex::label($slug) }}</span>
                            <span class="text-[12px] text-cream/40">{{ number_format($count) }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    @endforeach
</div>
@endsection
