@extends('layouts.admin')
@section('page-title', 'สถิติแอป (อุปกรณ์)')
@section('page-subtitle', 'ข้อมูลเครื่องที่แอปรายงานกลับมาตอนเปิดแอป — ใช้วิเคราะห์รุ่นเครื่อง/เวอร์ชันที่ต้องซัพพอร์ต')
@section('action')<span></span>@endsection

@section('content')
<div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="nx-card p-5"><div class="text-[13px] text-cream/45">อุปกรณ์ทั้งหมด</div><div class="mt-1 text-3xl font-extrabold">{{ number_format($total) }}</div></div>
    <div class="nx-card p-5"><div class="text-[13px] text-cream/45">ใช้งานใน 7 วัน</div><div class="mt-1 text-3xl font-extrabold">{{ number_format($active7) }}</div></div>
    <div class="nx-card p-5"><div class="text-[13px] text-cream/45">ใช้งานใน 30 วัน</div><div class="mt-1 text-3xl font-extrabold">{{ number_format($active30) }}</div></div>
    <div class="nx-card p-5"><div class="text-[13px] text-cream/45">ผูกกับบัญชีสมาชิก</div><div class="mt-1 text-3xl font-extrabold">{{ number_format($linked) }}</div></div>
</div>

<div class="grid gap-6 lg:grid-cols-2">
    @foreach ([['เวอร์ชันแอป', $byVersion], ['แพลตฟอร์ม', $byPlatform], ['รุ่นเครื่อง (Top 12)', $byModel], ['เวอร์ชัน OS', $byOs]] as [$label, $rows])
        <div class="nx-card p-5">
            <h3 class="mb-4 text-base font-semibold">{{ $label }}</h3>
            @php $max = max(1, (int) ($rows->max('c') ?? 1)); @endphp
            <div class="flex flex-col gap-2.5">
                @forelse ($rows as $r)
                    <div>
                        <div class="mb-1 flex items-center justify-between text-[13px]">
                            <span class="truncate pr-3 text-cream/80">{{ $r->k }}</span>
                            <span class="shrink-0 text-cream/50">{{ number_format($r->c) }}</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-white/5">
                            <div class="nx-gradient h-full rounded-full" style="width: {{ max(3, round($r->c / $max * 100)) }}%"></div>
                        </div>
                    </div>
                @empty
                    <div class="py-6 text-center text-sm text-cream/40">ยังไม่มีข้อมูล — จะเริ่มเก็บเมื่อผู้ใช้อัปเดตแอปเวอร์ชันใหม่</div>
                @endforelse
            </div>
        </div>
    @endforeach
</div>

<div class="nx-card mt-6 p-5">
    <h3 class="mb-4 text-base font-semibold">อุปกรณ์ที่ใช้งานล่าสุด</h3>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-[13px]">
            <thead class="text-xs uppercase text-cream/40">
                <tr><th class="py-2 pr-4">รุ่นเครื่อง</th><th class="py-2 pr-4">OS</th><th class="py-2 pr-4">เวอร์ชันแอป</th><th class="py-2 pr-4">ภาษา</th><th class="py-2 pr-4">เปิดแอป (ครั้ง)</th><th class="py-2 pr-4">สมาชิก</th><th class="py-2">ล่าสุด</th></tr>
            </thead>
            <tbody class="divide-y divide-white/5">
                @forelse ($recent as $d)
                    <tr>
                        <td class="py-2 pr-4">{{ $d->device_model ?: '—' }}</td>
                        <td class="py-2 pr-4">{{ trim(($d->platform ?: '').' '.($d->os_version ?: '')) ?: '—' }}</td>
                        <td class="py-2 pr-4">{{ $d->app_version ?: '—' }}</td>
                        <td class="py-2 pr-4">{{ $d->locale ?: '—' }}</td>
                        <td class="py-2 pr-4">{{ number_format($d->launches) }}</td>
                        <td class="py-2 pr-4">{{ $d->user_id ? '#'.$d->user_id : 'ผู้เยี่ยมชม' }}</td>
                        <td class="py-2 text-cream/50">{{ $d->last_seen_at?->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-8 text-center text-cream/40">ยังไม่มีข้อมูล</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
