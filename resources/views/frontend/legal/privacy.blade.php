@extends('layouts.guest')
@section('title', 'นโยบายความเป็นส่วนตัว')

@section('content')
<div class="min-h-screen bg-ink text-cream">
    @include('partials.site-header')

    <div class="mx-auto max-w-3xl px-[5vw] py-12">
        <h1 class="text-[clamp(26px,4.5vw,40px)] font-extrabold">นโยบายความเป็นส่วนตัว</h1>
        <p class="mt-2 text-sm text-cream/45">ปรับปรุงล่าสุด: {{ $updated }}</p>

        <div class="nx-legal mt-8">
            {!! $custom !!}
        </div>
    </div>

    @include('partials.site-footer')
</div>
@endsection
