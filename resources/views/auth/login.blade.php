@extends('layouts.guest')
@section('title', 'เข้าสู่ระบบ')

@section('content')
<div class="relative flex min-h-screen items-center justify-center overflow-hidden p-6"
     style="background:radial-gradient(circle at 12% 18%, rgba(255,45,85,0.22), transparent 42%), radial-gradient(circle at 88% 82%, rgba(139,47,240,0.24), transparent 46%), #07050c;">
    @include('partials.logo-bg', ['video' => 'logomedia1.mp4'])

    <form method="POST" action="{{ route('login') }}"
          class="relative z-10 w-[min(430px,100%)] rounded-2xl border border-white/10 bg-surface/85 p-10 backdrop-blur-xl"
          style="box-shadow:0 40px 90px rgba(0,0,0,0.55)">
        @csrf
        <img src="{{ asset('assets/netwix-wordmark.png') }}" alt="NetWix" class="mx-auto mb-9 h-16 w-auto">
        <h1 class="mb-6 text-center text-2xl font-bold">เข้าสู่ระบบ</h1>

        <div class="flex flex-col gap-3.5">
            <input type="email" name="email" value="{{ old('email') }}" placeholder="อีเมล" class="nx-input" required autofocus>
            <input type="password" name="password" placeholder="รหัสผ่าน" class="nx-input" required>
        </div>

        @error('email')<div class="mt-3.5 text-[13.5px] text-[#ff6b81]">{{ $message }}</div>@enderror

        <button type="submit" class="btn-brand mt-5 w-full py-3.5 text-base">เข้าสู่ระบบ</button>

        <div class="mt-4 flex items-center justify-between text-[13.5px] text-cream/50">
            <label class="flex cursor-pointer items-center gap-2"><input type="checkbox" name="remember" class="accent-brand"> จดจำฉันไว้</label>
            <span class="cursor-pointer">ลืมรหัสผ่าน?</span>
        </div>

        @include('auth.partials.social')

        <div class="mt-7 border-t border-white/10 pt-5 text-center text-sm text-cream/55">
            ยังไม่มีบัญชี? <a href="{{ route('register') }}" class="font-semibold text-cream underline">สมัครสมาชิกเลย</a>
        </div>
    </form>
</div>
@endsection
