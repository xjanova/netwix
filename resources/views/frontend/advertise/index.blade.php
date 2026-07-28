@extends('layouts.app')
@section('title', 'ลงโฆษณากับ NetWix')
@section('meta_robots', 'noindex,follow')

@section('content')
<div class="pt-16 pb-14">
    <div class="mx-auto max-w-6xl px-4">

        {{-- Hero --}}
        <div class="nx-card relative overflow-hidden p-8 sm:p-10">
            <div class="absolute -right-16 -top-16 h-64 w-64 rounded-full opacity-40 blur-3xl" style="background:radial-gradient(circle,#b026ff,transparent 70%)"></div>
            <div class="absolute -bottom-20 -left-10 h-56 w-56 rounded-full opacity-30 blur-3xl" style="background:radial-gradient(circle,#ff2d55,transparent 70%)"></div>
            <div class="relative">
                <div class="mb-2 inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-1 text-[12px] text-cream/60">
                    <span class="h-1.5 w-1.5 rounded-full bg-success"></span> เปิดรับลงโฆษณา
                </div>
                <h1 class="text-3xl font-extrabold leading-tight sm:text-4xl">ลงโฆษณาบน <span class="nx-gradient bg-clip-text text-transparent">NetWix</span></h1>
                <p class="mt-3 max-w-2xl text-[15px] leading-relaxed text-cream/60">
                    เลือกตำแหน่ง เลือกจำนวนวัน อัพโหลดแบนเนอร์เอง แล้วชำระด้วย USDT/USDC (BEP20)
                    <br class="hidden sm:block">ตัวเลขคนเห็นด้านล่างคำนวณจาก<b class="text-cream/80">ทราฟฟิกจริง</b>ของเว็บและแอปย้อนหลัง 30 วัน ไม่ใช่ตัวเลขตั้งขึ้นมา
                </p>
            </div>
        </div>

        {{-- Placements --}}
        <h2 class="mb-4 mt-9 text-xl font-bold">ตำแหน่งที่เปิดขาย</h2>

        @if ($cards->isEmpty())
            <div class="nx-card p-10 text-center text-cream/55">
                <div class="text-4xl">📢</div>
                <div class="mt-3">ยังไม่เปิดขายตำแหน่งโฆษณาในตอนนี้</div>
            </div>
        @else
            <div class="grid gap-5 lg:grid-cols-2">
                @foreach ($cards as $c)
                    @php($p = $c['placement'])
                    <div class="nx-card flex flex-col p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="text-[17px] font-bold">{{ $p->name }}</div>
                                @if ($p->blurb)
                                    <div class="mt-0.5 text-[13px] text-cream/50">{{ $p->blurb }}</div>
                                @endif
                            </div>
                            <div class="shrink-0 text-right">
                                <div class="text-2xl font-extrabold text-cream">${{ rtrim(rtrim(number_format((float) $p->price_usdt_per_day, 2), '0'), '.') }}</div>
                                <div class="text-[12px] text-cream/45">ต่อวัน</div>
                            </div>
                        </div>

                        {{-- Where it appears: a tiny wireframe of the page with the slot lit up --}}
                        <div class="mt-4 rounded-xl border border-white/5 bg-black/20 p-3">
                            <div class="mx-auto w-full max-w-[260px] space-y-1.5">
                                <div class="h-2.5 rounded {{ $p->slot === 'header' ? 'nx-gradient' : 'bg-white/10' }}"></div>
                                <div class="grid grid-cols-4 gap-1.5">
                                    @for ($i = 0; $i < 4; $i++)
                                        <div class="h-8 rounded bg-white/[0.07]"></div>
                                    @endfor
                                </div>
                                <div class="h-2.5 rounded {{ $p->slot === 'infeed' ? 'nx-gradient' : 'bg-white/[0.07]' }}"></div>
                                <div class="grid grid-cols-4 gap-1.5">
                                    @for ($i = 0; $i < 4; $i++)
                                        <div class="h-8 rounded bg-white/[0.07]"></div>
                                    @endfor
                                </div>
                                <div class="h-2.5 rounded {{ $p->slot === 'footer' ? 'nx-gradient' : 'bg-white/10' }}"></div>
                            </div>
                            <div class="mt-2 text-center text-[11px] text-cream/35">ขนาดแบนเนอร์ {{ $p->width }}×{{ $p->height }} px</div>
                        </div>

                        {{-- Reach, from real traffic --}}
                        <div class="mt-4 grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-white/5" style="background:rgba(255,255,255,0.06)">
                            <div class="bg-panel-2 p-3">
                                <div class="text-[11.5px] text-cream/45">คนเห็นโดยประมาณ / วัน</div>
                                <div class="mt-1 text-xl font-bold">{{ number_format($c['reach']['per_day']) }}</div>
                            </div>
                            <div class="bg-panel-2 p-3">
                                <div class="text-[11.5px] text-cream/45">ต่อเดือน</div>
                                <div class="mt-1 text-xl font-bold">{{ number_format($c['reach']['per_month']) }}</div>
                            </div>
                        </div>

                        {{-- Queue pressure --}}
                        <div class="mt-4">
                            <div class="mb-1 flex items-center justify-between text-[12.5px]">
                                <span class="text-cream/55">ความหนาแน่นของคิว (30 วันข้างหน้า)</span>
                                <span class="font-semibold {{ $c['pressure'] >= 80 ? 'text-brand' : ($c['pressure'] >= 50 ? 'text-gold' : 'text-success') }}">
                                    {{ $c['pressure'] }}% {{ $c['pressure'] >= 80 ? 'แน่นมาก' : ($c['pressure'] >= 50 ? 'ค่อนข้างแน่น' : 'ว่างเยอะ') }}
                                </span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-white/10">
                                <div class="h-full rounded-full {{ $c['pressure'] >= 80 ? 'bg-brand' : ($c['pressure'] >= 50 ? 'bg-gold' : 'bg-success') }}"
                                     style="width: {{ max(2, $c['pressure']) }}%"></div>
                            </div>
                            <div class="mt-1 text-[11.5px] text-cream/35">ตำแหน่งนี้แสดงหมุนเวียนได้สูงสุด {{ $p->max_concurrent }} เจ้าพร้อมกัน</div>
                        </div>

                        {{-- 30-day availability strip (anonymous: only how full, never who) --}}
                        <div class="mt-4">
                            <div class="mb-1.5 text-[12.5px] text-cream/55">ปฏิทินว่าง 30 วัน</div>
                            <div class="flex flex-wrap gap-1">
                                @foreach ($c['calendar'] as $d)
                                    <div title="{{ $d['date'] }} — จองแล้ว {{ $d['taken'] }}/{{ $d['cap'] }}"
                                         class="h-5 w-5 rounded text-center text-[9px] leading-5
                                            {{ $d['full'] ? 'bg-brand/70 text-white' : ($d['taken'] > 0 ? 'bg-gold/50 text-black/70' : 'bg-white/[0.07] text-cream/35') }}">
                                        {{ (int) substr($d['date'], 8, 2) }}
                                    </div>
                                @endforeach
                            </div>
                            <div class="mt-1.5 flex flex-wrap gap-3 text-[11px] text-cream/40">
                                <span><span class="mr-1 inline-block h-2.5 w-2.5 rounded bg-white/[0.07] align-middle"></span>ว่าง</span>
                                <span><span class="mr-1 inline-block h-2.5 w-2.5 rounded bg-gold/50 align-middle"></span>มีคนจองบางส่วน</span>
                                <span><span class="mr-1 inline-block h-2.5 w-2.5 rounded bg-brand/70 align-middle"></span>เต็ม</span>
                            </div>
                        </div>

                        <a href="{{ route('advertise.create', $p) }}"
                           class="nx-gradient mt-5 block rounded-lg py-3 text-center text-[14px] font-bold text-white">
                            เลือกตำแหน่งนี้
                        </a>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Rules --}}
        <h2 class="mb-3 mt-10 text-xl font-bold">กติกาการลงโฆษณา</h2>
        <div class="nx-card p-5">{!! $rules !!}</div>

        @if ($mine->isNotEmpty())
            <div class="mt-8 flex items-center justify-between">
                <h2 class="text-xl font-bold">โฆษณาของคุณ</h2>
                <a href="{{ route('advertise.mine') }}" class="text-[13px] text-cream/55 hover:text-cream">ดูทั้งหมด →</a>
            </div>
            <div class="mt-3 space-y-2">
                @foreach ($mine as $b)
                    <a href="{{ $b->status === 'awaiting_payment' ? route('advertise.checkout', $b) : route('advertise.mine') }}"
                       class="nx-card flex flex-wrap items-center gap-3 p-3.5 hover:bg-white/[0.03]">
                        @if ($b->image_src)
                            <img src="{{ $b->image_src }}" alt="" class="h-10 w-28 shrink-0 rounded object-cover ring-1 ring-white/10">
                        @endif
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-[14px] font-semibold">{{ $b->title ?: $b->reference }}</div>
                            <div class="text-[12px] text-cream/45">{{ $b->placement?->name }} · {{ $b->starts_at?->format('d/m/y') }}–{{ $b->ends_at?->format('d/m/y') }}</div>
                        </div>
                        <span class="shrink-0 rounded-full bg-white/5 px-2.5 py-1 text-[11.5px] text-cream/60">{{ $b->status_label }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection
