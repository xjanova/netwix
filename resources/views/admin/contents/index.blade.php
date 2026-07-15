@extends('layouts.admin')
@section('page-title', 'จัดการคอนเทนต์')
@section('page-subtitle', 'ภาพยนตร์ ซีรีส์ และซีรีส์แนวตั้งทั้งหมด')
@section('action')
    <form method="POST" action="{{ route('admin.contents.reset-all-thumbs') }}"
          onsubmit="return confirm('รีเซ็ตปกตอนของทุกเรื่องทั้งหมด? ระบบจะจับภาพใหม่เมื่อมีคนดูแต่ละตอน')">
        @csrf
        <button class="rounded-lg bg-white/5 px-4 py-2.5 text-sm hover:bg-white/10" title="ล้างปกตอนที่จับไว้ทั้งระบบ ให้จับใหม่">↺ รีเซ็ตปกตอนทั้งหมด</button>
    </form>
@endsection

@section('content')
@php
    $tabs = ['all' => 'ทั้งหมด', 'series' => 'ซีรี่ส์', 'movie' => 'ภาพยนตร์', 'vertical' => 'แนวตั้ง'];
    // filters carried across the type tabs + pagination
    $activeFilters = array_filter([
        'q' => $q, 'genre' => $genre, 'maturity' => $maturity, 'min_rating' => $minRating,
        'sort' => ($sort ?? 'latest') !== 'latest' ? $sort : null,
    ], fn ($v) => $v !== null && $v !== '');
@endphp

<div class="mb-4 flex flex-wrap gap-2">
    @foreach ($tabs as $key => $label)
        <a href="{{ route('admin.contents.index', array_merge($activeFilters, ['type' => $key])) }}"
           class="rounded-lg px-4 py-2 text-sm transition {{ $type === $key ? 'nx-gradient font-semibold' : 'bg-white/5 text-cream/60 hover:text-cream' }}">
            {{ $label }} <span class="opacity-60">{{ $counts[$key] }}</span>
        </a>
    @endforeach
</div>

<form method="GET" action="{{ route('admin.contents.index') }}" class="mb-5 flex flex-wrap items-center gap-2">
    <input type="hidden" name="type" value="{{ $type }}">
    <input type="text" name="q" value="{{ $q }}" placeholder="ค้นหาชื่อเรื่อง…"
           class="min-w-[180px] flex-1 rounded-lg border border-white/10 bg-surface-2 px-3.5 py-2 text-sm outline-none focus:border-brand">
    <select name="genre" onchange="this.form.submit()" class="rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-sm outline-none focus:border-brand">
        <option value="">ทุกหมวด</option>
        @foreach ($genres as $g)
            <option value="{{ $g->id }}" @selected((string) $genre === (string) $g->id)>{{ $g->name }}</option>
        @endforeach
    </select>
    <select name="maturity" onchange="this.form.submit()" class="rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-sm outline-none focus:border-brand">
        <option value="">ทุกเรทอายุ</option>
        @foreach ($maturities as $m)
            <option value="{{ $m }}" @selected($maturity === $m)>{{ $m }}</option>
        @endforeach
    </select>
    <select name="min_rating" onchange="this.form.submit()" class="rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-sm outline-none focus:border-brand">
        <option value="">ทุกคะแนน</option>
        @foreach ([9, 8, 7, 6, 5] as $r)
            <option value="{{ $r }}" @selected((string) $minRating === (string) $r)>★ {{ $r }}+ ขึ้นไป</option>
        @endforeach
    </select>
    <select name="sort" onchange="this.form.submit()" class="rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-sm outline-none focus:border-brand">
        @foreach (['latest' => 'ใหม่ล่าสุด', 'views' => 'ยอดวิวสูงสุด', 'rating' => 'คะแนนสูงสุด', 'likes' => 'ถูกใจมากสุด'] as $k => $lbl)
            <option value="{{ $k }}" @selected(($sort ?? 'latest') === $k)>เรียง: {{ $lbl }}</option>
        @endforeach
    </select>
    <button type="submit" class="rounded-lg bg-white/10 px-4 py-2 text-sm hover:bg-white/15">กรอง</button>
    @if ($activeFilters)
        <a href="{{ route('admin.contents.index', ['type' => $type]) }}" class="rounded-lg px-3 py-2 text-sm text-cream/50 hover:text-cream">ล้างตัวกรอง</a>
    @endif
</form>

