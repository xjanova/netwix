@extends('layouts.admin')
@section('page-title', 'SEO / ทราฟฟิก')
@section('page-subtitle', 'คีย์เวิร์ด, สุขภาพ SEO และสถิติผู้เข้าชมรายหน้า')
@section('action')<span></span>@endsection

@section('content')
{{-- KPIs --}}
<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    @foreach ($kpis as $k)
        <div class="nx-card p-5">
            <div class="text-[13px] text-cream/55">{{ $k['label'] }}</div>
            <div class="mt-1.5 text-3xl font-extrabold">{{ $k['value'] }}</div>
        </div>
    @endforeach
</div>

{{-- Traffic per day --}}
<div class="nx-card mt-6 p-5">
    <h3 class="mb-5 text-base font-semibold">ผู้เข้าชมรายวัน (30 วัน) <span class="text-[13px] font-normal text-cream/40">เฉพาะคนจริง ไม่นับบอท/เครื่องมือค้นหา</span></h3>
    <div class="flex h-48 items-end gap-[3px] pt-2">
        @foreach ($daily as $d)
            <div class="group relative flex h-full flex-1 flex-col items-center justify-end gap-1" title="{{ $d['label'] }}: {{ $d['value'] }}">
                <div class="w-full rounded-t nx-gradient" style="height:{{ $d['height'] }};min-height:2px"></div>
                @if ($loop->index % 5 === 0 || $loop->last)
                    <span class="text-[9px] text-cream/40">{{ $d['label'] }}</span>
                @else
                    <span class="text-[9px] text-transparent">·</span>
                @endif
            </div>
        @endforeach
    </div>
</div>

<div class="mt-6 grid gap-4 lg:grid-cols-2">
    {{-- Top pages --}}
    <div class="nx-card p-5">
        <h3 class="mb-4 text-base font-semibold">หน้ายอดนิยม (30 วัน)</h3>
        <div class="flex flex-col gap-3">
            @forelse ($topPages as $p)
                <div>
                    <div class="mb-1.5 flex justify-between gap-3 text-[13px]"><span class="truncate">{{ $p['label'] }}</span><span class="shrink-0 text-cream/50">{{ number_format($p['value']) }}</span></div>
                    <div class="h-2.5 overflow-hidden rounded-full bg-white/[0.07]"><div class="h-full rounded-full nx-gradient" style="width:{{ $p['pct'] }}"></div></div>
                </div>
            @empty
                <div class="py-6 text-center text-sm text-cream/45">ยังไม่มีข้อมูลผู้เข้าชม — จะเริ่มเก็บทันทีที่มีคนเข้าเว็บ</div>
            @endforelse
        </div>
    </div>

    {{-- SEO health --}}
    <div class="nx-card p-5">
        <h3 class="mb-4 text-base font-semibold">สุขภาพ SEO</h3>
        <div class="grid grid-cols-2 gap-3 text-sm">
            <div class="rounded-lg bg-white/[0.03] p-3.5">
                <div class="text-[12px] text-cream/50">หน้าที่ Google เก็บได้</div>
                <div class="mt-1 text-2xl font-bold">{{ number_format($health['indexable']) }}</div>
                <div class="text-[11px] text-cream/40">{{ number_format($health['titles']) }} เรื่อง · {{ $health['genres'] }} หมวด</div>
            </div>
            <div class="rounded-lg bg-white/[0.03] p-3.5">
                <div class="text-[12px] text-cream/50">ขาดเรื่องย่อ (description อ่อน)</div>
                <div class="mt-1 text-2xl font-bold {{ $health['missingSynopsis'] ? 'text-[#ffb457]' : 'text-success' }}">{{ number_format($health['missingSynopsis']) }}</div>
                <div class="text-[11px] text-cream/40">ควรเติมเรื่องย่อให้ครบ</div>
            </div>
            <div class="rounded-lg bg-white/[0.03] p-3.5">
                <div class="text-[12px] text-cream/50">ขาดโปสเตอร์ (OG image)</div>
                <div class="mt-1 text-2xl font-bold {{ $health['missingPoster'] ? 'text-[#ffb457]' : 'text-success' }}">{{ number_format($health['missingPoster']) }}</div>
                <div class="text-[11px] text-cream/40">ภาพตอนแชร์ลิงก์</div>
            </div>
            <div class="rounded-lg bg-white/[0.03] p-3.5">
                <div class="text-[12px] text-cream/50">ไฟล์มาตรฐาน</div>
                <div class="mt-2 flex flex-col gap-1 text-[13px]">
                    <a href="{{ url('/sitemap.xml') }}" target="_blank" class="text-[#b026ff] hover:underline">sitemap.xml ↗</a>
                    <a href="{{ url('/robots.txt') }}" target="_blank" class="text-[#b026ff] hover:underline">robots.txt ↗</a>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Keyword management --}}
