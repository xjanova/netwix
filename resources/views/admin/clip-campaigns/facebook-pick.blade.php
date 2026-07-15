@extends('layouts.admin')
@section('page-title', 'เลือกเพจ Facebook')
@section('page-subtitle', 'บัญชีนี้ดูแลหลายเพจ — เลือกเพจที่จะให้แคมเปญคลิปโพสต์อัตโนมัติ')

@section('content')
<div class="max-w-xl space-y-3">
    @foreach ($pages as $page)
        <form method="POST" action="{{ route('admin.facebook.select') }}">
            @csrf
            <input type="hidden" name="page_id" value="{{ $page['id'] }}">
            <button class="flex w-full items-center justify-between rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-left text-sm text-cream hover:border-brand/50 hover:bg-brand/10">
                <span class="font-semibold">{{ $page['name'] }}</span>
                <span class="text-[12px] text-cream/40">ID {{ $page['id'] }}</span>
            </button>
        </form>
    @endforeach
    <a href="{{ route('admin.clip-campaigns.index') }}" class="inline-block text-sm text-cream/50 hover:text-cream">← ยกเลิก</a>
</div>
@endsection
