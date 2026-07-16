@extends('layouts.admin')
@section('page-title', 'นโยบาย / ข้อตกลง')
@section('page-subtitle', 'แก้ไขข้อตกลงการใช้งานและนโยบายความเป็นส่วนตัว — มีผลทั้งหน้าเว็บ (/terms, /privacy) และหน้าอ่านในแอป')
@section('action')
    <div class="flex items-center gap-2">
        <a href="{{ route('terms') }}" target="_blank" class="rounded-lg bg-white/5 px-4 py-2.5 text-sm hover:bg-white/10">ดู /terms</a>
        <a href="{{ route('privacy') }}" target="_blank" class="rounded-lg bg-white/5 px-4 py-2.5 text-sm hover:bg-white/10">ดู /privacy</a>
    </div>
@endsection

@section('content')
<form method="POST" action="{{ route('admin.legal.update') }}" class="flex flex-col gap-6">
    @csrf @method('PUT')

    <div class="nx-card p-5">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <h3 class="text-base font-semibold">วันที่ปรับปรุงล่าสุด</h3>
            <input name="legal_updated_at" value="{{ $updated }}" placeholder="เช่น 16 กรกฎาคม 2569" class="nx-input w-64">
        </div>
        <p class="text-xs leading-relaxed text-cream/45">
            รูปแบบเนื้อหา: ขึ้นบรรทัดใหม่ = ย่อหน้าใหม่ · ขึ้นต้นบรรทัดด้วย <code class="rounded bg-white/10 px-1">## </code> = หัวข้อ ·
            ขึ้นต้นด้วย <code class="rounded bg-white/10 px-1">- </code> = รายการ · ปล่อยว่างทั้งช่อง = ใช้เนื้อหาเริ่มต้นที่ติดมากับระบบ
        </p>
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <div class="nx-card p-5">
            <h3 class="mb-3 text-base font-semibold">ข้อตกลงการใช้งาน (Terms)</h3>
            <textarea name="legal_terms_body" rows="24" class="nx-input w-full font-mono text-[13px] leading-relaxed" placeholder="ปล่อยว่างเพื่อใช้เนื้อหาเริ่มต้น">{{ $terms }}</textarea>
        </div>
        <div class="nx-card p-5">
            <h3 class="mb-3 text-base font-semibold">นโยบายความเป็นส่วนตัว (Privacy)</h3>
            <textarea name="legal_privacy_body" rows="24" class="nx-input w-full font-mono text-[13px] leading-relaxed" placeholder="ปล่อยว่างเพื่อใช้เนื้อหาเริ่มต้น">{{ $privacy }}</textarea>
        </div>
    </div>

    <div><button class="btn-brand px-8 py-3">บันทึกนโยบาย</button></div>
</form>
@endsection
