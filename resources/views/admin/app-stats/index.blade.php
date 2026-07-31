@extends('layouts.admin')
@section('page-title', 'สถิติแอป (อุปกรณ์)')
@section('page-subtitle', 'ข้อมูลเครื่องที่แอปรายงานกลับมาตอนเปิดแอป — ใช้วิเคราะห์รุ่นเครื่อง/เวอร์ชันที่ต้องซัพพอร์ต')
@section('action')<span></span>@endsection

@section('content')
<div class="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
    <div class="nx-card p-5"><div class="text-[13px] text-cream/45">อุปกรณ์ทั้งหมด</div><div class="mt-1 text-3xl font-extrabold">{{ number_format($total) }}</div><div class="mt-0.5 text-[11px] text-cream/35">นับสะสมตั้งแต่เปิดใช้</div></div>
    <div class="nx-card p-5"><div class="text-[13px] text-cream/45">ใช้งานใน 7 วัน</div><div class="mt-1 text-3xl font-extrabold">{{ number_format($active7) }}</div></div>
    <div class="nx-card p-5"><div class="text-[13px] text-cream/45">คาดว่ายังติดตั้งอยู่</div><div class="mt-1 text-3xl font-extrabold text-success">{{ number_format($stillHere) }}</div><div class="mt-0.5 text-[11px] text-cream/35">เปิดแอปภายใน {{ $goneAfterDays }} วัน</div></div>
    <div class="nx-card p-5"><div class="text-[13px] text-cream/45">คาดว่าถอนไปแล้ว</div><div class="mt-1 text-3xl font-extrabold text-[#ff6b81]">{{ number_format($gone) }}</div><div class="mt-0.5 text-[11px] text-cream/35">เงียบเกิน {{ $goneAfterDays }} วัน</div></div>
    <div class="nx-card p-5"><div class="text-[13px] text-cream/45">ผูกกับบัญชีสมาชิก</div><div class="mt-1 text-3xl font-extrabold">{{ number_format($linked) }}</div></div>
</div>

{{-- Say plainly what this number is and is not. A real uninstall cannot be observed:
     Android tells an app nothing when it is being removed, the APK is sideloaded so there
     is no Play Console report, and push goes to FCM topics so no per-device token can come
     back UNREGISTERED. Silence is the only signal there is. --}}
<div class="mb-6 rounded-xl border border-white/10 bg-white/[0.02] px-4 py-3 text-[12.5px] leading-relaxed text-cream/55">
    <b class="text-cream/80">"คาดว่าถอนไปแล้ว" เป็นค่าประมาณ ไม่ใช่ยอดถอนจริง</b> — แอนดรอยด์ไม่แจ้งแอปตอนถูกถอน
    และแอปไม่ได้อยู่บน Play Store จึงไม่มีทางรู้ตอนถอนจริงๆ ตัวเลขนี้จึงนับจาก
    <b class="text-cream/80">เครื่องที่ไม่ได้เปิดแอปเลยเกิน {{ $goneAfterDays }} วัน</b>
    <br>เครื่องที่แค่ปิดเน็ตยาว หรือวางแอปไว้เฉยๆ ก็จะถูกนับรวมมาด้วย
    — และ<b class="text-cream/80">ถ้าเปิดแอปอีกครั้งเมื่อไหร่ ระบบย้ายกลับไปเป็น "ยังติดตั้งอยู่" ให้เองทันที</b>
    (คิดจากเวลาเปิดล่าสุดสดๆ ทุกครั้งที่เข้าหน้านี้ ไม่ได้ติดธงค้างไว้)
    <br>อนึ่ง ถอนแล้วติดตั้งใหม่จะนับเป็นเครื่องใหม่ เพราะรหัสเครื่องถูกล้างไปพร้อมกับแอป
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
                <tr><th class="py-2 pr-4">รุ่นเครื่อง</th><th class="py-2 pr-4">OS</th><th class="py-2 pr-4">เวอร์ชันแอป</th><th class="py-2 pr-4">ภาษา</th><th class="py-2 pr-4">เปิดแอป (ครั้ง)</th><th class="py-2 pr-4">สมาชิก</th><th class="py-2 pr-4">ล่าสุด</th><th class="py-2">สถานะ</th></tr>
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
                        <td class="py-2 pr-4 text-cream/50">{{ $d->last_seen_at?->diffForHumans() }}</td>
                        <td class="py-2">
                            @if ($d->isPresumedGone())
                                <span class="rounded-full bg-[#ff6b81]/12 px-2 py-0.5 text-[11.5px] font-semibold text-[#ff6b81]">คาดว่าถอนแล้ว</span>
                            @else
                                <span class="rounded-full bg-success/12 px-2 py-0.5 text-[11.5px] font-semibold text-success">ยังติดตั้งอยู่</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="py-8 text-center text-cream/40">ยังไม่มีข้อมูล</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
