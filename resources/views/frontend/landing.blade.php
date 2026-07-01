@extends('layouts.guest')
@section('title', 'ดูหนังและซีรีส์ออนไลน์')

@section('content')
<div class="relative min-h-screen overflow-hidden"
     style="background:radial-gradient(circle at 15% 12%, rgba(255,45,85,0.22), transparent 45%), radial-gradient(circle at 85% 80%, rgba(139,47,240,0.24), transparent 48%), #07050c;">

    {{-- top bar --}}
    <header class="flex items-center justify-between px-[6vw] py-6">
        <img src="{{ asset('assets/netwix-logo.png') }}" alt="NetWix" class="h-8 w-auto">
        <a href="{{ route('login') }}" class="btn-brand px-6 py-2.5 text-sm">เข้าสู่ระบบ</a>
    </header>

    {{-- hero --}}
    <section class="mx-auto max-w-3xl px-6 py-20 text-center sm:py-28">
        <h1 class="text-4xl font-extrabold leading-tight sm:text-6xl">
            ภาพยนตร์ ซีรีส์ และ<span class="nx-gradient-text">ซีรีส์แนวตั้ง</span><br>ไม่มีสะดุด
        </h1>
        <p class="mx-auto mt-6 max-w-xl text-lg text-cream/70">
            ดูได้ทุกที่ทุกเวลา ยกเลิกได้ตลอด เริ่มต้นรับชมวันนี้กับ NetWix
        </p>
        <form method="GET" action="{{ route('register') }}" class="mx-auto mt-9 flex max-w-md flex-col gap-3 sm:flex-row">
            <input type="email" name="email" placeholder="กรอกอีเมลเพื่อเริ่มต้น" class="nx-input flex-1">
            <button type="submit" class="btn-brand whitespace-nowrap px-7 py-3.5">เริ่มเลย ›</button>
        </form>
    </section>

    {{-- feature strip --}}
    <section class="mx-auto grid max-w-5xl gap-5 px-6 pb-24 sm:grid-cols-3">
        @foreach ([
            ['ดูบนทุกอุปกรณ์', 'สตรีมบนทีวี แท็บเล็ต มือถือ และคอมพิวเตอร์'],
            ['NetWix Originals', 'คอนเทนต์ต้นฉบับที่หาดูที่อื่นไม่ได้'],
            ['ซีรีส์แนวตั้ง', 'ดูจบไวในไม่กี่นาที ปัดขึ้นลงเหมือนโซเชียล'],
        ] as [$t, $d])
            <div class="nx-card p-6">
                <div class="nx-gradient mb-3 h-1.5 w-10 rounded"></div>
                <h3 class="mb-1.5 text-lg font-semibold">{{ $t }}</h3>
                <p class="text-sm text-cream/60">{{ $d }}</p>
            </div>
        @endforeach
    </section>
</div>
@endsection
