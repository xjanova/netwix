@extends('layouts.admin')
@section('page-title', 'ดูดคลิปมาเก็บ & พื้นที่จัดเก็บ')
@section('page-subtitle', 'มอนิเตอร์การดาวน์โหลดรายแหล่ง สั่งเริ่ม–พัก–หยุดได้ พร้อมประมาณการพื้นที่และค่าใช้จ่ายจากขนาดไฟล์จริง')
@section('action')<a href="{{ route('admin.import.index') }}" class="rounded-lg bg-white/5 px-4 py-2.5 text-sm hover:bg-white/10">→ นำเข้าหนัง</a>@endsection

@php
    $s = $summary;
    $t = $totals;
    $usedGb = $s['used'] / 1e9;
    $freeGb = $t['disk_free'] / 1e9;
@endphp

@section('content')
<div x-data="mirrorMonitor(@js(['rows' => $rows, 'totals' => $totals, 'switch' => $switchOn, 'usdPerTb' => $usdPerTb, 'urls' => [
        'monitor' => route('admin.storage.monitor'),
        'switch' => route('admin.storage.switch'),
        'probe' => route('admin.storage.probe'),
        'control' => route('admin.storage.control'),
    ]]))" x-init="start()">

    {{-- ── Master switch ─────────────────────────────────────────────────────────
         Deliberately the first thing on the page. Every control below it is inert while this is
         off, and saying so plainly is better than letting an admin press เริ่ม and wonder why
         nothing moves. --}}
    <div class="nx-card p-5" :class="on ? 'border-success/30' : 'border-white/10'">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-start gap-3.5">
                <span class="relative mt-1 flex h-3 w-3">
                    <span x-show="on" class="absolute inline-flex h-full w-full animate-ping rounded-full bg-success opacity-60"></span>
                    <span class="relative inline-flex h-3 w-3 rounded-full" :class="on ? 'bg-success' : 'bg-white/25'"></span>
                </span>
                <div>
                    <div class="text-sm font-semibold" x-text="on ? 'ระบบดูดคลิป: เปิดอยู่' : 'ระบบดูดคลิป: ปิดอยู่ — ยังไม่มีการดาวน์โหลดใดๆ'"></div>
                    <div class="mt-1 max-w-2xl text-xs leading-relaxed text-cream/50">
                        <template x-if="!on">
                            <span>สวิตช์นี้คือคันโยกใหญ่ ปิดไว้ = ตัวดาวน์โหลดจะไม่แตะเน็ตเลยแม้จะกดเริ่มรายแหล่งไว้ก็ตาม
                                  เปิดเมื่อพร้อมจ่ายพื้นที่/ค่าเก็บไฟล์ตามตัวเลขด้านล่าง</span>
                        </template>
                        <template x-if="on">
                            <span>เปิดแล้ว — แต่จะดาวน์โหลดจริงเฉพาะแหล่งที่กด “เริ่มดูด” และต้องมีตัวรัน
                                  <code class="rounded bg-white/10 px-1">php artisan netwix:mirror-run</code> ทำงานอยู่</span>
                        </template>
                    </div>
                </div>
            </div>
            <button type="button" @click="toggle()" :disabled="busy"
                    class="rounded-lg px-5 py-2.5 text-sm font-semibold transition disabled:opacity-40"
                    :class="on ? 'bg-white/8 hover:bg-white/12' : 'bg-primary text-white hover:opacity-90'"
                    x-text="on ? 'ปิดสวิตช์' : 'เปิดสวิตช์'"></button>
        </div>
    </div>

    {{-- ── Headline numbers ───────────────────────────────────────────────────── --}}
    <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="nx-card relative overflow-hidden p-5">
            <div class="absolute -right-8 -top-8 h-24 w-24 rounded-full opacity-50 blur-2xl" style="background:#ff2d55"></div>
            <div class="relative text-[13px] text-cream/55">เก็บไว้บนเซิร์ฟเวอร์แล้ว</div>
            <div class="relative mt-1.5 text-2xl font-extrabold" x-text="fmtInt(totals.mirrored) + ' ตอน'"></div>
            <div class="relative mt-0.5 text-xs text-cream/45" x-text="gb(totals.bytes) + ' GB · จากทั้งหมด ' + fmtInt(totals.episodes) + ' ตอน'"></div>
        </div>
        <div class="nx-card relative overflow-hidden p-5">
            <div class="absolute -right-8 -top-8 h-24 w-24 rounded-full opacity-50 blur-2xl" style="background:#b026ff"></div>
            <div class="relative text-[13px] text-cream/55">ดาวน์โหลดได้ตอนนี้</div>
            <div class="relative mt-1.5 text-2xl font-extrabold" x-text="fmtInt(totals.ready_episodes) + ' ตอน'"></div>
            <div class="relative mt-0.5 text-xs text-cream/45">แหล่งที่ให้ไฟล์ MP4 ตรงๆ</div>
        </div>
        <div class="nx-card relative overflow-hidden p-5">
            <div class="absolute -right-8 -top-8 h-24 w-24 rounded-full opacity-50 blur-2xl" style="background:#8b8b8b"></div>
            <div class="relative text-[13px] text-cream/55">ยังดาวน์โหลดไม่ได้</div>
            <div class="relative mt-1.5 text-2xl font-extrabold" x-text="fmtInt(totals.blocked_episodes) + ' ตอน'"></div>
            <div class="relative mt-0.5 text-xs text-cream/45">เป็นสตรีม HLS — ต้องต่อ ffmpeg ก่อน</div>
        </div>
        <div class="nx-card relative overflow-hidden p-5">
            <div class="absolute -right-8 -top-8 h-24 w-24 rounded-full opacity-50 blur-2xl" style="background:#00d68f"></div>
            <div class="relative text-[13px] text-cream/55">ดิสก์เซิร์ฟเวอร์ว่าง</div>
            <div class="relative mt-1.5 text-2xl font-extrabold" x-text="gb(totals.disk_free) + ' GB'"></div>
            <div class="relative mt-0.5 text-xs text-cream/45" x-text="'ใช้ได้จริง ' + gb(totals.disk_usable) + ' GB (กันไว้ 15 GB ให้ระบบ)'"></div>
        </div>
    </div>

    {{-- ── Projection ─────────────────────────────────────────────────────────────
         Built only from sources whose size we have actually measured, and it always says how many
         it could not include. A projection that quietly covers 2 of 9 sources and prints one
         confident total is worse than no projection at all. --}}
    <div class="nx-card mt-4 p-5">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-base font-semibold">📊 ถ้าเก็บไฟล์เองทั้งหมด จะใช้พื้นที่และเงินเท่าไร</h3>
            <button type="button" @click="probeAll()" :disabled="busy"
                    class="rounded-lg bg-white/5 px-3.5 py-2 text-xs hover:bg-white/10 disabled:opacity-40">
                <span x-text="busy ? 'กำลังวัด…' : 'วัดขนาดไฟล์จริงทุกแหล่ง'"></span>
            </button>
        </div>

        <template x-if="totals.projected_from === 0">
            <div class="rounded-xl border border-white/8 bg-white/[0.03] p-4 text-sm text-cream/60">
                ยังไม่ได้วัดขนาดไฟล์จริงของแหล่งไหนเลย จึงยังคำนวณไม่ได้ —
                กด “วัดขนาดไฟล์จริงทุกแหล่ง” ระบบจะสุ่มตอนละ 3 เรื่องต่อแหล่ง แล้วอ่านขนาดจริงจากต้นทาง (ไม่โหลดไฟล์ลงมา)
            </div>
        </template>

        <template x-if="totals.projected_from > 0">
            <div class="grid gap-4 md:grid-cols-3">
                <div class="rounded-xl border border-white/8 bg-white/[0.03] p-4">
                    <div class="text-xs text-cream/50">พื้นที่ที่ต้องใช้</div>
                    <div class="mt-1 text-2xl font-extrabold" x-text="tb(totals.projected) + ' TB'"></div>
                    <div class="mt-1 text-xs text-cream/45" x-text="'เทียบเท่า ' + fmtInt(Math.round(totals.projected/1e9)) + ' GB'"></div>
                </div>
                <div class="rounded-xl border border-white/8 bg-white/[0.03] p-4">
                    <div class="text-xs text-cream/50">ค่าเก็บไฟล์ Backblaze B2</div>
                    <div class="mt-1 text-2xl font-extrabold" x-text="'$' + usd(totals.projected) + ' /เดือน'"></div>
                    <div class="mt-1 text-xs text-cream/45" x-text="'$' + usdPerTb + '/TB/เดือน · ค่าส่งออกฟรีเมื่อผ่าน Cloudflare'"></div>
                </div>
                <div class="rounded-xl border p-4"
                     :class="totals.projected > totals.disk_usable ? 'border-[#ff6b81]/25 bg-[#ff6b81]/[0.06]' : 'border-success/25 bg-success/[0.06]'">
                    <div class="text-xs text-cream/50">ดิสก์เซิร์ฟเวอร์รับไหวไหม</div>
                    <div class="mt-1 text-2xl font-extrabold"
                         x-text="totals.projected > totals.disk_usable ? 'ไม่ไหว' : 'ไหว'"></div>
                    <div class="mt-1 text-xs text-cream/45"
                         x-text="totals.projected > totals.disk_usable
                            ? 'ต้องใช้ที่เก็บนอกเครื่อง (B2) หรือเลือกเก็บบางแหล่ง'
                            : 'เก็บลงดิสก์เครื่องนี้ได้เลย ไม่ต้องเสียค่าเก็บ'"></div>
                </div>
            </div>
        </template>

        <div class="mt-3 text-xs text-cream/45">
            <template x-if="totals.projected_missing > 0">
                <span>⚠ ตัวเลขนี้ยังไม่รวม <b class="text-cream/70" x-text="totals.projected_missing"></b> แหล่ง
                      (<span x-text="fmtInt(totals.projected_missing_episodes)"></span> ตอน) ที่ยังไม่ได้วัดขนาด —
                      ของจริงจะมากกว่านี้</span>
            </template>
            <template x-if="totals.projected_missing === 0 && totals.projected_from > 0">
                <span>วัดครบทุกแหล่งแล้ว (<span x-text="totals.projected_from"></span> แหล่ง)</span>
            </template>
        </div>
    </div>

    {{-- ── Per-source monitor ─────────────────────────────────────────────────── --}}
    <div class="nx-card mt-4 overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-white/5 px-5 py-4">
            <div class="text-base font-semibold">มอนิเตอร์รายแหล่ง</div>
            <div class="flex items-center gap-2 text-xs text-cream/40">
                <span class="h-1.5 w-1.5 rounded-full bg-success"></span>
                <span x-text="'อัปเดตล่าสุด ' + (at || '—')"></span>
            </div>
        </div>

        <div class="divide-y divide-white/[0.05]">
            <template x-for="r in rows" :key="r.source">
                <div class="p-5" :class="r.job && (r.job.state === 'running') ? 'bg-success/[0.03]' : ''">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        {{-- name + capability --}}
                        <div class="min-w-[220px] flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold" x-text="r.label"></span>
                                <span class="rounded-md px-2 py-0.5 text-[11px]"
                                      :class="r.can_download ? 'bg-success/15 text-success' : 'bg-white/8 text-cream/45'"
                                      x-text="r.kind === 'mp4' ? 'MP4 · โหลดได้' : 'HLS · ยังโหลดไม่ได้'"></span>
                                <template x-if="r.job">
                                    <span class="rounded-md px-2 py-0.5 text-[11px]" :class="stateClass(r.job.state)"
                                          x-text="stateLabel(r.job.state)"></span>
                                </template>
                            </div>
                            <div class="mt-1 text-xs text-cream/45">
                                <span x-text="fmtInt(r.titles) + ' เรื่อง · ' + fmtInt(r.episodes) + ' ตอน'"></span>
                                <template x-if="r.refd < r.episodes">
                                    <span x-text="' · มีลิงก์ต้นทาง ' + fmtInt(r.refd) + ' ตอน'"></span>
                                </template>
                            </div>
                            <template x-if="r.blocked_reason">
                                <div class="mt-1.5 text-xs text-cream/40" x-text="'↳ ' + r.blocked_reason"></div>
                            </template>
                        </div>

                        {{-- measured size --}}
                        <div class="min-w-[170px]">
                            <div class="text-[11px] uppercase tracking-wide text-cream/35">ขนาดต่อตอน</div>
                            <template x-if="r.avg">
                                <div>
                                    <div class="mt-0.5 text-lg font-bold" x-text="mb(r.avg) + ' MB'"></div>
                                    <div class="text-[11px] text-cream/40"
                                         x-text="(r.avg_from === 'stored' ? 'จากไฟล์ที่เก็บจริง ' : 'จากการวัด ') + r.avg_samples + ' ตอน'"></div>
                                    <div class="text-[11px] text-cream/55"
                                         x-text="'ทั้งแหล่ง ≈ ' + tb(r.projected) + ' TB · $' + usd(r.projected) + '/ด.'"></div>
                                </div>
                            </template>
                            <template x-if="!r.avg">
                                <div class="mt-0.5">
                                    <div class="text-sm text-cream/40">ยังไม่ได้วัด</div>
                                    <button type="button" @click="probe(r.source)" :disabled="busy"
                                            class="mt-1 rounded-md bg-white/5 px-2.5 py-1 text-[11px] hover:bg-white/10 disabled:opacity-40">
                                        วัดขนาดจริง
                                    </button>
                                </div>
                            </template>
                        </div>

                        {{-- progress --}}
                        <div class="min-w-[200px] flex-1">
                            <div class="mb-1 flex justify-between text-xs">
                                <span class="text-cream/50">เก็บแล้ว</span>
                                <span x-text="fmtInt(r.mirrored) + ' / ' + fmtInt(r.refd) + ' ตอน'"></span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-white/[0.07]">
                                <div class="h-full rounded-full transition-all"
                                     :style="'width:' + pct(r.mirrored, r.refd) + '%;background:' + (r.can_download ? '#ff2d55' : '#4a4a55')"></div>
                            </div>
                            <div class="mt-1 text-[11px] text-cream/40">
                                <span x-text="gb(r.bytes) + ' GB'"></span>
                                <template x-if="r.job && (r.job.done || r.job.fail)">
                                    <span x-text="' · รอบนี้สำเร็จ ' + r.job.done + ' ล้มเหลว ' + r.job.fail"></span>
                                </template>
                            </div>
                            <template x-if="r.job && r.job.last_title">
                                <div class="mt-1 truncate text-[11px] text-cream/45" x-text="'ล่าสุด: ' + r.job.last_title"></div>
                            </template>
                            <template x-if="r.job && r.job.last_error">
                                <div class="mt-1 truncate text-[11px] text-[#ff6b81]" x-text="'ผิดพลาด: ' + r.job.last_error"></div>
                            </template>
                        </div>

                        {{-- controls --}}
                        <div class="flex min-w-[190px] flex-col items-end gap-2">
                            <template x-if="r.can_download">
                                <div class="flex flex-wrap justify-end gap-1.5">
                                    <template x-if="!r.job || ['idle','done','error'].includes(r.job.state)">
                                        <button type="button" @click="control(r.source,'start')" :disabled="busy || !on"
                                                class="rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-white hover:opacity-90 disabled:opacity-30">
                                            เริ่มดูด
                                        </button>
                                    </template>
                                    <template x-if="r.job && ['queued','running','waiting','stalled'].includes(r.job.state)">
                                        <button type="button" @click="control(r.source,'pause')" :disabled="busy"
                                                class="rounded-md bg-white/8 px-3 py-1.5 text-xs hover:bg-white/12 disabled:opacity-40">พัก</button>
                                    </template>
                                    <template x-if="r.job && r.job.state === 'paused'">
                                        <button type="button" @click="control(r.source,'resume')" :disabled="busy || !on"
                                                class="rounded-md bg-success/20 px-3 py-1.5 text-xs text-success hover:bg-success/30 disabled:opacity-30">ไปต่อ</button>
                                    </template>
                                    <template x-if="r.job && r.job.state !== 'idle'">
                                        <button type="button" @click="control(r.source,'stop')" :disabled="busy"
                                                class="rounded-md bg-white/5 px-3 py-1.5 text-xs text-cream/60 hover:bg-white/10 disabled:opacity-40">หยุด</button>
                                    </template>
                                </div>
                            </template>
                            <template x-if="!r.can_download">
                                <span class="rounded-md bg-white/[0.04] px-3 py-1.5 text-xs text-cream/35">ยังสั่งดูดไม่ได้</span>
                            </template>

                            {{-- The heartbeat. A row that says "กำลังดูด" with nothing attached is the one
                                 lie this page must never tell, so the state itself becomes "ค้าง". --}}
                            <template x-if="r.job && ['waiting','stalled'].includes(r.job.state)">
                                <div class="max-w-[220px] text-right text-[11px] leading-relaxed text-[#ffc107]">
                                    สั่งไว้แล้วแต่ยังไม่มีตัวรันมารับงาน —
                                    ต้องรัน <code class="rounded bg-white/10 px-1">netwix:mirror-run</code> บนเซิร์ฟเวอร์
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <template x-if="msg">
        <div class="mt-4 rounded-xl border border-white/10 bg-white/[0.04] px-4 py-3 text-sm" x-text="msg"></div>
    </template>
</div>

{{-- ── Legacy: the desktop ingest bridge ────────────────────────────────────────
     Kept because rongyok once served fresh links only to residential IPs. The server now fetches
     directly, so this is informational, not a gate. --}}
<div class="nx-card mt-4 flex flex-wrap items-center justify-between gap-3 px-5 py-3.5">
    <div class="flex items-center gap-3 text-xs text-cream/45">
        <span class="inline-flex h-2 w-2 rounded-full {{ $agent['connected'] ? 'bg-success' : 'bg-white/20' }}"></span>
        <span>ตัวช่วยดาวน์โหลดจากเครื่องบ้าน (Hive Download):
            {{ $agent['connected'] ? 'เชื่อมต่ออยู่' : 'ไม่ได้เชื่อมต่อ' }}
            @if ($agent['last_seen']) · เห็นล่าสุด {{ $agent['last_seen']->diffForHumans() }} @endif
        </span>
    </div>
    <span class="text-xs text-cream/35">ไม่จำเป็นแล้ว — เซิร์ฟเวอร์ดาวน์โหลดเองได้โดยตรง</span>
</div>

{{-- ── Per-title table ─────────────────────────────────────────────────────── --}}
<div class="nx-card mt-6 overflow-hidden">
    <div class="border-b border-white/5 px-5 py-4 text-base font-semibold">รายเรื่องที่ดาวน์โหลดมาเก็บ ({{ $titles->total() }})</div>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[640px] text-sm">
            <thead class="border-b border-white/5 text-left text-xs uppercase text-cream/40">
                <tr>
                    <th class="px-5 py-3 font-medium">เรื่อง</th>
                    <th class="px-5 py-3 font-medium">แหล่ง</th>
                    <th class="px-5 py-3 font-medium">ตอนที่เก็บ</th>
                    <th class="px-5 py-3 font-medium">ขนาดรวม</th>
                    <th class="px-5 py-3 font-medium">เฉลี่ย/ตอน</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($titles as $t)
                    <tr class="border-b border-white/[0.04] hover:bg-white/[0.02]">
                        <td class="px-5 py-3">
                            <div class="flex items-center gap-3">
                                @php $poster = \App\Support\MediaUrl::adminPoster($t->poster_url); @endphp
                                <div class="relative h-12 w-[46px] flex-shrink-0 overflow-hidden rounded {{ $poster ? 'cursor-zoom-in' : '' }}"
                                     style="background:{{ $t->gradient }}" @if ($poster) data-zoom-src="{{ $poster }}" @endif>
                                    @if ($poster)
                                        <img src="{{ $poster }}" alt="" loading="lazy" referrerpolicy="no-referrer"
                                             class="absolute inset-0 h-full w-full object-cover" onerror="this.style.display='none'">
                                    @endif
                                </div>
                                <span class="font-medium">{{ $t->title }}</span>
                            </div>
                        </td>
                        <td class="px-5 py-3 text-cream/60">{{ $t->source === 'rongyok' ? 'โรงหยก' : ($t->source ?: '—') }}</td>
                        <td class="px-5 py-3 text-cream/70">{{ $t->mirrored_count }} / {{ $t->total_episodes }}</td>
                        <td class="px-5 py-3 font-semibold">{{ number_format(($t->media_bytes ?? 0) / 1e6, 0) }} MB</td>
                        <td class="px-5 py-3 text-cream/70">{{ $t->mirrored_count ? number_format(($t->media_bytes ?? 0) / 1e6 / $t->mirrored_count, 1) : 0 }} MB</td>
                        <td class="px-5 py-3 text-right"><a href="{{ route('admin.contents.edit', $t) }}" class="rounded-md bg-white/5 px-3 py-1.5 text-xs hover:bg-white/10">ดูตอน</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-5 py-12 text-center text-cream/45">
                        ยังไม่มีไฟล์ที่ดาวน์โหลดมาเก็บ<br>
                        <span class="text-xs">ตอนนี้ทุกเรื่องเล่นจากลิงก์ต้นทางสด — เปิดสวิตช์ด้านบนแล้วสั่งดูดรายแหล่งเพื่อเก็บไฟล์เอง</span>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-5">{{ $titles->links() }}</div>
@endsection

@push('scripts')
<script>
/**
 * The download monitor. Every number it shows comes from the server on each poll — nothing is
 * incremented locally — so the page can never drift away from what is really on disk.
 */
window.mirrorMonitor = function (cfg) {
    return {
        rows: cfg.rows,
        totals: cfg.totals,
        on: cfg.switch,
        usdPerTb: cfg.usdPerTb,
        urls: cfg.urls,
        at: '',
        busy: false,
        msg: '',
        timer: null,

        start() {
            this.tick();
            // Poll faster while something is actually running; a quiet page has no reason to keep
            // waking the database up.
            this.timer = setInterval(() => this.tick(), 5000);
            window.addEventListener('beforeunload', () => clearInterval(this.timer));
        },

        async tick() {
            try {
                const r = await fetch(this.urls.monitor, { headers: { Accept: 'application/json' } });
                if (!r.ok) return;
                const d = await r.json();
                this.rows = d.rows;
                this.totals = d.totals;
                this.on = d.switch;
                this.at = d.at;
            } catch (e) { /* a dropped poll is not worth showing; the next one recovers */ }
        },

        async toggle() {
            this.busy = true;
            const r = await window.nxPostSoft(this.urls.switch, { on: !this.on });
            this.busy = false;
            if (r && r.ok) {
                this.on = r.on;
                this.msg = r.on
                    ? 'เปิดสวิตช์แล้ว — สั่งดูดรายแหล่งได้ และต้องมี netwix:mirror-run รันอยู่จึงจะเริ่มโหลดจริง'
                    : 'ปิดสวิตช์แล้ว — พักงานที่กำลังทำอยู่ทั้งหมด ไม่มีอะไรดาวน์โหลดต่อ';
            }
            this.tick();
        },

        async control(source, action) {
            this.busy = true;
            const r = await window.nxPostSoft(this.urls.control, { source, action });
            this.busy = false;
            this.msg = (r && r.ok) ? '' : (r && r.error ? r.error : 'สั่งงานไม่สำเร็จ');
            this.tick();
        },

        async probe(source) {
            this.busy = true;
            this.msg = 'กำลังวัดขนาดไฟล์จริงของ ' + source + ' …';
            const r = await window.nxPostSoft(this.urls.probe, { source, count: 3 });
            this.busy = false;
            this.msg = (r && r.measured)
                ? 'วัด ' + source + ' สำเร็จ ' + r.measured + ' ตอน · เฉลี่ย ' + this.mb(r.avg) + ' MB/ตอน'
                : 'วัด ' + source + ' ไม่สำเร็จ' + (r && r.errors && r.errors.length ? ' — ' + r.errors[0] : '');
            this.tick();
        },

        async probeAll() {
            const todo = this.rows.filter(r => !r.avg && r.known !== false).map(r => r.source);
            if (!todo.length) { this.msg = 'วัดครบทุกแหล่งแล้ว'; return; }
            this.busy = true;
            let done = 0;
            for (const s of todo) {
                this.msg = 'กำลังวัด ' + s + ' (' + (done + 1) + '/' + todo.length + ') …';
                await window.nxPostSoft(this.urls.probe, { source: s, count: 3 });
                done++;
                await this.tick();
            }
            this.busy = false;
            this.msg = 'วัดเสร็จ ' + done + ' แหล่ง';
        },

        pct(a, b) { return b > 0 ? Math.min(100, Math.max(a > 0 ? 1 : 0, Math.round(a / b * 100))) : 0; },
        fmtInt(n) { return (n || 0).toLocaleString('th-TH'); },
        mb(b) { return ((b || 0) / 1e6).toFixed(1); },
        gb(b) { return ((b || 0) / 1e9).toFixed(2); },
        tb(b) { return ((b || 0) / 1e12).toFixed(2); },
        usd(b) { return ((b || 0) / 1e12 * this.usdPerTb).toFixed(2); },

        stateLabel(s) {
            return { running: 'กำลังดูด', queued: 'รอเริ่ม', waiting: 'สั่งแล้ว รอตัวรัน', stalled: 'ค้าง — ไม่มีตัวรัน',
                     paused: 'พักอยู่', done: 'เสร็จแล้ว', error: 'ผิดพลาด', idle: 'ยังไม่สั่ง' }[s] || s;
        },
        stateClass(s) {
            if (s === 'running') return 'bg-success/15 text-success';
            if (s === 'stalled' || s === 'error') return 'bg-[#ff6b81]/15 text-[#ff6b81]';
            if (s === 'waiting' || s === 'queued') return 'bg-[#ffc107]/15 text-[#ffc107]';
            if (s === 'paused') return 'bg-white/10 text-cream/60';
            return 'bg-white/[0.06] text-cream/40';
        },
    };
};
</script>
@endpush
