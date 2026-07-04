@extends('layouts.app')
@section('title', $content->title)
@section('meta_keywords', $content->seo_keywords)

@section('content')
<div class="pt-16 pb-10">
    <div class="mx-auto max-w-5xl px-4 py-6">
        <div class="nx-card overflow-hidden">
            @include('frontend.partials.title-modal', [
                'content' => $content,
                'inMyList' => $inMyList,
                'liked' => $liked,
                'modal' => false,
            ])
        </div>
    </div>
    <x-content-row title="เรื่องที่คล้ายกัน" :items="$related" />
</div>
@endsection
