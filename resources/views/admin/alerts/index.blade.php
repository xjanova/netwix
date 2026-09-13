@extends('layouts.admin')
@section('page-title', 'แจ้งเตือนปัญหา')
@section('page-subtitle', 'ส่งเรื่องสำคัญเข้า Telegram (ฟรี · การ์ดกราฟิก) หรือ LINE — แหล่งหนังล่ม เงินเข้า เว็บ error มีคนโจมตี และรายงานประจำวัน')

@section('content')
@if (session('status'))
    <div class="mb-5 rounded-xl border border-success/30 bg-success/10 px-4 py-3 text-[13px] text-success">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="mb-5 rounded-xl border border-brand/40 bg-brand/10 px-4 py-3 text-[13px] text-brand">
        @foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach
    </div>
@endif

@if (!empty($sourcesDown))
    <div class="mb-5 rounded-xl border border-[#ff6b81]/30 bg-[#ff6b81]/10 px-4 py-3 text-[13px] text-cream/80">
        ตอนนี้มีแหล่งที่ดึงลิ้งค์ไม่ได้: <b class="text-[#ff6b81]">{{ implode(', ', $sourcesDown) }}</b>
    </div>
@endif

<div class="grid gap-5 lg:grid-cols-2">

    {{-- ── Telegram ──────────────────────────────────────────────────────────── --}}
    <div class="nx-card p-5">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2.5">
                <span class="grid h-9 w-9 place-items-center rounded-full bg-[#229ED9]">
                    <svg viewBox="0 0 24 24" class="h-[18px] w-[18px] -translate-x-px fill-white" aria-hidden="true"><path d="M21.4 4.1 2.9 11.2c-1.3.5-1.3 1.2-.2 1.5l4.7 1.5 1.8 5.6c.2.6.1.9.8.9.5 0 .7-.2 1-.5l2.3-2.2 4.8 3.5c.9.5 1.5.2 1.7-.8l3.2-15c.3-1.3-.5-1.9-1.6-1.4ZM8.9 13.9l9.6-6.1c.5-.3.9-.1.5.2l-8.2 7.4-.3 3.4-1.6-4.9Z"/></svg>
                </span>
                <div>
                    <div class="text-[15px] font-bold">Telegram</div>
                    <div class="text-[11.5px] text-[#5ec2f0]">แนะนำ · ฟรี ไม่จำกัดจำนวน · ส่งเป็นการ์ดกราฟิก</div>
                </div>
            </div>
            @if ($tg['ready'])
                <span class="rounded-full bg-success/15 px-2.5 py-1 text-[11.5px] font-semibold text-success">● กำลังส่งแจ้งเตือน</span>
            @elseif ($tg['hasToken'] && $tg['chat'] !== '')
                <span class="rounded-full bg-white/8 px-2.5 py-1 text-[11.5px] text-cream/60">ตั้งค่าครบ แต่ปิดอยู่</span>
            @else
                <span class="rounded-full bg-white/8 px-2.5 py-1 text-[11.5px] text-cream/60">ยังไม่ได้ตั้งค่า</span>
            @endif
        </div>

        @unless ($tg['ready'])
            <ol class="mb-4 space-y-1.5 rounded-lg border border-white/5 bg-white/[0.02] p-3.5 text-[12.5px] leading-relaxed text-cream/60">
                <li><b class="text-cream/80">1.</b> ใน Telegram ค้นหา <b class="text-cream/80">@BotFather</b> → พิมพ์ <code class="rounded bg-white/8 px-1">/newbot</code> → ตั้งชื่อบอท → คัดลอก <b class="text-cream/80">Token</b> มาวางด้านล่างแล้วกดบันทึก</li>
                <li><b class="text-cream/80">2.</b> เปิดแชทกับบอทของคุณแล้วกด <b class="text-cream/80">Start</b> (อยากให้ทีมเห็นด้วย ให้เพิ่มบอทเข้ากลุ่มแทน)</li>
                <li><b class="text-cream/80">3.</b> กด <b class="text-cream/80">ค้นหา Chat ID อัตโนมัติ</b> → เลือกแชท → กด <b class="text-cream/80">ทดสอบส่ง</b></li>
            </ol>
        @endunless

        <form method="POST" action="{{ route('admin.alerts.telegram.update') }}" class="space-y-4">
            @csrf @method('PUT')

            <label class="flex cursor-pointer items-center gap-2 text-[14px]">
                <input type="checkbox" name="telegram_alerts_enabled" value="1" @checked(old('telegram_alerts_enabled', $tg['enabled']))
                       class="h-4 w-4 rounded border-white/20 bg-white/5">
                <span class="font-semibold">เปิดการแจ้งเตือนเข้า Telegram</span>
            </label>

            <div>
                <label class="mb-1 flex flex-wrap items-center gap-1.5 text-[13px] text-cream/60">
                    Bot Token
                    @if ($tg['hasToken'])
                        <span class="rounded-full bg-success/15 px-2 py-0.5 text-[11px] text-success">ตั้งค่าไว้แล้ว</span>
                    @endif
                    @if ($tg['bot'] !== '')
                        <a href="https://t.me/{{ $tg['bot'] }}" target="_blank" rel="noopener noreferrer"
                           class="rounded-full bg-[#229ED9]/15 px-2 py-0.5 text-[11px] text-[#5ec2f0] hover:bg-[#229ED9]/25">{{ '@'.$tg['bot'] }} · เปิดแชท ↗</a>
                    @endif
                </label>
                <input type="password" name="telegram_bot_token" autocomplete="new-password" spellcheck="false"
                       placeholder="{{ $tg['hasToken'] ? 'เว้นว่างไว้ = ใช้ค่าเดิม' : '123456789:AAH…' }}"
                       class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2.5 font-mono text-[13px]">
                <div class="mt-1 text-[12px] text-cream/40">เก็บแบบเข้ารหัส และไม่แสดงค่ากลับมาอีก — ตอนบันทึกระบบจะเช็คกับ Telegram ให้ว่าใช้ได้จริง</div>
            </div>

            <div>
                <label class="mb-1 block text-[13px] text-cream/60">ส่งเข้าแชทไหน (Chat ID)</label>
                <input type="text" name="telegram_chat_id" value="{{ old('telegram_chat_id', $tg['chat']) }}" placeholder="เช่น 123456789 หรือ -1001234567890"
                       class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2.5 font-mono text-[13px]">
                <div class="mt-1 text-[12px] text-cream/40">ไม่ต้องหาเอง — กดปุ่ม "ค้นหา Chat ID อัตโนมัติ" ด้านล่างหลังจากกด Start กับบอทแล้ว</div>
            </div>

            <button class="rounded-lg bg-[#229ED9] px-5 py-2.5 text-[14px] font-semibold text-white hover:bg-[#229ED9]/90">บันทึก</button>
        </form>

        <div class="mt-4 flex flex-wrap gap-2 border-t border-white/5 pt-4">
            <form method="POST" action="{{ route('admin.alerts.telegram.detect') }}">@csrf
                <button class="rounded-lg bg-white/10 px-4 py-2.5 text-[13px] font-semibold hover:bg-white/15" @disabled(!$tg['hasToken'])>ค้นหา Chat ID อัตโนมัติ</button>
            </form>
            <form method="POST" action="{{ route('admin.alerts.telegram.test') }}">@csrf
                <button class="rounded-lg bg-white/10 px-4 py-2.5 text-[13px] font-semibold hover:bg-white/15">ทดสอบส่ง</button>
            </form>
            @if ($tg['hasToken'])
                <form method="POST" action="{{ route('admin.alerts.telegram.forget') }}"
                      onsubmit="return confirm('ลบ Bot Token และปิดการแจ้งเตือน Telegram?')">@csrf @method('DELETE')
                    <button class="rounded-lg bg-brand/15 px-4 py-2.5 text-[13px] text-brand hover:bg-brand/25">ลบ Token</button>
                </form>
            @endif
        </div>

        @if (!empty($chats))
            <div class="mt-4 overflow-hidden rounded-lg border border-[#229ED9]/30">
                <div class="bg-[#229ED9]/10 px-3.5 py-2 text-[12.5px] font-semibold text-[#5ec2f0]">แชทที่คุยกับบอทล่าสุด — เลือกแชทที่จะรับแจ้งเตือน</div>
                @foreach ($chats as $c)
                    <div class="flex items-center justify-between gap-3 border-t border-white/5 px-3.5 py-2.5">
                        <div class="min-w-0">
                            <div class="truncate text-[13.5px] font-semibold">{{ $c['title'] }}</div>
                            <div class="text-[11.5px] text-cream/45">
                                {{ ['private' => 'แชทส่วนตัว', 'group' => 'กลุ่ม', 'supergroup' => 'กลุ่ม', 'channel' => 'ช่อง'][$c['type']] ?? $c['type'] }}
                                · <span class="font-mono">{{ $c['id'] }}</span>
                            </div>
                        </div>
                        <form method="POST" action="{{ route('admin.alerts.telegram.chat') }}">@csrf
                            <input type="hidden" name="chat_id" value="{{ $c['id'] }}">
                            <button class="shrink-0 rounded-lg bg-[#229ED9] px-3.5 py-2 text-[12.5px] font-semibold text-white hover:bg-[#229ED9]/90">ใช้แชทนี้</button>
                        </form>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- ── LINE ─────────────────────────────────────────────────────────────── --}}
    <div class="nx-card p-5">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2.5">
                <span class="grid h-9 w-9 place-items-center rounded-full bg-[#06C755] text-[11px] font-black text-white">LINE</span>
                <div>
                    <div class="text-[15px] font-bold">LINE Official Account</div>
                    <div class="text-[11.5px] text-cream/45">ข้อความตัวอักษร · ฟรีแค่โควตาต่อเดือน เกินแล้วเสียเงิน</div>
                </div>
            </div>
            @if ($line['ready'])
                <span class="rounded-full bg-success/15 px-2.5 py-1 text-[11.5px] font-semibold text-success">● กำลังส่งแจ้งเตือน</span>
            @else
                <span class="rounded-full bg-white/8 px-2.5 py-1 text-[11.5px] text-cream/60">{{ $line['hasToken'] ? 'ปิดอยู่' : 'ยังไม่ได้ตั้งค่า' }}</span>
            @endif
        </div>

        <form method="POST" action="{{ route('admin.alerts.line.update') }}" class="space-y-4">
            @csrf @method('PUT')

            <label class="flex cursor-pointer items-center gap-2 text-[14px]">
                <input type="checkbox" name="line_alerts_enabled" value="1" @checked(old('line_alerts_enabled', $line['enabled']))
                       class="h-4 w-4 rounded border-white/20 bg-white/5">
                <span class="font-semibold">เปิดการแจ้งเตือนเข้า LINE</span>
            </label>

            <div>
                <label class="mb-1 block text-[13px] text-cream/60">
                    Channel access token (Messaging API)
                    @if ($line['hasToken'])
                        <span class="ml-1 rounded-full bg-success/15 px-2 py-0.5 text-[11px] text-success">ตั้งค่าไว้แล้ว</span>
                    @endif
                </label>
                <input type="password" name="line_oa_token" autocomplete="new-password"
                       placeholder="{{ $line['hasToken'] ? 'เว้นว่างไว้ = ใช้ค่าเดิม' : 'วาง token จาก LINE Developers' }}"
                       class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2.5 text-[14px]">
                <div class="mt-1 text-[12px] text-cream/40">เก็บแบบเข้ารหัส และไม่แสดงค่ากลับมาอีก — ถ้าต้องการเปลี่ยนให้วางค่าใหม่ทับ</div>
            </div>

            <div>
                <label class="mb-1 block text-[13px] text-cream/60">ส่งหาใคร (User ID หรือ Group ID)</label>
                <input type="text" name="line_oa_to" value="{{ old('line_oa_to', $line['to']) }}" placeholder="Uxxxxxxxxxxxxxxxx"
                       class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2.5 text-[14px]">
                <div class="mt-1 text-[12px] text-cream/40">หา User ID ได้จาก LINE Developers → Basic settings → Your user ID (ต้องเพิ่ม OA เป็นเพื่อนก่อน)</div>
            </div>

            <button class="rounded-lg bg-[#06C755] px-5 py-2.5 text-[14px] font-semibold text-white hover:bg-[#06C755]/90">บันทึก</button>
        </form>

        <div class="mt-4 flex flex-wrap gap-2 border-t border-white/5 pt-4">
            <form method="POST" action="{{ route('admin.alerts.line.test') }}">@csrf
                <button class="rounded-lg bg-white/10 px-4 py-2.5 text-[13px] font-semibold hover:bg-white/15">ทดสอบส่ง</button>
            </form>
            @if ($line['hasToken'])
                <form method="POST" action="{{ route('admin.alerts.line.forget') }}"
                      onsubmit="return confirm('ลบ Token และปิดการแจ้งเตือน LINE?')">@csrf @method('DELETE')
                    <button class="rounded-lg bg-brand/15 px-4 py-2.5 text-[13px] text-brand hover:bg-brand/25">ลบ Token</button>
                </form>
            @endif
        </div>
    </div>
