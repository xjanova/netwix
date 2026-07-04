@extends('layouts.guest')
@section('title', 'สมัครสมาชิก')

@section('content')
<div class="relative flex min-h-screen items-center justify-center overflow-hidden p-6"
     style="background:radial-gradient(circle at 12% 18%, rgba(255,45,85,0.22), transparent 42%), radial-gradient(circle at 88% 82%, rgba(139,47,240,0.24), transparent 46%), #07050c;">
    @include('partials.logo-bg', ['video' => 'logomedia1.mp4'])

    <form method="POST" action="{{ route('register') }}"
          class="relative z-10 w-[min(430px,100%)] rounded-2xl border border-white/10 bg-surface/85 p-10 backdrop-blur-xl"
          style="box-shadow:0 40px 90px rgba(0,0,0,0.55)">
        @csrf
        <img src="{{ asset('assets/netwix-wordmark.png') }}" alt="NetWix" class="mx-auto mb-8 h-16 w-auto">
        <h1 class="mb-2 text-center text-2xl font-bold">สมัครสมาชิก</h1>

        @if ($emailReg ?? true)
            <input type="hidden" name="ref" value="{{ old('ref', request('ref')) }}">
            <div class="mt-5 flex flex-col gap-3.5">
                <input type="text" name="name" value="{{ old('name') }}" placeholder="ชื่อของคุณ" class="nx-input" required autofocus>
                <input type="email" name="email" value="{{ old('email', request('email')) }}" placeholder="อีเมล" class="nx-input" required>
                <input type="password" name="password" placeholder="รหัสผ่าน (อย่างน้อย 8 ตัว)" class="nx-input" required>
                <input type="password" name="password_confirmation" placeholder="ยืนยันรหัสผ่าน" class="nx-input" required>
            </div>

            @if ($errors->any())
                <div class="mt-3.5 text-[13.5px] text-[#ff6b81]">{{ $errors->first() }}</div>
            @endif

            @include('partials.turnstile')

            <button type="submit" class="btn-brand mt-5 w-full py-3.5 text-base">สร้างบัญชี</button>
        @else
            <p class="mb-1 mt-3 text-center text-[15px] text-cream/70">สมัครและเข้าสู่ระบบด้วยบัญชีโซเชียล</p>
            @if ($errors->any())
                <div class="mt-2 text-center text-[13.5px] text-[#ff6b81]">{{ $errors->first() }}</div>
            @endif
        @endif

        @include('auth.partials.social')

        @unless ($emailReg ?? true)
            @unless (filled(config('services.google.client_id')) || filled(config('services.line.client_id')))
                <div class="mt-4 rounded-lg border border-white/10 bg-white/[0.03] p-4 text-center text-[13.5px] leading-relaxed text-cream/60">
                    การสมัครด้วย Google / LINE กำลังเปิดให้บริการเร็ว ๆ นี้ 🎉
                </div>
            @endunless
        @endunless

        <div class="mt-7 border-t border-white/10 pt-5 text-center text-sm text-cream/55">
            มีบัญชีอยู่แล้ว? <a href="{{ route('login') }}" class="font-semibold text-cream underline">เข้าสู่ระบบ</a>
        </div>
    </form>
</div>
@endsection
