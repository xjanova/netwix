@extends('layouts.admin')
@section('page-title', 'ตั้งค่า / เชื่อมต่อ')
@section('page-subtitle', 'ตั้งค่าเข้าสู่ระบบด้วย Google / LINE และช่องทางช่วยเหลือ')
@section('action')<span></span>@endsection

@section('content')
@php
    $googleActive = filled($google_client_id) && $hasGoogleSecret;
    $lineActive = filled($line_client_id) && $hasLineSecret;
@endphp

<form method="POST" action="{{ route('admin.settings.update') }}" class="mx-auto flex max-w-3xl flex-col gap-6">
    @csrf @method('PUT')

    {{-- ============ GOOGLE ============ --}}
    <div class="nx-card p-6">
        <div class="mb-4 flex items-center justify-between">
            <div class="flex items-center gap-2.5">
                <svg class="h-6 w-6" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.611 20.083H42V20H24v8h11.303c-1.649 4.657-6.08 8-11.303 8-6.627 0-12-5.373-12-12s5.373-12 12-12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4 12.955 4 4 12.955 4 24s8.955 20 20 20 20-8.955 20-20c0-1.341-.138-2.65-.389-3.917z"/><path fill="#FF3D00" d="M6.306 14.691l6.571 4.819C14.655 15.108 18.961 12 24 12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4 16.318 4 9.656 8.337 6.306 14.691z"/><path fill="#4CAF50" d="M24 44c5.166 0 9.86-1.977 13.409-5.192l-6.19-5.238C29.211 35.091 26.715 36 24 36c-5.202 0-9.619-3.317-11.283-7.946l-6.522 5.025C9.505 39.556 16.227 44 24 44z"/><path fill="#1976D2" d="M43.611 20.083H42V20H24v8h11.303c-.792 2.237-2.231 4.166-4.087 5.571l6.19 5.238C36.971 39.205 44 34 44 24c0-1.341-.138-2.65-.389-3.917z"/></svg>
                <h3 class="text-base font-bold">เข้าสู่ระบบด้วย Google</h3>
            </div>
            @if ($googleActive)
                <span class="rounded-full bg-success/15 px-2.5 py-1 text-[11px] font-semibold text-success">● ใช้งานอยู่</span>
            @else
                <span class="rounded-full bg-white/10 px-2.5 py-1 text-[11px] font-semibold text-cream/50">○ ยังไม่ตั้งค่า</span>
            @endif
        </div>

        <div class="flex flex-col gap-3">
            <label class="text-[13px] text-cream/60">Client ID
                <input name="google_client_id" value="{{ old('google_client_id', $google_client_id) }}" placeholder="xxxxxxxx.apps.googleusercontent.com" class="nx-input mt-1">
            </label>
            <div x-data="{ show: false }">
                <label class="text-[13px] text-cream/60">Client Secret</label>
                <div class="mt-1 flex gap-2">
                    <input :type="show ? 'text' : 'password'" name="google_client_secret" autocomplete="new-password"
                           placeholder="{{ $hasGoogleSecret ? '•••••• บันทึกไว้แล้ว — เว้นว่างเพื่อคงค่าเดิม' : 'วาง Client Secret ที่นี่' }}" class="nx-input flex-1">
                    <button type="button" @click="show = !show" class="rounded-md bg-white/5 px-3 text-xs hover:bg-white/10" x-text="show ? 'ซ่อน' : 'แสดง'"></button>
                </div>
                @if ($hasGoogleSecret)
                    <label class="mt-2 flex items-center gap-2 text-[12px] text-cream/45">
                        <input type="checkbox" name="google_client_secret_clear" value="1" class="h-3.5 w-3.5 accent-brand"> ล้างค่า Client Secret ที่บันทึกไว้
                    </label>
                @endif
            </div>
        </div>

        <div class="mt-4 rounded-lg border border-white/[0.06] bg-white/[0.02] p-3.5 text-[12.5px] leading-relaxed text-cream/55">
            <div class="mb-1.5 font-semibold text-cream/70">วิธีเอาค่า:</div>
            <ol class="ml-4 list-decimal space-y-1">
                <li>เปิด <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener" class="text-brand-2 underline">Google Cloud Console → Credentials</a> สร้างโปรเจกต์ (ถ้ายังไม่มี)</li>
                <li>ตั้งค่า <strong>OAuth consent screen</strong> (External, ชื่อแอป NetWix, โดเมน netwix.online)</li>
                <li><strong>Create Credentials → OAuth client ID → Web application</strong></li>
                <li>Authorized redirect URI ใส่ตามด้านล่างนี้ แล้วคัดลอก Client ID + Secret มาวาง</li>
            </ol>
            <div class="mt-2.5 flex items-center gap-2">
                <code class="flex-1 truncate rounded bg-black/40 px-2 py-1.5 text-[11.5px] text-cream/80">{{ $callbackBase }}/auth/google/callback</code>
                <button type="button" class="shrink-0 rounded bg-white/5 px-2.5 py-1.5 text-[11px] hover:bg-white/10" onclick="navigator.clipboard.writeText('{{ $callbackBase }}/auth/google/callback')">คัดลอก</button>
            </div>
        </div>
    </div>

    {{-- ============ LINE LOGIN ============ --}}
    <div class="nx-card p-6">
        <div class="mb-4 flex items-center justify-between">
            <div class="flex items-center gap-2.5">
                <span class="flex h-6 w-6 items-center justify-center rounded-md text-white" style="background:#06C755"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M12 3C6.48 3 2 6.63 2 11.02c0 3.93 3.32 7.22 7.8 7.85.3.06.72.2.82.46.09.24.06.6.03.85l-.13.79c-.04.24-.19.94.83.51 1.02-.43 5.5-3.24 7.5-5.55C20.5 14.42 22 12.86 22 11.02 22 6.63 17.52 3 12 3z"/></svg></span>
                <h3 class="text-base font-bold">เข้าสู่ระบบด้วย LINE</h3>
            </div>
            @if ($lineActive)
                <span class="rounded-full bg-success/15 px-2.5 py-1 text-[11px] font-semibold text-success">● ใช้งานอยู่</span>
            @else
                <span class="rounded-full bg-white/10 px-2.5 py-1 text-[11px] font-semibold text-cream/50">○ ยังไม่ตั้งค่า</span>
            @endif
        </div>

        <div class="flex flex-col gap-3">
            <label class="text-[13px] text-cream/60">Channel ID
                <input name="line_client_id" value="{{ old('line_client_id', $line_client_id) }}" placeholder="เช่น 2001234567" class="nx-input mt-1">
            </label>
            <div x-data="{ show: false }">
                <label class="text-[13px] text-cream/60">Channel Secret</label>
                <div class="mt-1 flex gap-2">
                    <input :type="show ? 'text' : 'password'" name="line_client_secret" autocomplete="new-password"
                           placeholder="{{ $hasLineSecret ? '•••••• บันทึกไว้แล้ว — เว้นว่างเพื่อคงค่าเดิม' : 'วาง Channel Secret ที่นี่' }}" class="nx-input flex-1">
                    <button type="button" @click="show = !show" class="rounded-md bg-white/5 px-3 text-xs hover:bg-white/10" x-text="show ? 'ซ่อน' : 'แสดง'"></button>
                </div>
                @if ($hasLineSecret)
                    <label class="mt-2 flex items-center gap-2 text-[12px] text-cream/45">
                        <input type="checkbox" name="line_client_secret_clear" value="1" class="h-3.5 w-3.5 accent-brand"> ล้างค่า Channel Secret ที่บันทึกไว้
                    </label>
                @endif
            </div>
        </div>

        <div class="mt-4 rounded-lg border border-white/[0.06] bg-white/[0.02] p-3.5 text-[12.5px] leading-relaxed text-cream/55">
            <div class="mb-1.5 font-semibold text-cream/70">วิธีเอาค่า:</div>
            <ol class="ml-4 list-decimal space-y-1">
                <li>เปิด <a href="https://developers.line.biz/console/" target="_blank" rel="noopener" class="text-brand-2 underline">LINE Developers Console</a> สร้าง Provider (ถ้ายังไม่มี)</li>
                <li>สร้าง Channel ประเภท <strong>LINE Login</strong> (ไม่ใช่ Messaging API), App type = Web app</li>
                <li>แท็บ <strong>LINE Login</strong> → ใส่ Callback URL ตามด้านล่าง</li>
                <li>แท็บ <strong>Basic settings</strong> → คัดลอก <strong>Channel ID</strong> + <strong>Channel secret</strong> มาวาง</li>
                <li>(ถ้าต้องการอีเมลผู้ใช้ ต้องยื่นขอ Email permission ในคอนโซล)</li>
            </ol>
            <div class="mt-2.5 flex items-center gap-2">
                <code class="flex-1 truncate rounded bg-black/40 px-2 py-1.5 text-[11.5px] text-cream/80">{{ $callbackBase }}/auth/line/callback</code>
                <button type="button" class="shrink-0 rounded bg-white/5 px-2.5 py-1.5 text-[11px] hover:bg-white/10" onclick="navigator.clipboard.writeText('{{ $callbackBase }}/auth/line/callback')">คัดลอก</button>
            </div>
        </div>
    </div>

    {{-- ============ SUPPORT ============ --}}
    <div class="nx-card p-6">
        <h3 class="mb-1 text-base font-bold">ศูนย์ช่วยเหลือ</h3>
        <p class="mb-4 text-[13px] text-cream/50">ปุ่ม “แชทกับแอดมิน” บนหน้าช่วยเหลือจะพาไป LINE Official Account ตามลิงก์นี้</p>
        <div class="flex flex-col gap-3">
            <label class="text-[13px] text-cream/60">ลิงก์ LINE Official Account
                <input name="support_line_url" value="{{ old('support_line_url', $support_line_url) }}" placeholder="https://line.me/R/ti/p/@netwix หรือ https://lin.ee/xxxx" class="nx-input mt-1">
            </label>
            <label class="text-[13px] text-cream/60">อีเมลติดต่อ
                <input name="support_email" type="email" value="{{ old('support_email', $support_email) }}" placeholder="support@netwix.online" class="nx-input mt-1">
            </label>
        </div>
        <p class="mt-3 text-[12px] text-cream/40">ลิงก์ LINE OA เอาจากแอป LINE Official Account Manager → โปรไฟล์ → “เพิ่มเพื่อน” (Basic ID เช่น @netwix) หรือลิงก์ lin.ee</p>
    </div>

    {{-- ============ APP (APK) FROM GITHUB ============ --}}
    <div class="nx-card p-6">
        <div class="mb-4 flex items-center justify-between">
            <div class="flex items-center gap-2.5">
                <span class="flex h-6 w-6 items-center justify-center rounded-md bg-white/10"><svg class="h-4 w-4 text-cream" viewBox="0 0 24 24" fill="currentColor"><path d="M12 16l-5-5h3V4h4v7h3l-5 5zM5 18h14v2H5z"/></svg></span>
                <h3 class="text-base font-bold">แอปมือถือ (APK) — อัปเดตจาก GitHub</h3>
            </div>
            @if ($appRelease)
                <span class="rounded-full bg-success/15 px-2.5 py-1 text-[11px] font-semibold text-success">● พบเวอร์ชัน {{ $appRelease['version'] }}</span>
            @elseif (filled($app_github_repo))
                <span class="rounded-full bg-gold/15 px-2.5 py-1 text-[11px] font-semibold text-gold">! ยังไม่พบ release ที่มีไฟล์ .apk</span>
            @else
                <span class="rounded-full bg-white/10 px-2.5 py-1 text-[11px] font-semibold text-cream/50">○ ยังไม่ตั้งค่า</span>
            @endif
        </div>

        <div class="flex flex-col gap-3">
            <label class="text-[13px] text-cream/60">GitHub repo ของแอป (owner/repo)
                <input name="app_github_repo" value="{{ old('app_github_repo', $app_github_repo) }}" placeholder="เช่น xjanova/hivedownload" class="nx-input mt-1">
            </label>
            <div x-data="{ show: false }">
                <label class="text-[13px] text-cream/60">GitHub Token <span class="text-cream/35">(ไม่บังคับ — ใส่ถ้า repo เป็น private หรือชน rate limit)</span></label>
                <div class="mt-1 flex gap-2">
                    <input :type="show ? 'text' : 'password'" name="app_github_token" autocomplete="new-password"
                           placeholder="{{ $hasAppToken ? '•••••• บันทึกไว้แล้ว — เว้นว่างเพื่อคงค่าเดิม' : 'ghp_… (ไม่บังคับ)' }}" class="nx-input flex-1">
                    <button type="button" @click="show = !show" class="rounded-md bg-white/5 px-3 text-xs hover:bg-white/10" x-text="show ? 'ซ่อน' : 'แสดง'"></button>
                </div>
                @if ($hasAppToken)
                    <label class="mt-2 flex items-center gap-2 text-[12px] text-cream/45">
                        <input type="checkbox" name="app_github_token_clear" value="1" class="h-3.5 w-3.5 accent-brand"> ล้าง Token ที่บันทึกไว้
                    </label>
                @endif
            </div>
        </div>

        @if ($appRelease)
            <div class="mt-4 flex items-start gap-3 rounded-lg border border-success/20 bg-success/[0.06] p-3.5 text-[12.5px]">
                <span class="text-success">✓</span>
                <div class="text-cream/70">พร้อมให้ลูกค้าดาวน์โหลด: <strong class="text-cream">{{ $appRelease['apk_name'] }}</strong> · {{ number_format($appRelease['size'] / 1048576, 1) }} MB · เวอร์ชัน {{ $appRelease['version'] }}
                    <a href="{{ route('download') }}" target="_blank" rel="noopener" class="ml-1 text-brand-2 underline">เปิดหน้าดาวน์โหลด ›</a>
                </div>
            </div>
        @endif

        <div class="mt-4 rounded-lg border border-white/[0.06] bg-white/[0.02] p-3.5 text-[12.5px] leading-relaxed text-cream/55">
            <div class="mb-1.5 font-semibold text-cream/70">วิธีอัปเดตเวอร์ชัน:</div>
            <ol class="ml-4 list-decimal space-y-1">
                <li>ไปที่ repo ของแอปบน GitHub → <strong>Releases → Draft a new release</strong></li>
                <li>แนบไฟล์ <strong>.apk</strong> ของเวอร์ชันใหม่ ใส่ tag (เช่น v1.2.0) และ release notes</li>
                <li>กด <strong>Publish release</strong> — เว็บจะดึงเวอร์ชันล่าสุดมาให้ลูกค้าโหลดผ่าน netwix.online อัตโนมัติ (แคช ~30 นาที)</li>
            </ol>
            <p class="mt-2 text-cream/40">ลูกค้าดาวน์โหลดผ่านโดเมนเราเท่านั้น ไม่ต้องเข้า GitHub เอง</p>
        </div>
    </div>

    <div class="flex items-center justify-between rounded-xl border border-white/5 bg-white/[0.02] px-5 py-4">
        <p class="text-[12.5px] text-cream/45">ค่าลับถูกเข้ารหัสก่อนบันทึก และไม่ถูกแสดงซ้ำในหน้านี้</p>
        <button class="btn-brand px-8 py-2.5">บันทึกการตั้งค่า</button>
    </div>
</form>
@endsection
