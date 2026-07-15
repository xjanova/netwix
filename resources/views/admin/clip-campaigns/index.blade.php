@extends('layouts.admin')
@section('page-title', 'แคมเปญคลิปอัตโนมัติ')
@section('page-subtitle', 'ตั้งแคมเปญแยกกัน: เลือกหมวด/แนวหนัง + เวลาโพสต์ แล้วระบบตัดคลิป & โพสต์ลงเฟซบุ๊กให้เองอัตโนมัติ')

@section('action')
    <div class="flex items-center gap-2.5">
        {{-- Master kill-switch — pauses every automatic run at once. --}}
        <form method="POST" action="{{ route('admin.clip-campaigns.kill') }}" title="เปิด/ปิดการโพสต์อัตโนมัติของทุกแคมเปญพร้อมกัน">
            @csrf
            <input type="hidden" name="enabled" value="{{ $killEnabled ? '0' : '1' }}">
            <button class="flex items-center gap-2 rounded-lg border px-3.5 py-2.5 text-sm font-semibold transition {{ $killEnabled ? 'border-success/40 bg-success/15 text-success' : 'border-white/10 bg-white/5 text-cream/55 hover:text-cream' }}">
                <span class="relative inline-flex h-4 w-7 shrink-0 items-center rounded-full transition {{ $killEnabled ? 'bg-success/70' : 'bg-white/15' }}">
                    <span class="absolute h-3 w-3 rounded-full bg-white transition-all" style="{{ $killEnabled ? 'left:14px' : 'left:2px' }}"></span>
                </span>
                ระบบอัตโนมัติ · {{ $killEnabled ? 'เปิด' : 'ปิด' }}
            </button>
        </form>
        <a href="{{ route('admin.clip-campaigns.create') }}" class="nx-gradient flex items-center gap-1.5 rounded-lg px-4 py-2.5 text-sm font-semibold" style="box-shadow:0 8px 22px rgba(176,38,255,0.32)">+ สร้างแคมเปญ</a>
    </div>
@endsection

@section('content')

{{-- ── Facebook connection status ─────────────────────────────────────────── --}}
@unless ($fbConnected)
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-400/30 bg-amber-400/10 px-4 py-3 text-sm text-amber-200">
        <div class="flex items-start gap-3">
            <span class="text-lg leading-none">⚠️</span>
            <div>
                <div class="font-semibold">ยังไม่ได้เชื่อมต่อเพจ Facebook — ตอนนี้เป็น “โหมดทดสอบ”</div>
                <div class="mt-0.5 text-[13px] text-amber-200/80">
                    ระบบจะตัดคลิป + เขียนแคปชัน + จับเวลาให้ครบทุกอย่าง แต่จะ <b>ยังไม่โพสต์จริง</b> จนกว่าจะเชื่อมต่อเพจ —
                    กดปุ่มแล้วล็อกอิน Facebook ด้วยบัญชีที่เป็นแอดมินเพจ (ครั้งเดียวจบ)
                </div>
            </div>
        </div>
        <div class="flex flex-col items-stretch gap-2">
            @if ($fbSecretMissing)
                <form method="POST" action="{{ route('admin.facebook.secret') }}" class="flex items-center gap-2">
                    @csrf
                    <input type="password" name="app_secret" required pattern="[a-f0-9]{32}" autocomplete="off"
                           placeholder="วาง App Secret ของแอพ NetwixAI Poster ที่นี่"
                           class="w-72 rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-cream placeholder:text-cream/40">
                    <button class="rounded-lg border border-amber-300/40 bg-amber-400/20 px-3 py-2 text-sm font-semibold text-amber-100 hover:bg-amber-400/30">บันทึก</button>
                </form>
                @error('app_secret')<p class="text-[12px] text-[#ff6b81]">App Secret ต้องเป็นตัวอักษร a-f/ตัวเลข 32 ตัว</p>@enderror
            @endif
            <a href="{{ route('admin.facebook.connect') }}"
               class="flex items-center justify-center gap-2 rounded-lg bg-[#1877F2] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#1568d8] {{ $fbSecretMissing ? 'pointer-events-none opacity-40' : '' }}">
                <svg viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4"><path d="M24 12.07C24 5.4 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.09 10.13 24v-8.44H7.08v-3.49h3.05V9.41c0-3.02 1.79-4.7 4.53-4.7 1.31 0 2.68.24 2.68.24v2.97h-1.51c-1.49 0-1.96.93-1.96 1.89v2.26h3.33l-.53 3.49h-2.8V24C19.61 23.09 24 18.1 24 12.07z"/></svg>
                เชื่อมต่อเพจ Facebook
            </a>
        </div>
    </div>