<div class="nx-card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full min-w-[720px] text-sm">
            <thead class="border-b border-white/5 text-left text-xs uppercase text-cream/40">
                <tr>
                    <th class="px-4 py-3 font-medium">เรื่อง</th>
                    <th class="px-4 py-3 font-medium">ประเภท</th>
                    <th class="px-4 py-3 font-medium">หมวด</th>
                    <th class="px-4 py-3 font-medium">ตอน</th>
                    <th class="px-4 py-3 font-medium">สถิติ</th>
                    <th class="px-4 py-3 font-medium">สถานะ</th>
                    <th class="px-4 py-3 font-medium"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($contents as $c)
                    <tr class="border-b border-white/[0.04] hover:bg-white/[0.02]">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <div class="relative h-[64px] w-[44px] flex-shrink-0 overflow-hidden rounded {{ $c->poster_url ? 'cursor-zoom-in' : '' }}"
                                     style="background:{{ $c->gradient }}" @if ($c->poster_url) data-zoom-src="{{ $c->poster_url }}" @endif>
                                    @if ($c->poster_url)
                                        <img src="{{ $c->poster_url }}" alt="" loading="lazy" referrerpolicy="no-referrer"
                                             class="absolute inset-0 h-full w-full object-cover" onerror="this.style.display='none'">
                                    @endif
                                </div>
                                <div>
                                    <div class="flex items-center gap-1.5 font-medium">
                                        <span>{{ $c->title }}</span>
                                        @if (in_array($c->id, $dupIds ?? []))
                                            <span class="rounded px-1.5 py-0.5 text-[10px] font-semibold" style="background:rgba(245,197,66,.16);color:#f5c542"
                                                  title="มีคอนเทนต์ชื่อคล้ายกันในระบบ — ตรวจสอบว่าซ้ำหรือไม่">ซ้ำ?</span>
                                        @endif
                                    </div>
                                    <div class="text-xs text-cream/40">{{ $c->year }} · {{ $c->maturity }} · <span class="text-gold">★ {{ $c->rating }}</span> @if ($c->is_original)· <span class="text-brand">Original</span>@endif</div>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-cream/70">{{ ['series' => 'ซีรี่ส์', 'movie' => 'ภาพยนตร์', 'vertical' => 'แนวตั้ง'][$c->type] }}</td>
                        <td class="px-4 py-3 text-cream/60">{{ $c->genres->pluck('name')->take(2)->join(', ') }}</td>
                        <td class="px-4 py-3 text-cream/70">{{ $c->episodes_count }}</td>
                        <td class="px-4 py-3">
                            <div class="flex flex-col gap-0.5 text-xs text-cream/55">
                                <span>👁 {{ number_format($c->views) }} <span class="text-cream/35">(เว็บ {{ number_format($c->views_web) }} · แอป {{ number_format($c->views_app) }})</span></span>
                                <span>♥ {{ $c->liked_by_count }} · 💬 {{ $c->comments_count }} · ⭐ {{ $c->ratings_avg_stars ? round($c->ratings_avg_stars, 1) : '–' }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3" x-data="{ ok: {{ $c->review_ignored ? 'true' : 'false' }} }">
                            <div class="flex flex-col items-start gap-1">
                                @if ($c->suspended_at)
                                    <span class="rounded-full bg-[#e5484d]/15 px-2.5 py-0.5 text-xs font-semibold text-[#ff6b81]"
                                          title="ระบบหยุดเผยแพร่อัตโนมัติ เพราะมีคนดูไม่ได้ถึง {{ \App\Support\PlaybackHealth::THRESHOLD }} คน — ลิงก์น่าจะเสีย ควรหาลิงก์ใหม่หรือลบ">⛔ ลิงก์เสีย · หยุดเผยแพร่</span>
                                @elseif ($c->is_published)
                                    <span class="rounded-full bg-success/15 px-2.5 py-0.5 text-xs text-success">เผยแพร่</span>
                                @else
                                    <span class="rounded-full bg-white/10 px-2.5 py-0.5 text-xs text-cream/50">ฉบับร่าง</span>
                                @endif

                                @if (! $c->suspended_at && ($c->review_flagged_at || $c->review_ignored))
                                    @if ($c->review_flagged_at && ! $c->review_ignored)
                                        <span x-show="!ok" class="rounded-full bg-gold/15 px-2.5 py-0.5 text-xs font-semibold text-gold"
                                              title="มีลูกค้ากดเล่นแล้วเล่นไม่ได้ — ลิงก์น่าจะมีปัญหา ควรเช็ก/หาลิงก์ใหม่">⚠ ลิงก์มีปัญหา · รอตรวจสอบ</span>
                                    @endif
                                    <label class="flex cursor-pointer items-center gap-1.5 text-[11px] text-cream/50">
                                        <input type="checkbox" x-model="ok" class="h-3.5 w-3.5 accent-brand"
                                               @change="nxPost('{{ route('admin.contents.review-ignore', $c) }}', { ignored: ok }).catch(() => {})">
                                        <span x-text="ok ? '✓ ยืนยันลิงก์ปกติแล้ว' : 'ตรวจแล้ว ลิงก์ปกติ — ไม่ต้องเตือนอีก'"></span>
                                    </label>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-2">
                                <a href="{{ route('admin.contents.edit', $c) }}" class="rounded-md bg-white/5 px-3 py-1.5 text-xs hover:bg-white/10">แก้ไข</a>
                                <form method="POST" action="{{ route('admin.contents.destroy', $c) }}" onsubmit="return confirm('ลบ {{ $c->title }}?')">
                                    @csrf @method('DELETE')
                                    <button class="rounded-md bg-[#e5484d]/15 px-3 py-1.5 text-xs text-[#ff6b81] hover:bg-[#e5484d]/25">ลบ</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-12 text-center text-cream/45">ไม่พบคอนเทนต์ — <a href="{{ route('admin.contents.create') }}" class="text-brand">เพิ่มเรื่องใหม่</a></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-5">{{ $contents->links() }}</div>
@endsection