<form method="POST" action="{{ route('admin.seo.update') }}" class="nx-card mt-6 p-5">
    @csrf @method('PUT')
    <h3 class="text-base font-semibold">จัดการคีย์เวิร์ด (Meta Keywords)</h3>
    <p class="mt-1 text-[13px] leading-relaxed text-cream/50">
        คีย์เวิร์ด <b>รายเรื่อง / รายหมวด</b> ระบบสร้างให้อัตโนมัติจากชื่อเรื่อง+แนว (เช่น “ชื่อเรื่อง พากย์ไทย, ชื่อเรื่อง ซับไทย”) — ไม่ต้องแก้ทีละหน้า
        ช่องด้านล่างคือ <b>ค่าเริ่มต้นทั้งเว็บ</b> และคำที่จะ <b>เติมเพิ่ม</b> ให้แต่ละชนิด (คั่นด้วยจุลภาค ,)
    </p>

    <div class="mt-4 flex flex-col gap-4">
        <label class="block">
            <span class="text-sm font-medium">คีย์เวิร์ดกลาง (ทุกหน้าที่ไม่ได้กำหนดเอง)</span>
            <textarea name="seo_keywords" rows="3" placeholder="เว้นว่าง = ใช้ชุดมาตรฐานในระบบ (ดูหนังออนไลน์ฟรี, ดูซีรี่ย์ออนไลน์ฟรี, …)"
                      class="mt-1.5 w-full rounded-lg border border-white/10 bg-white/[0.04] px-3.5 py-2.5 text-sm text-cream placeholder:text-cream/30 focus:border-[#b026ff] focus:outline-none">{{ old('seo_keywords', $keywords['seo_keywords']) }}</textarea>
        </label>

        <div class="grid gap-4 sm:grid-cols-3">
            @foreach ([
                'seo_kw_series' => 'เติมให้ ซีรี่ส์',
                'seo_kw_movie' => 'เติมให้ ภาพยนตร์',
                'seo_kw_vertical' => 'เติมให้ ซีรีส์แนวตั้ง',
            ] as $field => $label)
                <label class="block">
                    <span class="text-sm font-medium">{{ $label }}</span>
                    <textarea name="{{ $field }}" rows="3" placeholder="เช่น ซีรี่ย์เกาหลี, ซับไทย"
                              class="mt-1.5 w-full rounded-lg border border-white/10 bg-white/[0.04] px-3.5 py-2.5 text-sm text-cream placeholder:text-cream/30 focus:border-[#b026ff] focus:outline-none">{{ old($field, $keywords[$field]) }}</textarea>
                </label>
            @endforeach
        </div>
    </div>

    <div class="mt-5 flex justify-end">
        <button class="nx-gradient rounded-lg px-6 py-2.5 text-sm font-semibold" style="box-shadow:0 8px 22px rgba(176,38,255,0.32)">บันทึกคีย์เวิร์ด</button>
    </div>
</form>
@endsection
