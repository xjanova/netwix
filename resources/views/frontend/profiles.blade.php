@extends('layouts.guest')
@section('title', 'ใครกำลังดูอยู่?')

@section('content')
<div x-data="{ manage: false, add: false, color: '#8b2ff0',
        palette: ['#ff2d55','#b026ff','#8b2ff0','#00b8d4','#46d369','#f5c518','#ff8a3d','#e5484d'] }"
     class="relative flex min-h-screen flex-col items-center justify-center overflow-hidden p-10"
     style="background:radial-gradient(circle at 20% 20%, rgba(139,47,240,0.16), transparent 45%), #07050c;">

    <img src="{{ asset('assets/netwix-logo.png') }}" alt="NetWix" class="relative z-10 mb-14 h-10 w-auto">
    <h1 class="mb-11 text-center text-3xl font-semibold sm:text-4xl md:text-5xl">ใครกำลังดูอยู่?</h1>

    <div class="flex max-w-4xl flex-wrap justify-center gap-7">
        @foreach ($profiles as $profile)
            <div class="group flex flex-col items-center gap-3.5">
                <div class="relative">
                    <form method="POST" action="{{ route('profiles.select', $profile) }}">
                        @csrf
                        <button type="submit" x-show="!manage"
                                class="flex h-28 w-28 items-center justify-center rounded-2xl text-5xl font-bold text-black/55 outline-3 outline-transparent transition hover:outline-cream"
                                style="background: {{ $profile->avatar_color }}; outline-style:solid;">
                            {{ $profile->initial }}
                        </button>
                    </form>
                    <div x-show="manage" x-cloak
                         class="flex h-28 w-28 items-center justify-center rounded-2xl text-5xl font-bold text-black/40 opacity-60"
                         style="background: {{ $profile->avatar_color }}">{{ $profile->initial }}</div>

                    @if ($profile->is_kids)
                        <span class="absolute -right-2.5 -top-2 rounded bg-gold px-2 py-0.5 text-[10.5px] font-bold tracking-wider text-[#181111]">KIDS</span>
                    @endif

                    @if ($profiles->count() > 1)
                        <form method="POST" action="{{ route('profiles.destroy', $profile) }}"
                              x-show="manage" x-cloak class="absolute -right-2 -top-2"
                              onsubmit="return confirm('ลบโปรไฟล์ {{ $profile->name }}?')">
                            @csrf @method('DELETE')
                            <button class="flex h-7 w-7 items-center justify-center rounded-full bg-[#e5484d] text-sm">✕</button>
                        </form>
                    @endif
                </div>
                <div class="text-[17px] text-cream/75">{{ $profile->name }}</div>
            </div>
        @endforeach

        @if ($profiles->count() < 5)
            <button @click="add = true" class="flex flex-col items-center gap-3.5">
                <div class="flex h-28 w-28 items-center justify-center rounded-2xl border-2 border-dashed border-cream/30 text-4xl text-cream/35 transition hover:border-cream hover:text-cream">+</div>
                <div class="text-[17px] text-cream/50">เพิ่มโปรไฟล์</div>
            </button>
        @endif
    </div>

    <button @click="manage = !manage"
            class="mt-14 rounded border-[1.5px] border-cream/40 px-8 py-2.5 text-sm tracking-widest text-cream/70 transition hover:border-cream hover:text-cream"
            x-text="manage ? 'เสร็จสิ้น' : 'จัดการโปรไฟล์'"></button>

    {{-- Add profile modal --}}
    <div x-show="add" x-cloak class="fixed inset-0 z-[200] flex items-center justify-center p-6">
        <div class="absolute inset-0 bg-black/70" @click="add = false"></div>
        <form method="POST" action="{{ route('profiles.store') }}"
              class="relative w-[min(420px,100%)] rounded-2xl border border-white/10 bg-surface p-8">
            @csrf
            <h2 class="mb-5 text-xl font-bold">เพิ่มโปรไฟล์ใหม่</h2>
            <input type="text" name="name" placeholder="ชื่อโปรไฟล์" class="nx-input mb-4" maxlength="40" required>
            <input type="hidden" name="avatar_color" :value="color">
            <div class="mb-4">
                <div class="mb-2 text-sm text-cream/60">เลือกสี</div>
                <div class="flex flex-wrap gap-2.5">
                    <template x-for="c in palette" :key="c">
                        <button type="button" @click="color = c" class="h-9 w-9 rounded-lg"
                                :style="'background:'+c" :class="color === c ? 'ring-2 ring-cream' : ''"></button>
                    </template>
                </div>
            </div>
            <label class="mb-5 flex cursor-pointer items-center gap-2 text-sm text-cream/70">
                <input type="checkbox" name="is_kids" value="1" class="accent-brand"> โปรไฟล์สำหรับเด็ก (KIDS)
            </label>
            <div class="flex gap-3">
                <button type="submit" class="btn-brand flex-1 py-3">สร้างโปรไฟล์</button>
                <button type="button" @click="add = false" class="btn-ghost px-6 py-3">ยกเลิก</button>
            </div>
        </form>
    </div>
</div>
@endsection