@else
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-success/25 bg-success/10 px-4 py-2.5 text-[13px] text-success">
        <div class="flex items-center gap-2">
            <span>✅</span>
            เชื่อมต่อเพจ{{ $fbPageName ? ' "'.$fbPageName.'"' : ' Facebook' }} แล้ว — แคมเปญที่เปิดไว้จะโพสต์จริงตามเวลาที่ตั้ง
        </div>
        <form method="POST" action="{{ route('admin.facebook.disconnect') }}"
              onsubmit="return confirm('ตัดการเชื่อมต่อเพจ Facebook? แคมเปญจะหยุดโพสต์จริงจนกว่าจะเชื่อมต่อใหม่')">
            @csrf
            <button class="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-[12px] text-cream/60 hover:text-cream">ตัดการเชื่อมต่อ</button>
        </form>
    </div>
@endunless

{{-- ── Outro card burned onto the end of every clip ───────────────────────── --}}
<div class="nx-card mb-5 p-5" x-data="{ open: {{ $errors->has('clip_outro_text') || $errors->has('logo') ? 'true' : 'false' }}, ver: 0 }">
    <button type="button" @click="open = !open" class="flex w-full items-center justify-between gap-3 text-left">
        <div>
            <div class="text-sm font-semibold text-cream/85">🎬 ท้ายคลิป — โลโก้ + ข้อความชวนดูต่อ</div>
            <div class="mt-0.5 text-[12px] text-cream/45">
                ต่อท้ายทุกคลิปที่ตัด ·
                <span class="{{ $outroEnabled ? 'text-success' : 'text-cream/40' }}">{{ $outroEnabled ? 'เปิดอยู่' : 'ปิดอยู่' }}</span>
                @if ($outroEnabled) · {{ $outroSeconds }} วินาที @endif
            </div>
        </div>
        <span class="text-cream/40" x-text="open ? '▲' : '▼'"></span>
    </button>

    <div x-show="open" x-cloak class="mt-4 grid gap-5 border-t border-white/5 pt-4 md:grid-cols-[1fr_200px]">
        <form method="POST" action="{{ route('admin.clip-outro.update') }}" enctype="multipart/form-data" class="space-y-4">
            @csrf
            <label class="flex items-center gap-3 text-sm">
                <input type="checkbox" name="clip_outro_enabled" value="1" @checked($outroEnabled)
                       class="h-4 w-4 rounded border-white/20 bg-white/5 text-brand-2">
                <span>เปิดใช้งาน — ต่อท้ายทุกคลิปที่ตัดหลังจากนี้</span>
            </label>

            <div>
                <label class="mb-1 block text-[12px] text-cream/50">ข้อความ <span class="text-cream/30">(ขึ้นบรรทัดใหม่ได้ สูงสุด 4 บรรทัด)</span></label>
                <textarea name="clip_outro_text" rows="2" maxlength="200"
                          placeholder="{{ \App\Support\ClipOutro::DEFAULT_TEXT }}"
                          class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-cream placeholder:text-cream/30">{{ old('clip_outro_text', $outroText) }}</textarea>
                @error('clip_outro_text')<p class="mt-1 text-[12px] text-[#ff6b81]">{{ $message }}</p>@enderror
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-[12px] text-cream/50">แสดงกี่วินาที</label>
                    <input type="number" name="clip_outro_seconds" min="2" max="10" value="{{ old('clip_outro_seconds', $outroSeconds) }}"
                           class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-cream">
                </div>
                <div>
                    <label class="mb-1 block text-[12px] text-cream/50">โลโก้ <span class="text-cream/30">(ไม่ใส่ = โลโก้ NetWix)</span></label>
                    <input type="file" name="logo" accept="image/png,image/jpeg,image/webp"
                           class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-[12px] text-cream/70 file:mr-2 file:rounded file:border-0 file:bg-white/10 file:px-2 file:py-1 file:text-[12px] file:text-cream">
                    @error('logo')<p class="mt-1 text-[12px] text-[#ff6b81]">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button class="nx-gradient rounded-lg px-5 py-2 text-sm font-semibold" style="box-shadow:0 8px 22px rgba(176,38,255,0.32)">💾 บันทึก</button>
                @if ($outroCustomLogo)
                    <label class="flex items-center gap-2 text-[12px] text-cream/50">
                        <input type="checkbox" name="reset_logo" value="1" class="h-3.5 w-3.5 rounded border-white/20 bg-white/5">
                        กลับไปใช้โลโก้ NetWix เดิม
                    </label>
                @endif
            </div>
        </form>

        <div>
            <div class="mb-1 text-[12px] text-cream/50">ตัวอย่าง (9:16)</div>
            <img :src="'{{ route('admin.clip-outro.preview') }}?v=' + ver" alt="ตัวอย่างท้ายคลิป"
                 class="w-full rounded-lg border border-white/10 bg-black/40"
                 onerror="this.style.display='none'; this.nextElementSibling.style.display='block'">
            <p style="display:none" class="text-[11px] text-[#ff6b81]">ยังสร้างตัวอย่างไม่ได้ (ไม่พบโลโก้/ฟอนต์บนเซิร์ฟเวอร์)</p>
            <button type="button" @click="ver++" class="mt-2 w-full rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-[12px] text-cream/60 hover:text-cream">🔄 รีเฟรชตัวอย่าง</button>
        </div>
    </div>
