@extends('layouts.admin')
@section('page-title', 'ความคิดเห็น')
@section('page-subtitle', 'ดูและจัดการความคิดเห็นของสมาชิก — คอมเมนต์และคะแนนมีผลต่ออันดับหนัง')
@section('action')<span class="text-sm text-cream/50">ทั้งหมด {{ number_format($total) }} รายการ</span>@endsection

@section('content')
<div class="nx-card overflow-hidden">
    <div class="flex flex-col divide-y divide-white/5">
        @forelse ($comments as $c)
            <div class="flex items-start gap-3 px-4 py-3.5">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-[13px] font-bold text-black/60"
                      style="background:{{ $c->profile?->avatar_color ?? '#8b2ff0' }}">{{ mb_substr($c->profile?->name ?? '?', 0, 1) }}</span>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[13px]">
                        <span class="font-semibold">{{ $c->profile?->name ?? 'สมาชิก' }}</span>
                        <span class="text-cream/35">·</span>
                        @if ($c->content)
                            <a href="{{ route('admin.contents.edit', $c->content) }}" class="text-brand-2 hover:underline">{{ $c->content->title }}</a>
                        @else
                            <span class="text-cream/40">(ลบแล้ว)</span>
                        @endif
                        <span class="text-cream/35">·</span>
                        <span class="text-cream/40">{{ $c->created_at?->diffForHumans() }}</span>
                    </div>
                    <p class="mt-1 whitespace-pre-line break-words text-sm text-cream/80">{{ $c->body }}</p>
                </div>
                <form method="POST" action="{{ route('admin.comments.destroy', $c) }}" onsubmit="return confirm('ลบความคิดเห็นนี้?')">
                    @csrf @method('DELETE')
                    <button class="shrink-0 rounded-md bg-[#e5484d]/15 px-3 py-1.5 text-xs text-[#ff6b81] hover:bg-[#e5484d]/25">ลบ</button>
                </form>
            </div>
        @empty
            <div class="px-4 py-16 text-center text-cream/45">ยังไม่มีความคิดเห็น</div>
        @endforelse
    </div>
</div>

<div class="mt-5">{{ $comments->links() }}</div>
@endsection
