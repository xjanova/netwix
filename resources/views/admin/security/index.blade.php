@extends('layouts.admin')
@section('page-title', 'ความปลอดภัย / พฤติกรรมน่าสงสัย')
@section('page-subtitle', 'บันทึกว่าใครมาทำอะไรผิดปกติกับเว็บเรา และควบคุมว่าจะจัดการอย่างไร')

@section('content')
@php
    $modes = [
        'off' => ['ปิดระบบ', 'ไม่เฝ้าดู ไม่บันทึกอะไรเลย'],
        'observe' => ['สังเกตอย่างเดียว', 'บันทึกทุกอย่าง แต่ไม่ปฏิเสธใคร — ปลอดภัยที่สุด'],
        'enforce' => ['บล็อกจริง', 'ปฏิเสธผู้ที่ทำผิดซ้ำจนคะแนนถึงเกณฑ์'],
    ];
@endphp

@if (session('status'))
    <div class="mb-4 rounded-lg border border-success/30 bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="mb-4 rounded-lg border border-[#e5484d]/30 bg-[#e5484d]/10 px-4 py-3 text-sm text-[#ff6b81]">{{ $errors->first() }}</div>
@endif

{{-- ── สถิติ ─────────────────────────────────────────────── --}}
<div class="mb-5 grid gap-4 sm:grid-cols-3">
    @foreach ([['วันนี้', $stats['today'], 'เหตุการณ์'], ['7 วัน', $stats['week'], 'เหตุการณ์'], ['กำลังถูกบล็อก', $stats['blocked'], 'ไอพี']] as [$label, $value, $unit])
        <div class="nx-card p-5">
            <div class="text-[13px] text-cream/45">{{ $label }}</div>
            <div class="mt-1 text-2xl font-semibold">{{ number_format($value) }} <span class="text-sm font-normal text-cream/40">{{ $unit }}</span></div>
        </div>
    @endforeach
</div>

{{-- ── ตัวควบคุม ─────────────────────────────────────────── --}}
<div class="mb-6 grid gap-4 lg:grid-cols-2">
    <div class="nx-card p-5">
        <h3 class="mb-1 text-base font-semibold">โหมดการทำงาน</h3>
        <p class="mb-3 text-[12px] leading-relaxed text-cream/45">
            เริ่มที่ “สังเกตอย่างเดียว” เสมอ แล้วค่อยดูจากบันทึกว่าเกณฑ์แรงไปไหม — การบล็อกพลาดแล้วลูกค้าดูหนังไม่ได้ เสียหายกว่าโดนดูดข้อมูล
        </p>
        <form method="POST" action="{{ route('admin.security.mode') }}" class="flex flex-wrap gap-2">
            @csrf
            @foreach ($modes as $key => [$label, $desc])
                <button name="mode" value="{{ $key }}" title="{{ $desc }}"
                        class="rounded-lg px-3.5 py-2 text-[13px] {{ $mode === $key ? 'bg-brand/20 font-semibold text-brand' : 'bg-white/5 text-cream/60 hover:bg-white/10' }}">
                    {{ $label }}
                </button>
            @endforeach
        </form>
        <p class="mt-2 text-[12px] text-cream/50">ตอนนี้: <b class="text-cream/80">{{ $modes[$mode][0] }}</b> — {{ $modes[$mode][1] }}</p>
    </div>

    <div class="nx-card p-5">
        <h3 class="mb-1 text-base font-semibold">บล็อกที่ไฟร์วอลล์ (Apache)</h3>
        <p class="mb-3 text-[12px] leading-relaxed text-cream/45">
            เปิดไว้ = ผู้ต้องสงสัยถูกปฏิเสธตั้งแต่ชั้นเว็บเซิร์ฟเวอร์ ไม่กินทรัพยากรระบบเลย<br>
            <span class="text-cream/35">ระบบสำรองไฟล์ก่อนเขียนทุกครั้ง และถ้าเขียนแล้วเว็บใช้งานไม่ได้ จะคืนค่าเดิมให้อัตโนมัติภายในไม่กี่วินาที</span>
        </p>
        <form method="POST" action="{{ route('admin.security.firewall') }}" class="flex items-center gap-3">
            @csrf
            <input type="hidden" name="enabled" value="{{ $firewall ? 0 : 1 }}">
            <button class="rounded-lg px-4 py-2 text-[13px] font-semibold {{ $firewall ? 'bg-[#e5484d]/15 text-[#ff6b81] hover:bg-[#e5484d]/25' : 'bg-success/15 text-success hover:bg-success/25' }}">
                {{ $firewall ? 'ปิดการบล็อกที่ไฟร์วอลล์' : 'เปิดการบล็อกที่ไฟร์วอลล์' }}
            </button>
            <span class="text-[12px] text-cream/50">สถานะ: <b class="{{ $firewall ? 'text-success' : 'text-cream/70' }}">{{ $firewall ? 'เปิดอยู่' : 'ปิดอยู่' }}</b></span>
        </form>
    </div>
</div>

{{-- ── ไอพีที่น่าจับตา + รายการที่บล็อก ─────────────────── --}}
<div class="mb-6 grid gap-4 lg:grid-cols-2">
    <div class="nx-card overflow-hidden p-0">
        <div class="border-b border-white/5 px-5 py-3.5">
            <h3 class="text-base font-semibold">น่าจับตา (24 ชม.)</h3>
            <p class="text-[12px] text-cream/45">คะแนนยิ่งสูง ยิ่งมีพฤติกรรมแบบเก็บข้อมูลอัตโนมัติ</p>
        </div>
        @if ($offenders->isEmpty())
            <div class="px-5 py-8 text-center text-[13px] text-cream/45">ยังไม่พบพฤติกรรมผิดปกติ</div>
        @else
            <table class="w-full text-sm">
                <tbody>
                @foreach ($offenders as $o)
                    <tr class="border-b border-white/5 last:border-0">
                        <td class="px-5 py-2.5 font-mono text-[12px]">{{ $o->ip }}</td>
                        <td class="px-2 py-2.5 text-right text-cream/60">{{ number_format($o->events) }} ครั้ง</td>
                        <td class="px-2 py-2.5 text-right"><span class="rounded-full bg-white/5 px-2 py-0.5 text-[11px]">{{ number_format($o->total) }} คะแนน</span></td>
                        <td class="px-5 py-2.5 text-right">
                            <a href="{{ route('admin.security.index', ['ip' => $o->ip]) }}" class="text-[12px] text-brand hover:underline">ดูประวัติ</a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="nx-card overflow-hidden p-0">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-white/5 px-5 py-3.5">
            <div>
                <h3 class="text-base font-semibold">รายการที่ถูกบล็อก</h3>
                <p class="text-[12px] text-cream/45">ปลดได้ทุกเมื่อ — การปลดจะถูกบันทึกไว้ด้วย</p>
            </div>
            <form method="POST" action="{{ route('admin.security.block') }}" class="flex items-center gap-1.5">
                @csrf
                <input name="ip" placeholder="เพิ่ม IP" class="nx-input w-32 py-1.5 text-xs">
                <button class="rounded-lg bg-white/10 px-2.5 py-1.5 text-xs hover:bg-white/15">บล็อก</button>
            </form>
        </div>
        @if ($blocked->isEmpty())
            <div class="px-5 py-8 text-center text-[13px] text-cream/45">ยังไม่มีใครถูกบล็อก</div>
        @else
            <table class="w-full text-sm">
                <tbody>
                @foreach ($blocked as $b)
                    <tr class="border-b border-white/5 last:border-0">
                        <td class="px-5 py-2.5">
                            <div class="font-mono text-[12px]">{{ $b->ip }}</div>
                            <div class="text-[11px] text-cream/45">
                                {{ $b->manual ? 'บล็อกด้วยตนเอง' : 'ระบบบล็อก · '.$b->reason }}
                                @if ($b->hits > 0) · ปฏิเสธไปแล้ว {{ number_format($b->hits) }} ครั้ง @endif
                            </div>
                        </td>
                        <td class="px-2 py-2.5 text-right text-[11px] text-cream/45">
                            {{ $b->manual ? 'ถาวร' : ($b->expires_at?->diffForHumans() ?? '—') }}
                        </td>
                        <td class="px-5 py-2.5 text-right">
                            <form method="POST" action="{{ route('admin.security.unblock', $b) }}"
                                  onsubmit="return confirm('ปลดบล็อก {{ $b->ip }}?')">
                                @csrf @method('DELETE')
                                <button class="rounded-md bg-white/5 px-2.5 py-1 text-[12px] hover:bg-white/10">ปลด</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>

{{-- ── บันทึกเหตุการณ์ ──────────────────────────────────── --}}
<div class="nx-card overflow-hidden p-0">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-white/5 px-5 py-3.5">
        <h3 class="text-base font-semibold">บันทึกเหตุการณ์ {{ $ip ? '· '.$ip : '' }}</h3>
        <form method="GET" class="flex flex-wrap items-center gap-2">
            <input name="ip" value="{{ $ip }}" placeholder="กรอง IP" class="nx-input w-36 py-1.5 text-xs">
            <select name="reason" class="nx-input w-44 py-1.5 text-xs" onchange="this.form.submit()">
                <option value="">ทุกสาเหตุ</option>
                @foreach (['rate' => 'ยิงถี่ผิดปกติ', 'token_abuse' => 'ขอลิงก์ดูหนังรัว', 'sequential' => 'ไล่ไอดีเรียงลำดับ', 'no_referer' => 'ขอข้อมูลไม่ผ่านหน้าเว็บ', 'bot_ua' => 'บอทที่ประกาศตัว', 'admin' => 'การกระทำของแอดมิน'] as $k => $v)
                    <option value="{{ $k }}" @selected($reason === $k)>{{ $v }}</option>
                @endforeach
            </select>
            <button class="rounded-lg bg-white/10 px-3 py-1.5 text-xs hover:bg-white/15">กรอง</button>
            @if ($ip || $reason)
                <a href="{{ route('admin.security.index') }}" class="text-xs text-cream/45 hover:text-cream">ล้าง</a>
            @endif
        </form>
    </div>

    @if ($events->total() === 0)
        <div class="px-5 py-12 text-center text-cream/50">
            <div class="text-3xl">🛡️</div>
            <div class="mt-2 text-sm">ยังไม่มีเหตุการณ์ที่เข้าข่าย</div>
            <div class="mt-1 text-[12px] text-cream/35">ผู้ชมทั่วไปจะไม่ถูกบันทึกที่นี่เลย — บันทึกเฉพาะเมื่อมีพฤติกรรมเข้าเกณฑ์เท่านั้น</div>
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full min-w-[720px] text-sm">
                <thead class="bg-white/[0.03] text-[12px] text-cream/45">
                    <tr>
                        <th class="px-5 py-2.5 text-left font-medium">เวลา</th>
                        <th class="px-3 py-2.5 text-left font-medium">IP</th>
                        <th class="px-3 py-2.5 text-left font-medium">สาเหตุ</th>
                        <th class="px-3 py-2.5 text-left font-medium">เส้นทาง</th>
                        <th class="px-5 py-2.5 text-left font-medium">รายละเอียด</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($events as $e)
                    <tr class="border-t border-white/5">
                        <td class="whitespace-nowrap px-5 py-2 text-[12px] text-cream/50">{{ $e->created_at?->format('d/m H:i:s') }}</td>
                        <td class="px-3 py-2">
                            <a href="{{ route('admin.security.index', ['ip' => $e->ip]) }}" class="font-mono text-[12px] text-brand hover:underline">{{ $e->ip }}</a>
                        </td>
                        <td class="px-3 py-2">
                            <span class="rounded-full px-2 py-0.5 text-[11px] {{ $e->reason === 'admin' ? 'bg-white/5 text-cream/60' : 'bg-[#e5484d]/12 text-[#ff6b81]' }}">
                                {{ $e->reason_label }}
                            </span>
                        </td>
                        <td class="max-w-[220px] truncate px-3 py-2 text-[12px] text-cream/55" title="{{ $e->path }}">{{ $e->path }}</td>
                        <td class="px-5 py-2 text-[11px] text-cream/45">
                            @foreach (($e->meta ?? []) as $k => $v)
                                <span class="mr-2">{{ $k }}=<b class="text-cream/70">{{ is_scalar($v) ? $v : json_encode($v) }}</b></span>
                            @endforeach
                            @if ($e->user_agent)
                                <div class="mt-0.5 truncate text-cream/30" title="{{ $e->user_agent }}">{{ Str::limit($e->user_agent, 70) }}</div>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="px-5 py-4">{{ $events->links() }}</div>
    @endif
</div>
@endsection