</div>

@php
    $dayLabels = ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'];
    $typeLabels = ['movie' => 'หนัง', 'series' => 'ซีรีส์', 'anime' => 'อนิเมะ', 'vertical' => 'แนวตั้ง'];
    $pickLabels = ['trending' => 'มาแรง', 'random' => 'สุ่ม', 'newest' => 'ใหม่ล่าสุด'];
@endphp

{{-- ── Campaign cards ─────────────────────────────────────────────────────── --}}
@if ($campaigns->isEmpty())
    <div class="rounded-xl border border-dashed border-white/10 py-14 text-center">
        <div class="text-4xl">🎬</div>
        <div class="mt-3 text-sm text-cream/60">ยังไม่มีแคมเปญ</div>
        <div class="mt-1 text-[13px] text-cream/40">กด “+ สร้างแคมเปญ” เพื่อตั้งค่าหมวดหนัง เวลาโพสต์ และเริ่มให้ระบบตัดคลิป+โพสต์ให้อัตโนมัติ</div>
    </div>
@else
    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ($campaigns as $c)
            <div class="nx-card p-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <h3 class="truncate text-[15px] font-bold text-cream">{{ $c->name }}</h3>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-[10.5px] font-semibold {{ $c->is_enabled ? 'bg-success/15 text-success' : 'bg-white/10 text-cream/45' }}">
                                {{ $c->is_enabled ? 'เปิด' : 'ปิด' }}
                            </span>
                        </div>
                        <div class="mt-1 text-[12px] text-cream/45">
                            {{ $c->posts_count }} ครั้งที่รันแล้ว ·
                            {{ $c->last_run_at ? 'ล่าสุด '.$c->last_run_at->diffForHumans() : 'ยังไม่เคยรัน' }}
                        </div>
                    </div>
                    <form method="POST" action="{{ route('admin.clip-campaigns.toggle', $c) }}">
                        @csrf
                        <button title="{{ $c->is_enabled ? 'ปิดแคมเปญนี้' : 'เปิดแคมเปญนี้' }}"
                                class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition {{ $c->is_enabled ? 'bg-success/70' : 'bg-white/15' }}">
                            <span class="absolute h-4 w-4 rounded-full bg-white transition-all" style="{{ $c->is_enabled ? 'left:24px' : 'left:4px' }}"></span>
                        </button>
                    </form>
                </div>

                {{-- What it posts --}}
                <div class="mt-4 flex flex-wrap gap-1.5 text-[11.5px]">
                    <span class="rounded-md bg-white/[0.06] px-2 py-1 text-cream/70">
                        {{ $c->content ? '🎯 '.$c->content->title : ($typeLabels[$c->content_type] ?? 'ทุกประเภท') }}
                    </span>
                    @if ($c->genre)
                        <span class="rounded-md bg-white/[0.06] px-2 py-1 text-cream/70">หมวด {{ $c->genre->name }}</span>
                    @endif
                    <span class="rounded-md bg-white/[0.06] px-2 py-1 text-cream/70">{{ $pickLabels[$c->pick] ?? $c->pick }}</span>
                    <span class="rounded-md bg-white/[0.06] px-2 py-1 text-cream/70">{{ $c->aspect }} · {{ $c->duration }} วิ</span>
                    <span class="rounded-md bg-brand/15 px-2 py-1 text-brand-2">
                        {{ collect($c->targetList())->map(fn ($t) => $t === 'reels' ? 'Reels' : 'ฟีด')->implode(' + ') }}
                    </span>
                    @unless ($c->include_adult)
                        <span class="rounded-md bg-white/[0.06] px-2 py-1 text-cream/45">ไม่รวม 18+</span>
                    @endunless
                </div>

                {{-- Schedule --}}
                <div class="mt-3 space-y-1.5 text-[12px]">
                    <div class="flex items-center gap-2">
                        <span class="text-cream/40">วัน:</span>
                        <div class="flex gap-1">
                            @php $days = $c->dayList(); @endphp
                            @foreach ($dayLabels as $i => $lbl)
                                <span class="flex h-5 w-5 items-center justify-center rounded text-[10px] {{ ($days === [] || in_array($i, $days, true)) ? 'nx-gradient text-white' : 'bg-white/5 text-cream/30' }}">{{ $lbl }}</span>
                            @endforeach
                            @if ($days === [])<span class="ml-1 text-cream/40">(ทุกวัน)</span>@endif
                        </div>
                    </div>
                    <div class="flex items-start gap-2">
                        <span class="text-cream/40">เวลา:</span>
                        <div class="flex flex-wrap gap-1">
                            @forelse ($c->slotList() as $slot)
                                <span class="rounded bg-white/[0.06] px-1.5 py-0.5 text-[11px] font-medium text-cream/75">{{ $slot }}</span>
                            @empty
                                <span class="text-[#ff6b81]">ยังไม่ได้ตั้งเวลา</span>
                            @endforelse
                            <span class="text-[10px] text-cream/30">(เวลาไทย)</span>
                        </div>
                    </div>
                    @if ($c->is_enabled && ($next = $c->nextRunAt()))
                        <div class="flex items-center gap-2 text-brand-2/90">
                            <span class="text-cream/40">ถัดไป:</span>
                            <span>{{ $next->locale('th')->diffForHumans() }} · {{ $next->format('d/m') }} เวลา {{ $next->format('H:i') }} น.</span>
                        </div>
                    @endif
                </div>

                {{-- Actions --}}
                <div class="mt-4 flex items-center gap-2 border-t border-white/5 pt-3 text-[13px]">
                    <a href="{{ route('admin.clip-campaigns.edit', $c) }}" class="rounded-lg bg-white/10 px-3 py-1.5 hover:bg-white/15">แก้ไข</a>
                    <form method="POST" action="{{ route('admin.clip-campaigns.run', $c) }}"
                          onsubmit="return confirm('สั่งตัดคลิป + โพสต์ของแคมเปญนี้เดี๋ยวนี้เลยหรือไม่?')">
                        @csrf
                        <button class="rounded-lg bg-brand/20 px-3 py-1.5 text-brand-2 hover:bg-brand/30">▶ โพสต์ทันที</button>
                    </form>
                    <form method="POST" action="{{ route('admin.clip-campaigns.destroy', $c) }}" class="ml-auto"
                          onsubmit="return confirm('ลบแคมเปญ &quot;{{ $c->name }}&quot; ? (คลิปที่ตัดไว้แล้วจะยังอยู่)')">
                        @csrf @method('DELETE')
                        <button class="text-[#ff6b81]/70 hover:text-[#ff6b81]">ลบ</button>
                    </form>
                </div>
            </div>
        @endforeach
    </div>
