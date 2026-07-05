@extends('layouts.guest')
@section('title', 'ตั้งค่าผู้ดูแลหลัก')

@section('content')
<div class="relative flex min-h-screen items-center justify-center overflow-hidden p-6"
     style="background:radial-gradient(circle at 12% 18%, rgba(255,45,85,0.22), transparent 42%), radial-gradient(circle at 88% 82%, rgba(139,47,240,0.24), transparent 46%);">
    @include('partials.logo-bg', ['video' => 'logomedia1.mp4'])

    <form method="POST" action="{{ route('setup') }}"
          class="relative z-10 w-[min(460px,100%)] rounded-2xl border border-white/10 bg-surface/85 p-10 backdrop-blur-xl"
          style="box-shadow:0 40px 90px rgba(0,0,0,0.55)">
        @csrf
        <img src="{{ asset('assets/netwix-wordmark.png') }}" alt="NetWix" class="mx-auto mb-7 h-14 w-auto">
        <div class="nx-gradient mx-auto mb-4 w-fit rounded px-2.5 py-1 text-[11px] font-bold tracking-widest">FIRST-RUN SETUP</div>
        <h1 class="mb-2 text-center text-2xl font-bold">ตั้งค่าผู้ดูแลหลัก</h1>
        <p class="mb-6 text-center text-sm text-cream/55">สร้างบัญชีผู้ดูแลระบบบัญชีแรกของ NetWix<br>หน้านี้จะปิดอัตโนมัติเมื่อมีผู้ดูแลแล้ว</p>

        <div class="flex flex-col gap-3.5">
            <input type="text" name="name" value="{{ old('name') }}" placeholder="ชื่อผู้ดูแล" class="nx-input" required autofocus>
            <input type="email" name="email" value="{{ old('email') }}" placeholder="อีเมล" class="nx-input" required>
            <input type="password" name="password" placeholder="รหัสผ่าน (อย่างน้อย 10 ตัว มีตัวอักษรและตัวเลข)" class="nx-input" required>
            <input type="password" name="password_confirmation" placeholder="ยืนยันรหัสผ่าน" class="nx-input" required>
        </div>

        @if ($errors->any())
            <div class="mt-3.5 text-[13.5px] text-[#ff6b81]">{{ $errors->first() }}</div>
        @endif

        <button type="submit" class="btn-brand mt-5 w-full py-3.5 text-base">สร้างบัญชีผู้ดูแล</button>
    </form>
</div>
@endsection
