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

        {{-- How long an automatic block lasts. The FIRST one is short on purpose: a block that is
             wrong should expire before anyone has to notice it. The second is long, and the third
             does not end, because by then the client has served a ban and come back to do it again —
             which is the one thing a viewer blocked by mistake never does. --}}
        <form method="POST" action="{{ route('admin.security.default-hours') }}"
              class="mt-4 flex flex-wrap items-center gap-2 border-t border-white/5 pt-4">
            @csrf
            <span class="text-[12px] text-cream/60">ครั้งแรกบล็อก</span>
            <select name="hours" class="nx-input w-28 py-1.5 text-xs">
                @foreach ([1 => '1 ชม.', 6 => '6 ชม.', 12 => '12 ชม.', 24 => '1 วัน', 72 => '3 วัน', 168 => '7 วัน', 720 => '30 วัน'] as $h => $label)
                    <option value="{{ $h }}" @selected($blockHours === $h)>{{ $label }}</option>
                @endforeach
            </select>
            <span class="text-[12px] text-cream/60">· ทำอีกครั้งที่ 2 บล็อก</span>
            <select name="repeat_hours" class="nx-input w-28 py-1.5 text-xs">
                @foreach ([24 => '1 วัน', 72 => '3 วัน', 168 => '7 วัน', 336 => '14 วัน', 720 => '30 วัน', 2160 => '90 วัน'] as $h => $label)
                    <option value="{{ $h }}" @selected($repeatHours === $h)>{{ $label }}</option>
                @endforeach
            </select>
            <span class="text-[12px] text-cream/60">· ครั้งที่ 3 <b class="text-brand">ถาวร</b></span>
            <button class="rounded-lg bg-white/10 px-3 py-1.5 text-xs hover:bg-white/15">บันทึก</button>
        </form>
    </div>
</div>

