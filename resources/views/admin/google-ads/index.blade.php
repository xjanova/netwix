@extends('layouts.admin')
@section('page-title', 'โฆษณา Google (AdSense / AdMob)')
@section('page-subtitle', 'โฆษณาจากเครือข่ายในจุดแบนเนอร์ของเว็บและในแอป — สมาชิก Pro ไม่เห็นโฆษณาทุกจุด')

@section('content')
@if (session('status'))
    <div class="mb-5 rounded-xl border border-success/30 bg-success/10 px-4 py-3 text-[13px] text-success">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="mb-5 rounded-xl border border-brand/40 bg-brand/10 px-4 py-3 text-[13px] text-brand">
        @foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach
    </div>
@endif

<div class="mb-5 rounded-xl border border-white/5 bg-white/[0.02] px-4 py-4 text-[13px] leading-relaxed text-cream/55">
    <div class="mb-1 font-semibold text-cream/80">ก่อนเปิดใช้ — ข้อควรรู้</div>
    เว็บที่มีหนัง/ซีรีส์ลิขสิทธิ์มีโอกาสสูงที่ AdSense จะ<b class="text-cream/75">ไม่อนุมัติหรือระงับบัญชี</b>
    และหน้าที่เป็นเรท 18+/20+ ผิดนโยบายชัดเจน ระบบนี้จึง<b class="text-cream/75">บล็อกโฆษณาบนหน้าเรทผู้ใหญ่ให้อัตโนมัติ</b>
    และทุกช่องรองรับการวางโค้ดจากเครือข่ายอื่นแทนได้ ถ้า AdSense ไม่ผ่านก็แค่เปลี่ยนโค้ดในช่อง ไม่ต้องแก้ระบบ
</div>

