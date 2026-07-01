@extends('layouts.admin')
@section('page-title', $content->exists ? 'แก้ไขคอนเทนต์' : 'เพิ่มคอนเทนต์')
@section('page-subtitle', $content->exists ? $content->title : 'สร้างเรื่องใหม่ในคลัง NetWix')
@section('action')<a href="{{ route('admin.contents.index') }}" class="rounded-lg bg-white/5 px-4 py-2.5 text-sm hover:bg-white/10">← กลับ</a>@endsection

@php
    $primaryId = $content->exists ? optional($content->primaryGenre())->id : null;
    $val = fn ($f, $d = '') => old($f, $content->$f ?? $d);
@endphp

@section('content')
<form method="POST" action="{{ $content->exists ? route('admin.contents.update', $content) : route('admin.contents.store') }}" class="grid gap-6 lg:grid-cols-[2fr_1fr]">
    @csrf
    @if ($content->exists) @method('PUT') @endif

    {{-- Left column --}}
    <div class="flex flex-col gap-5">
        <div class="nx-card p-5">
            <label class="mb-1.5 block text-sm text-cream/60">ชื่อเรื่อง *</label>
            <input name="title" value="{{ $val('title') }}" class="nx-input mb-4" required>

            <div class="mb-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-sm text-cream/60">Slug (เว้นว่างให้สร้างอัตโนมัติ)</label>
                    <input name="slug" value="{{ $val('slug') }}" class="nx-input">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm text-cream/60">ประเภท *</label>
                    <select name="type" class="nx-input">
                        @foreach (['series' => 'ซีรี่ส์', 'movie' => 'ภาพยนตร์', 'vertical' => 'ซีรีส์แนวตั้ง'] as $k => $lbl)
                            <option value="{{ $k }}" @selected($val('type') === $k)>{{ $lbl }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <label class="mb-1.5 block text-sm text-cream/60">เรื่องย่อ</label>
            <textarea name="synopsis" rows="4" class="nx-input">{{ $val('synopsis') }}</textarea>
        </div>

        <div class="nx-card p-5">
            <h3 class="mb-4 text-base font-semibold">สื่อและวิดีโอ</h3>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-sm text-cream/60">YouTube Trailer ID</label>
                    <input name="trailer_youtube_id" value="{{ $val('trailer_youtube_id') }}" placeholder="เช่น aqz-KE-bpKQ" class="nx-input">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm text-cream/60">วิดีโอหลัก (mp4 / m3u8 / YouTube) — หนัง & แนวตั้ง</label>
                    <input name="video_url" value="{{ $val('video_url') }}" class="nx-input">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm text-cream/60">โปสเตอร์ (URL 2:3)</label>
                    <input name="poster_path" value="{{ $val('poster_path') }}" class="nx-input">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm text-cream/60">ภาพพื้นหลัง (URL 16:9)</label>
                    <input name="backdrop_path" value="{{ $val('backdrop_path') }}" class="nx-input">
                </div>
            </div>
        </div>

    </div>

    {{-- Right column --}}
    <div class="flex flex-col gap-5">
        <div class="nx-card p-5">
            <button type="submit" class="btn-brand w-full py-3">{{ $content->exists ? 'บันทึกการเปลี่ยนแปลง' : 'สร้างคอนเทนต์' }}</button>
        </div>

        <div class="nx-card p-5">
            <h3 class="mb-3 text-base font-semibold">การตั้งค่า</h3>
            <div class="grid grid-cols-2 gap-4">
                <div><label class="mb-1.5 block text-xs text-cream/60">ปี</label><input name="year" type="number" value="{{ $val('year', 2025) }}" class="nx-input"></div>
                <div><label class="mb-1.5 block text-xs text-cream/60">เรตอายุ</label><input name="maturity" value="{{ $val('maturity', '13+') }}" class="nx-input"></div>
                <div><label class="mb-1.5 block text-xs text-cream/60">% ตรงใจ</label><input name="match_score" type="number" min="0" max="100" value="{{ $val('match_score', 96) }}" class="nx-input"></div>
                <div><label class="mb-1.5 block text-xs text-cream/60">คะแนน (0-10)</label><input name="rating" type="number" step="0.1" min="0" max="10" value="{{ $val('rating', 8.5) }}" class="nx-input"></div>
                <div class="col-span-2"><label class="mb-1.5 block text-xs text-cream/60">ความยาว (นาที) — เฉพาะหนัง</label><input name="duration_minutes" type="number" value="{{ $val('duration_minutes') }}" class="nx-input"></div>
            </div>
            <div class="mt-4 flex flex-col gap-2.5 text-sm">
                <label class="flex items-center gap-2.5"><input type="checkbox" name="is_original" value="1" class="accent-brand" @checked($val('is_original'))> NetWix Original</label>
                <label class="flex items-center gap-2.5"><input type="checkbox" name="is_featured" value="1" class="accent-brand" @checked($val('is_featured'))> แสดงเป็น Hero (แนะนำ)</label>
                <label class="flex items-center gap-2.5"><input type="checkbox" name="is_published" value="1" class="accent-brand" @checked($val('is_published', true))> เผยแพร่</label>
            </div>
        </div>

        <div class="nx-card p-5">
            <h3 class="mb-3 text-base font-semibold">หมวดหมู่</h3>
            <div class="flex flex-col gap-2 text-sm">
                @foreach ($genres as $g)
                    <label class="flex items-center justify-between gap-2 rounded-lg px-2 py-1.5 hover:bg-white/5">
                        <span class="flex items-center gap-2.5">
                            <input type="checkbox" name="genres[]" value="{{ $g->id }}" class="accent-brand" @checked(in_array($g->id, old('genres', $selectedGenres)))>
                            {{ $g->name }}
                        </span>
                        <label class="flex items-center gap-1 text-xs text-cream/45">
                            <input type="radio" name="primary_genre" value="{{ $g->id }}" class="accent-brand" @checked(old('primary_genre', $primaryId) == $g->id)> หลัก
                        </label>
                    </label>
                @endforeach
                @if ($genres->isEmpty())
                    <a href="{{ route('admin.genres.index') }}" class="text-brand">+ เพิ่มหมวดก่อน</a>
                @endif
            </div>
        </div>
    </div>
</form>

@if ($content->exists && $content->type !== 'movie')
    <div class="mt-6">
        @include('admin.contents.partials.episodes')
    </div>
@endif
@endsection