{{-- The Cloudflare secret header (App\Support\EdgeSecret). Three states: none, observing (the old
     header-presence check still decides while we watch Cloudflare's header arrive), enforcing. The
     secret itself is only ever shown right after it is made. --}}
<div class="nx-card mb-6 p-5">
    <h3 class="mb-1 text-base font-semibold">รหัสลับจาก Cloudflare <span class="text-[12px] font-normal text-cream/45">(กันคนยิงเข้าเซิร์ฟเวอร์ตรง ข้าม Cloudflare)</span></h3>
    <p class="mb-3 text-[12px] leading-relaxed text-cream/45">
        ด่านเดิมดูแค่ว่าคำขอ “มี header ของ Cloudflare” ซึ่งใครรู้ IP เซิร์ฟเวอร์ก็พิมพ์ปลอมเองได้ — รหัสลับนี้ Cloudflare เป็นคนแนบให้คนเดียว
        คำขอที่ไม่มีรหัสจึงไม่ได้มาทาง Cloudflare แน่นอน
    </p>

    @if (session('edge_secret'))
        <div class="mb-4 rounded-lg border border-brand/40 bg-brand/10 p-4 text-[13px] leading-relaxed" x-data="{ copied: false }">
            <div class="mb-2 font-semibold text-brand">รหัสลับ (แสดงครั้งเดียว — คัดลอกตอนนี้)</div>
            <div class="flex items-center gap-2">
                <code class="flex-1 break-all rounded bg-black/40 px-2 py-1.5 font-mono text-[12px]">{{ session('edge_secret') }}</code>
                <button type="button" class="rounded bg-white/10 px-2.5 py-1.5 text-[11px] hover:bg-white/15"
                        @click="navigator.clipboard.writeText(@js(session('edge_secret'))).then(() => { copied = true; setTimeout(() => copied = false, 1500) })"
                        x-text="copied ? 'คัดลอกแล้ว' : 'คัดลอก'"></button>
            </div>
            <ol class="mt-3 list-decimal space-y-1 pl-5 text-[12px] text-cream/70">
                <li>Cloudflare → netwix.online → <b>Rules → Transform Rules → Modify Request Header</b> → Create rule</li>
                <li>ตั้งชื่อ เช่น “NetWix edge secret” · เลือก <b>All incoming requests</b></li>
                <li><b>Set static</b> · Header name <code class="rounded bg-white/10 px-1">{{ $edge['header'] }}</code> · Value = รหัสด้านบน → Deploy</li>
                <li>กลับมาหน้านี้ภายในไม่กี่นาที เมื่อขึ้น “เห็นรหัสจาก Cloudflare แล้ว” จึงกด “บังคับใช้”</li>
            </ol>
        </div>
    @endif

    <div class="flex flex-wrap items-center gap-3 text-[12px]">
        <span class="text-cream/50">สถานะ:
            @if (! $edge['configured'])
                <b class="text-cream/70">ยังไม่ได้ตั้ง</b> (ใช้การตรวจแบบเดิม)
            @elseif ($edge['enforcing'])
                <b class="text-success">บังคับใช้อยู่</b>
            @else
                <b class="text-amber-300">สังเกตอยู่</b> (ยังใช้การตรวจแบบเดิม)
            @endif
        </span>
        @if ($edge['configured'])
            <span class="text-cream/50">เห็นรหัสจาก Cloudflare ล่าสุด:
                <b class="{{ $edge['seenRecently'] ? 'text-success' : 'text-cream/70' }}">{{ $edge['lastSeen'] ? \Illuminate\Support\Carbon::createFromTimestamp($edge['lastSeen'])->diffForHumans() : 'ยังไม่เคย' }}</b>
            </span>
            <span class="text-cream/50">คำขอที่ไม่มีรหัส (ชั่วโมงนี้): <b class="text-cream/80">{{ number_format($edge['missing']) }}</b></span>
        @endif
    </div>

    <form method="POST" action="{{ route('admin.security.edge') }}" class="mt-3 flex flex-wrap gap-2">
        @csrf
        <button name="action" value="generate" class="rounded-lg bg-white/10 px-3.5 py-2 text-[13px] hover:bg-white/15"
                @if ($edge['configured']) onclick="return confirm(@js('สร้างรหัสใหม่จะหยุดการบังคับใช้ และต้องแก้ค่าใน Cloudflare ตามใหม่ — ทำต่อ?'))" @endif>
            {{ $edge['configured'] ? 'สร้างรหัสใหม่' : 'สร้างรหัสลับ' }}
        </button>
        @if ($edge['configured'] && ! $edge['enforcing'])
            <button name="action" value="enforce" class="rounded-lg bg-success/15 px-3.5 py-2 text-[13px] font-semibold text-success hover:bg-success/25"
                    onclick="return confirm(@js('บังคับใช้แล้ว คำขอที่ไม่มีรหัสจะถูกปฏิเสธทั้งหมด — ยืนยัน?'))">บังคับใช้</button>
        @endif
        @if ($edge['enforcing'])
            <button name="action" value="relax" class="rounded-lg bg-white/10 px-3.5 py-2 text-[13px] hover:bg-white/15">หยุดบังคับ</button>
        @endif
        @if ($edge['configured'])
            <button name="action" value="clear" class="rounded-lg bg-[#e5484d]/15 px-3.5 py-2 text-[13px] text-[#ff6b81] hover:bg-[#e5484d]/25"
                    onclick="return confirm(@js('ลบรหัสลับ? ด่านจะกลับไปใช้การตรวจแบบเดิม'))">ลบรหัส</button>
        @endif
    </form>
</div>

{{-- IPv6 note: automatic blocks are written as a /64 — the subscriber, not one of the addresses a
     Thai carrier rotates through them every few hours. Blocking a single IPv6 address is evaded by
     reconnecting and fills the list with dead entries. --}}
<div class="mb-6 rounded-xl border border-white/8 bg-white/[0.03] px-4 py-3 text-[12px] leading-relaxed text-cream/55">
    <b class="text-cream/75">วิธีที่ระบบเลือกบล็อก:</b>
    IPv4 บล็อกเลขเดียว · IPv6 บล็อกทั้งช่วง <code class="rounded bg-white/10 px-1">/64</code> (คือ “บ้านหนึ่งหลัง”
    เพราะค่ายมือถือไทยหมุนเลขท้ายให้ผู้ใช้คนเดิมตลอด — บล็อกทีละเลขจึงหลบง่ายและลิสต์จะเต็มไปด้วยขยะ)
    · <b class="text-cream/75">ไม่บล็อกเครื่องที่มีสมาชิกล็อกอินอยู่</b> และไม่บล็อกเซิร์ฟเวอร์เราเอง
    <br>
    <b class="text-cream/75">โทษเพิ่มขึ้นเมื่อเป็นคนเดิม:</b>
    ครั้งแรก {{ $blockHours }} ชม. → ครั้งที่ 2 {{ intdiv($repeatHours, 24) ?: $repeatHours }} {{ $repeatHours >= 24 ? 'วัน' : 'ชม.' }} → ครั้งที่ 3 เป็นต้นไป <b class="text-brand">ถาวร</b>
    · นับเพิ่มเฉพาะตอน<b class="text-cream/75">พ้นโทษแล้วกลับมาทำอีก</b> เท่านั้น (ยิงรัวระหว่างโดนแบนอยู่ไม่นับเพิ่ม)
    · ประวัติเก่ากว่า {{ \App\Support\ScrapeGuard::offenceMemoryDays() }} วันถือว่าล้างแล้ว เริ่มนับหนึ่งใหม่
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
                <select name="hours" class="nx-input w-24 py-1.5 text-xs" title="นานแค่ไหน">
                    <option value="6">6 ชม.</option>
                    <option value="24">1 วัน</option>
                    <option value="168">7 วัน</option>
                    <option value="720">30 วัน</option>
                    <option value="0">ถาวร</option>
                </select>
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
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="font-mono text-[12px]">{{ $b->ip }}</span>
                                {{-- Which time this is. Shown on the row and not only in the history
                                     section, because "ปลด" on a third-offence row is a different
                                     decision from "ปลด" on a first, and the admin is deciding here. --}}
                                @if (($offences[$b->ip]->offences ?? 0) >= 2)
                                    <span class="rounded bg-brand/15 px-1.5 py-0.5 text-[10px] font-semibold text-brand">
                                        ทำผิดครั้งที่ {{ $offences[$b->ip]->offences }}
                                    </span>
                                @endif
                            </div>
                            <div class="text-[11px] text-cream/45">
                                {{ $b->manual ? 'บล็อกด้วยตนเอง' : 'ระบบบล็อก · '.$b->reason }}
                                @if ($b->hits > 0) · ปฏิเสธไปแล้ว {{ number_format($b->hits) }} ครั้ง @endif
                            </div>
                        </td>
                        <td class="px-2 py-2.5 text-right text-[11px] {{ $b->expires_at ? 'text-cream/45' : 'text-brand' }}">
                            {{ $b->expires_at ? 'หมดอายุ '.$b->expires_at->diffForHumans() : 'ถาวร' }}
                        </td>
                        <td class="px-2 py-2.5 text-right">
                            {{-- Change how long, without lifting and re-adding: the hit count and the
                                 block's history are the evidence for deciding, and re-adding loses both. --}}
                            <form method="POST" action="{{ route('admin.security.duration', $b) }}" class="inline-flex items-center gap-1">
                                @csrf
                                <select name="hours" class="nx-input w-[86px] py-1 text-[11px]" onchange="this.form.submit()">
                                    <option value="" selected disabled>แก้เวลา…</option>
                                    <option value="1">1 ชม.</option>
                                    <option value="6">6 ชม.</option>
                                    <option value="24">1 วัน</option>
                                    <option value="168">7 วัน</option>
                                    <option value="720">30 วัน</option>
                                    <option value="0">ถาวร</option>
                                </select>
                            </form>
                        </td>
                        <td class="px-5 py-2.5 text-right">
                            <form method="POST" action="{{ route('admin.security.unblock', $b) }}"
                                  onsubmit="return confirm(@js('ปลดบล็อก '.$b->ip.'?'))">
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

{{-- ── ประวัติผู้ทำผิดซ้ำ ────────────────────────────────
     Kept separate from the block list above because it answers a different question. That list is
     "who is refused right now"; this is "who has been refused before", which is the only thing an
     escalating penalty can be based on — a block row is deleted when it is lifted and forgotten when
     it expires, so by the time a repeat offender comes back there is nothing left of the first ban.
     It is also where a mistake gets undone: ปลด lifts today's block, ล้างประวัติ forgives the ones
     already served so the next strike starts from the first rung again. --}}
@if ($repeatOffenders->isNotEmpty())
    <div class="mb-6">
        <div class="nx-card overflow-hidden p-0">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-white/5 px-5 py-3.5">
                <h3 class="text-base font-semibold">ประวัติผู้ทำผิดซ้ำ</h3>
                <span class="text-[11px] text-cream/40">พ้นโทษแล้วกลับมาทำอีก — ครั้งถัดไปโทษหนักขึ้น</span>
            </div>
            <table class="w-full text-sm">
                <tbody>
                @foreach ($repeatOffenders as $o)
                    <tr class="border-b border-white/5 last:border-0">
                        <td class="px-5 py-2.5">
                            <div class="font-mono text-[12px]">{{ $o->ip }}</div>
                            <div class="text-[11px] text-cream/45">
                                ล่าสุด {{ $o->last_reason ? \App\Support\ScrapeGuard::label($o->last_reason) : '—' }}
                                @if ($o->first_at) · ครั้งแรก {{ $o->first_at->diffForHumans() }} @endif
                            </div>
                        </td>
                        <td class="px-2 py-2.5 text-right">
                            <span class="rounded bg-brand/15 px-1.5 py-0.5 text-[11px] font-semibold text-brand">
                                {{ $o->offences }} ครั้ง
                            </span>
                        </td>
                        <td class="px-2 py-2.5 text-right text-[11px] text-cream/45">
                            {{-- Block form, not the inline one: the inline directive mis-compiles a
                                 nested-call expression into unclosed PHP and swallows the rest of the
                                 markup. That shipped two 500s on 2026-07-28. --}}
                            @php
                                $next = \App\Support\ScrapeGuard::sentenceHours($o->offences + 1);
                            @endphp
                            ครั้งหน้า: {{ $next === null ? 'ถาวร' : ($next >= 24 ? intdiv($next, 24).' วัน' : $next.' ชม.') }}
                        </td>
                        <td class="px-5 py-2.5 text-right">
                            <form method="POST" action="{{ route('admin.security.forgive', $o) }}"
                                  onsubmit="return confirm(@js('ล้างประวัติของ '.$o->ip.'? ครั้งต่อไปจะเริ่มนับใหม่'))">
                                @csrf @method('DELETE')
                                <button class="rounded-md bg-white/5 px-2.5 py-1 text-[12px] hover:bg-white/10">ล้างประวัติ</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

{{-- ── บันทึกเหตุการณ์ ──────────────────────────────────── --}}
<div class="nx-card overflow-hidden p-0">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-white/5 px-5 py-3.5">
        <h3 class="text-base font-semibold">บันทึกเหตุการณ์ {{ $ip ? '· '.$ip : '' }}</h3>
        <form method="GET" class="flex flex-wrap items-center gap-2">
            <input name="ip" value="{{ $ip }}" placeholder="กรอง IP" class="nx-input w-36 py-1.5 text-xs">
            <select name="reason" class="nx-input w-44 py-1.5 text-xs" onchange="this.form.submit()">
                <option value="">ทุกสาเหตุ</option>
                {{-- From the rule catalogue, not a copy of it. This list was hand-written and had
                     already gone stale: it offered no way to filter for `probe`, which by then was
                     174 of the events on the page. --}}
                @foreach (\App\Support\ScrapeGuard::RULES as $k => $rule)
                    <option value="{{ $k }}" @selected($reason === $k)>{{ $rule[0] }}</option>
                @endforeach
                <option value="admin" @selected($reason === 'admin')>การกระทำของแอดมิน</option>
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