<form method="POST" action="{{ route('admin.google-ads.update') }}" class="space-y-5">
    @csrf
    @method('PUT')

    {{-- Web / AdSense --}}
    <div class="nx-card p-5">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="text-[15px] font-bold">เว็บไซต์ — Google AdSense</div>
            <label class="flex cursor-pointer items-center gap-2 text-[13px]">
                <input type="checkbox" name="ads_enabled" value="1" @checked(old('ads_enabled', $settings['ads_enabled']) == '1')
                       class="h-4 w-4 rounded border-white/20 bg-white/5">
                <span>เปิดโฆษณาบนเว็บ</span>
            </label>
        </div>

        <label class="mb-1 block text-[13px] text-cream/60">รหัสผู้เผยแพร่ (Publisher ID)</label>
        <input type="text" name="adsense_client_id" placeholder="ca-pub-1234567890123456"
               value="{{ old('adsense_client_id', $settings['adsense_client_id']) }}"
               class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2.5 text-[14px]">
        <div class="mt-1 text-[12px] {{ $clientOk ? 'text-success' : 'text-cream/40' }}">
            {{ $clientOk ? 'รูปแบบถูกต้อง — โค้ด AdSense จะถูกใส่ในทุกหน้าสาธารณะ' : 'ยังไม่ได้ตั้งค่า (วางรหัสจาก AdSense → บัญชี)' }}
        </div>

        <div class="mt-5 space-y-4">
            @php
                $labels = [
                    'header' => ['ใต้เมนูด้านบน (Header)', 'แบนเนอร์แนวนอนเหนือเนื้อหา เห็นทุกหน้า'],
                    'infeed' => ['ในหน้ารายละเอียดเรื่อง (In-feed)', 'คั่นระหว่างข้อมูลเรื่องกับ "เรื่องที่คล้ายกัน"'],
                    'sidebar' => ['ด้านข้าง (Sidebar)', 'สำรองไว้ ยังไม่ได้วางในหน้าใด'],
                    'footer' => ['เหนือส่วนท้ายเว็บ (Footer)', 'แบนเนอร์ปิดท้าย เห็นทุกหน้า'],
                ];
            @endphp
            @foreach ($slots as $slot)
                <div class="rounded-lg border border-white/5 bg-white/[0.02] p-3.5">
                    <div class="text-[13.5px] font-semibold text-cream/85">{{ $labels[$slot][0] ?? $slot }}</div>
                    <div class="mb-2.5 text-[12px] text-cream/40">{{ $labels[$slot][1] ?? '' }}</div>

                    <label class="mb-1 block text-[12px] text-cream/50">รหัสหน่วยโฆษณา AdSense (ตัวเลข)</label>
                    <input type="text" name="adsense_slot_{{ $slot }}" placeholder="1234567890"
                           value="{{ old('adsense_slot_'.$slot, $settings['adsense_slot_'.$slot]) }}"
                           class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-[13px]">

                    <label class="mb-1 mt-2.5 block text-[12px] text-cream/50">
                        หรือวางโค้ดจากเครือข่ายอื่น (ถ้ากรอกช่องนี้ จะใช้ช่องนี้แทน AdSense)
                    </label>
                    <textarea name="ads_custom_{{ $slot }}" rows="2" placeholder="<script>…</script>"
                              class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 font-mono text-[12px]">{{ old('ads_custom_'.$slot, $settings['ads_custom_'.$slot]) }}</textarea>
                </div>
            @endforeach
        </div>
    </div>

    {{-- App / AdMob --}}
    <div class="nx-card p-5">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="text-[15px] font-bold">แอปมือถือ — Google AdMob</div>
            <label class="flex cursor-pointer items-center gap-2 text-[13px]">
                <input type="checkbox" name="admob_enabled" value="1" @checked(old('admob_enabled', $settings['admob_enabled']) == '1')
                       class="h-4 w-4 rounded border-white/20 bg-white/5">
                <span>เปิดโฆษณาในแอป</span>
            </label>
        </div>
        <div class="mb-4 text-[12px] text-cream/45">
            แอปอ่านค่าเหล่านี้จาก <code class="rounded bg-black/30 px-1.5 py-0.5">GET /api/app/ads/config</code>
            — สมาชิก Pro จะได้ <code class="rounded bg-black/30 px-1.5 py-0.5">show_ads=false</code> และรหัสเป็น null
            ตัดสินที่เซิร์ฟเวอร์ ไม่ต้องรอผู้ใช้อัปเดตแอป
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ([
                'admob_android_app_id' => 'App ID (Android)',
                'admob_ios_app_id' => 'App ID (iOS)',
                'admob_unit_banner' => 'หน่วยโฆษณา — แบนเนอร์',
                'admob_unit_interstitial' => 'หน่วยโฆษณา — เต็มหน้าจอ (Interstitial)',
                'admob_unit_native' => 'หน่วยโฆษณา — Native',
                'admob_unit_rewarded' => 'หน่วยโฆษณา — มีรางวัล (Rewarded)',
            ] as $key => $label)
                <div>
                    <label class="mb-1 block text-[12px] text-cream/50">{{ $label }}</label>
                    <input type="text" name="{{ $key }}" placeholder="ca-app-pub-…"
                           value="{{ old($key, $settings[$key]) }}"
                           class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-[13px]">
                </div>
            @endforeach
            <div>
                <label class="mb-1 block text-[12px] text-cream/50">เว้นระยะโฆษณาเต็มหน้าจอ (นาที · 0 = ปิด)</label>
                <input type="number" name="admob_interstitial_minutes" min="0" max="240"
                       value="{{ old('admob_interstitial_minutes', $settings['admob_interstitial_minutes'] ?: 8) }}"
                       class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-[13px]">
            </div>
        </div>
    </div>

    {{-- ads.txt --}}
    <div class="nx-card p-5">
        <div class="mb-1 text-[15px] font-bold">ads.txt</div>
        <div class="mb-3 text-[12px] text-cream/45">
            AdSense ต้องอ่านไฟล์นี้ได้ที่ <a href="{{ url('/ads.txt') }}" target="_blank" class="underline hover:text-cream">{{ url('/ads.txt') }}</a>
            — วางบรรทัดที่ AdSense ให้มา (ปล่อยว่าง = ไม่ให้บริการไฟล์นี้)
        </div>
        <textarea name="ads_txt" rows="3" placeholder="google.com, pub-1234567890123456, DIRECT, f08c47fec0942fa0"
                  class="w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 font-mono text-[12.5px]">{{ old('ads_txt', $adsTxt) }}</textarea>
    </div>

    <button class="rounded-lg bg-brand px-5 py-2.5 text-[14px] font-semibold text-white hover:bg-brand/90">บันทึก</button>
</form>
@endsection