@endif

{{-- ── Recent runs log ────────────────────────────────────────────────────── --}}
@if ($recentPosts->isNotEmpty())
    <div class="mt-8">
        <div class="mb-3 text-sm font-semibold text-cream/80">ประวัติการโพสต์ล่าสุด</div>
        <div class="overflow-x-auto rounded-xl border border-white/5">
            <table class="w-full text-left text-[13px]">
                <thead class="bg-white/[0.03] text-[11px] uppercase tracking-wide text-cream/40">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">แคมเปญ</th>
                        <th class="px-4 py-2.5 font-medium">วัน/เวลา</th>
                        <th class="px-4 py-2.5 font-medium">เรื่อง</th>
                        <th class="px-4 py-2.5 font-medium">สถานะ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    @foreach ($recentPosts as $p)
                        <tr>
                            <td class="px-4 py-2.5 text-cream/80">{{ $p->campaign?->name ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-cream/55">{{ $p->post_date?->format('d/m') }} · {{ $p->slot_time }}</td>
                            <td class="max-w-[220px] truncate px-4 py-2.5 text-cream/70">{{ $p->content?->title ?? '—' }}</td>
                            <td class="px-4 py-2.5">
                                @switch($p->status)
                                    @case('posted')
                                        @if ($p->dry_run)
                                            <span class="rounded-full bg-amber-400/15 px-2 py-0.5 text-[11px] text-amber-300">ทดสอบ (ยังไม่ต่อ FB)</span>
                                        @else
                                            <span class="rounded-full bg-success/15 px-2 py-0.5 text-[11px] text-success">โพสต์แล้ว</span>
                                        @endif
                                        @break
                                    @case('cutting')
                                        <span class="rounded-full bg-white/10 px-2 py-0.5 text-[11px] text-cream/60">กำลังตัดคลิป…</span>
                                        @break
                                    @case('pending')
                                        <span class="rounded-full bg-white/10 px-2 py-0.5 text-[11px] text-cream/50">รอคิว</span>
                                        @break
                                    @case('skipped')
                                        <span class="rounded-full bg-white/10 px-2 py-0.5 text-[11px] text-cream/45">ข้าม · {{ $p->error === 'no_title' ? 'ไม่มีหนังเข้าเงื่อนไข' : $p->error }}</span>
                                        @break
                                    @case('failed')
                                        <span class="rounded-full bg-[#ff6b81]/15 px-2 py-0.5 text-[11px] text-[#ff6b81]" title="{{ $p->error }}">ล้มเหลว</span>
                                        @break
                                    @default
                                        <span class="text-[11px] text-cream/50">{{ $p->status }}</span>
                                @endswitch
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

@endsection
