@extends('layouts.admin')
@section('page-title', 'Debug แอป')
@section('page-subtitle', 'ล็อกจากแอปมือถือ (POST /api/app/debug) — ล็อกอิน LINE, ข้อผิดพลาด, เหตุการณ์')
@section('action')<span class="text-sm text-cream/50">ทั้งหมด {{ number_format($total) }} รายการ</span>@endsection

@section('content')
@if (session('status'))
    <div class="mb-4 rounded-lg border border-success/30 bg-success/10 px-4 py-2.5 text-sm text-success">{{ session('status') }}</div>
@endif

<div class="mb-4 flex flex-wrap items-center gap-2">
    @php $levels = ['' => 'ทั้งหมด', 'error' => 'error', 'warn' => 'warn', 'info' => 'info']; @endphp
    @foreach ($levels as $lv => $lbl)
        <a href="{{ route('admin.debug.index', array_filter(['level' => $lv, 'event' => $event])) }}"
           class="rounded-full px-3.5 py-1.5 text-[13px] {{ (string) $level === (string) $lv ? 'nx-gradient font-semibold' : 'bg-white/5 text-cream/60 hover:text-cream' }}">
            {{ $lbl }}@if ($lv && isset($counts[$lv]))<span class="opacity-60"> ({{ $counts[$lv] }})</span>@endif
        </a>
    @endforeach

    <form method="GET" class="ml-auto flex items-center gap-2">
        @if ($level)<input type="hidden" name="level" value="{{ $level }}">@endif
        <input name="event" value="{{ $event }}" placeholder="ค้นหา event…"
               class="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-sm text-cream placeholder:text-cream/30">
        <button class="rounded-lg bg-white/10 px-3 py-1.5 text-sm hover:bg-white/15">ค้นหา</button>
    </form>

    <form method="POST" action="{{ route('admin.debug.clear') }}" onsubmit="return confirm('ล้าง log ทั้งหมด?')">
        @csrf @method('DELETE')
        <button class="rounded-lg bg-[#e5484d]/15 px-3 py-1.5 text-sm text-[#ff6b81] hover:bg-[#e5484d]/25">ล้างทั้งหมด</button>
    </form>
</div>

<div class="nx-card overflow-hidden">
    <div class="flex flex-col divide-y divide-white/5">
        @forelse ($logs as $log)
            @php $lc = ['error' => '#ff6b81', 'warn' => '#ffb454', 'info' => '#3ecf8e'][$log->level] ?? '#8b8b8b'; @endphp
            <div class="px-4 py-3">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px]">
                    <span class="rounded px-2 py-0.5 text-[11px] font-bold" style="background:{{ $lc }}22;color:{{ $lc }}">{{ strtoupper($log->level) }}</span>
                    <span class="font-mono font-semibold text-cream">{{ $log->event }}</span>
                    @if ($log->app_version)<span class="text-cream/40">· v{{ $log->app_version }}</span>@endif
                    @if ($log->platform)<span class="text-cream/40">· {{ $log->platform }}</span>@endif
                    <span class="text-cream/35">· {{ $log->created_at?->diffForHumans() }}</span>
                    <span class="ml-auto font-mono text-[11px] text-cream/30">{{ $log->ip }}</span>
                </div>
                @if ($log->message)
                    <p class="mt-1 whitespace-pre-line break-words text-sm text-cream/80">{{ $log->message }}</p>
                @endif
                @if ($log->context)
                    <pre class="mt-1.5 overflow-x-auto rounded-lg bg-black/30 p-2.5 text-[11.5px] text-cream/55">{{ json_encode($log->context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre>
                @endif
            </div>
        @empty
            <div class="px-4 py-16 text-center text-cream/45">ยังไม่มี log — ให้เปิดแอปแล้วลองล็อกอิน แล้วรีเฟรชหน้านี้</div>
        @endforelse
    </div>
</div>

<div class="mt-5">{{ $logs->links() }}</div>
@endsection