</div>

{{-- ── เรื่องไหนส่งเข้าช่องทางไหน ──────────────────────────────────────────────── --}}
<form method="POST" action="{{ route('admin.alerts.routes') }}" class="nx-card mt-5 overflow-hidden">
    @csrf @method('PUT')
    <div class="border-b border-white/5 px-5 py-4">
        <div class="text-base font-semibold">แจ้งเรื่องอะไร เข้าช่องทางไหน</div>
        <div class="mt-0.5 text-xs text-cream/45">
            ติ๊กเฉพาะที่อยากรู้ — ทุกเรื่องมีตัวกันแจ้งถี่ในตัว (เรื่องเดิมจะเงียบไประยะหนึ่ง และเรื่องที่เกิดทีละมาก ๆ จะรวบเป็นข้อความเดียว)
            · แนะนำให้ส่งรายงานประจำวันและเงินเข้าทาง Telegram เพราะ LINE คิดเงินตามจำนวนข้อความ
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[560px] text-[13px]">
            <thead>
                <tr class="text-left text-[12px] text-cream/45">
                    <th class="px-5 py-2.5 font-medium">เรื่อง</th>
                    <th class="w-28 px-3 py-2.5 text-center font-medium">Telegram</th>
                    <th class="w-28 px-3 py-2.5 text-center font-medium">LINE</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($categories as $key => $cat)
                    <tr class="border-t border-white/[0.04]">
                        <td class="px-5 py-3">
                            <div class="font-semibold text-cream/85">{{ $cat[0] }}</div>
                            <div class="mt-0.5 text-[12px] text-cream/45">{{ $cat[1] }}</div>
                        </td>
                        @foreach (['telegram', 'line'] as $channel)
                            <td class="px-3 py-3 text-center">
                                <input type="checkbox" name="routes[{{ $channel }}][]" value="{{ $key }}"
                                       @checked(in_array($key, $routes[$channel] ?? [], true))
                                       class="h-4 w-4 rounded border-white/20 bg-white/5" aria-label="{{ $cat[0] }} → {{ $channel }}">
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="border-t border-white/5 px-5 py-3.5">
        <button class="rounded-lg bg-brand px-5 py-2.5 text-[13.5px] font-semibold text-white hover:bg-brand/90">บันทึกการเลือก</button>
    </div>
</form>

{{-- ── ตัวอย่างการ์ด ──────────────────────────────────────────────────────────── --}}
<div class="nx-card mt-5 p-5">
    <div class="text-base font-semibold">หน้าตาการ์ดที่เด้งเข้า Telegram</div>
    <div class="mt-0.5 text-xs text-cream/45">ตัวอย่างจากข้อมูลสมมติ — ของจริงวาดจากเหตุการณ์จริงทุกครั้ง พร้อมปุ่มกดเข้าหน้าแอดมินที่เกี่ยวข้อง</div>
    @if ($canDraw)
        <div class="mt-4 flex gap-4 overflow-x-auto pb-2">
            @foreach (['critical' => 'ด่วน — เช่น แหล่งหนังล่ม', 'warning' => 'ควรตรวจสอบ — เช่น สรุปหนังที่ถูกหยุด', 'info' => 'แจ้งให้ทราบ — เช่น บล็อกบอท (ไม่มีเสียง)'] as $lv => $caption)
                <figure class="w-[290px] shrink-0">
                    <a href="{{ route('admin.alerts.preview', $lv) }}" target="_blank">
                        <img src="{{ route('admin.alerts.preview', $lv) }}" alt="{{ $caption }}" loading="lazy"
                             class="w-full rounded-xl border border-white/10 bg-white/[0.02]">
                    </a>
                    <figcaption class="mt-1.5 text-[12px] text-cream/50">{{ $caption }}</figcaption>
                </figure>
            @endforeach
        </div>
    @else
        <div class="mt-3 rounded-lg bg-white/[0.03] px-4 py-3 text-[13px] text-cream/55">
            เซิร์ฟเวอร์นี้วาดการ์ดไม่ได้ (ไม่มี GD/FreeType หรือไม่พบฟอนต์ใน resources/fonts) — Telegram จะส่งเป็นข้อความตัวอักษรแทน
        </div>
    @endif
</div>

{{-- ── ประวัติการแจ้งเตือน ──────────────────────────────────────────────────
     Exists because an alert used to live only on the owner's phone: the throttle key is stored
     hashed, and a SUCCESSFUL push wrote nothing anywhere. "What was that alert?" had no answer. --}}
<div class="nx-card mt-5 overflow-hidden">
    <div class="border-b border-white/5 px-5 py-4">
        <div class="text-base font-semibold">ข้อความที่ส่งไปแล้ว</div>
        <div class="mt-0.5 text-xs text-cream/45">เก็บทุกครั้งที่ระบบส่ง แยกตามช่องทาง — ย้อนดูได้ว่าที่เด้งเข้ามือถือคืออะไร และช่องทางไหนส่งไม่ผ่าน</div>
    </div>
    @forelse ($recent as $a)
        <div class="border-b border-white/[0.04] px-5 py-3.5">
            <div class="flex flex-wrap items-center gap-2 text-xs">
                @if (($a->channel ?? 'line') === 'telegram')
                    <span class="rounded bg-[#229ED9]/15 px-2 py-0.5 text-[#5ec2f0]">Telegram</span>
                @else
                    <span class="rounded bg-[#06C755]/15 px-2 py-0.5 text-[#3ddc84]">LINE</span>
                @endif
                <span class="rounded px-2 py-0.5 {{ $a->ok ? 'bg-success/15 text-success' : 'bg-[#ff6b81]/15 text-[#ff6b81]' }}">
                    {{ $a->ok ? 'ส่งสำเร็จ' : 'ส่งไม่สำเร็จ' }}
                </span>
                <span class="text-cream/40">{{ \Illuminate\Support\Carbon::parse($a->created_at)->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}</span>
                @if ($a->alert_key)
                    <code class="rounded bg-white/8 px-1.5 py-0.5 text-cream/60">{{ $a->alert_key }}</code>
                @endif
            </div>
            <div class="mt-1.5 whitespace-pre-wrap text-[13px] leading-relaxed text-cream/75">{{ \Illuminate\Support\Str::limit($a->body, 400) }}</div>
            @if ($a->error)
                <div class="mt-1 text-xs text-[#ff6b81]">{{ $a->error }}</div>
            @endif
        </div>
    @empty
        <div class="px-5 py-10 text-center text-sm text-cream/45">ยังไม่มีข้อความที่ส่งไป</div>
    @endforelse
</div>
@endsection
