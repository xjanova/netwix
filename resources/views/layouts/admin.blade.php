<!DOCTYPE html>
<html lang="th">
<head>
    @include('partials.head')
</head>
<body class="bg-ink text-cream antialiased">
@php
    $suspendedCount = \App\Support\PlaybackHealth::suspendedCount();
    $nav = [
        ['route' => 'admin.dashboard', 'label' => 'แดชบอร์ด'],
        ['route' => 'admin.contents.index', 'label' => 'จัดการคอนเทนต์', 'badge' => \App\Models\Content::count()],
        ['route' => 'admin.suspended.index', 'label' => 'หยุดเผยแพร่ (ปัญหา)'] + ($suspendedCount > 0 ? ['badge' => $suspendedCount, 'alert' => true] : []),
        ['route' => 'admin.backups.index', 'label' => 'หนังที่ใช้ลิ้งค์สำรอง'],
        ['route' => 'admin.force-link.index', 'label' => 'บังคับอัพเดทลิ้งค์'],
        ['route' => 'admin.import.index', 'label' => 'นำเข้าหนัง'],
        ['route' => 'admin.storage.index', 'label' => 'จัดเก็บสื่อ'],
        ['route' => 'admin.thumbs.index', 'label' => 'สร้างปกตอน'],
        ['route' => 'admin.clips.index', 'label' => 'ตัดคลิป → เฟซบุ๊ก'],
        ['route' => 'admin.clip-campaigns.index', 'label' => 'แคมเปญคลิปอัตโนมัติ'],
        ['route' => 'admin.genres.index', 'label' => 'หมวดหมู่'],
        ['route' => 'admin.announcements.index', 'label' => 'ข่าวสารหน้าแรก'],
        ['route' => 'admin.comments.index', 'label' => 'ความคิดเห็น'],
        ['route' => 'admin.users.index', 'label' => 'สมาชิก'],
        ['route' => 'admin.settings.index', 'label' => 'ตั้งค่า / เชื่อมต่อ'],
        ['route' => 'admin.membership.index', 'label' => 'โปรโมชัน / รางวัล'],
        ['route' => 'admin.payments.index', 'label' => 'เหรียญทอง / ชำระ USDT'],
        ['route' => 'admin.analytics', 'label' => 'วิเคราะห์ข้อมูล'],
        ['route' => 'admin.seo', 'label' => 'SEO / ทราฟฟิก'],
        ['route' => 'admin.debug.index', 'label' => 'Debug แอป'],
    ];
    $admin = auth()->user();
@endphp
<div x-data="{ sidebar: false }" class="flex min-h-screen">
    {{-- Sidebar --}}
    <aside class="fixed inset-y-0 left-0 z-40 flex w-60 flex-col border-r border-white/5 p-5 transition-transform lg:sticky lg:top-0 lg:h-screen lg:translate-x-0"
           :class="sidebar ? 'translate-x-0' : '-translate-x-full'"
           style="background:linear-gradient(180deg,#0d0913 0%,#0a0710 100%)">
        <div class="flex items-center gap-2.5 px-2">
            <img src="{{ asset('assets/netwix-wordmark.png') }}" alt="NetWix" class="h-8 w-auto">
            <span class="rounded border border-white/15 px-1.5 py-0.5 text-[11px] font-semibold tracking-widest text-cream/40">ADMIN</span>
        </div>

        <nav class="mt-8 flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto pr-1" style="scrollbar-gutter:stable">
            @foreach ($nav as $item)
                @php $active = request()->routeIs($item['route']) || request()->routeIs(str_replace('.index', '.*', $item['route'])); @endphp
                <a href="{{ route($item['route']) }}"
                   class="flex items-center gap-3 rounded-lg px-3.5 py-2.5 text-sm transition {{ $active ? 'bg-white/[0.07] font-semibold text-cream' : 'text-cream/60 hover:bg-white/5 hover:text-cream' }}">
                    <span class="h-1.5 w-1.5 rounded-full {{ $active ? 'nx-gradient' : 'bg-cream/25' }}"></span>
                    <span>{{ $item['label'] }}</span>
                    @isset($item['badge'])
                        <span class="ml-auto rounded-full px-2 py-0.5 text-[10.5px] font-bold {{ ($item['alert'] ?? false) ? 'bg-[#e5484d] text-white' : 'nx-gradient' }}">{{ $item['badge'] }}</span>
                    @endisset
                </a>
            @endforeach
        </nav>

        <a href="{{ route('browse') }}" class="mt-4 px-3.5 text-[13px] text-cream/45 hover:text-cream">← กลับหน้าเว็บ</a>

        <div class="mt-auto flex items-center gap-2.5 rounded-xl border border-white/5 bg-white/[0.03] p-3">
            <span class="flex h-9 w-9 items-center justify-center rounded-lg font-bold text-black/60" style="background:#b026ff">{{ mb_substr($admin->name, 0, 1) }}</span>
            <div class="min-w-0">
                <div class="truncate text-[13px] font-semibold">{{ $admin->name }}</div>
                <div class="text-[11px] text-cream/45">Super Admin</div>
            </div>
        </div>
    </aside>

    <div x-show="sidebar" x-cloak class="fixed inset-0 z-30 bg-black/50 lg:hidden" @click="sidebar = false"></div>

    {{-- Main --}}
    <div class="flex min-w-0 flex-1 flex-col">
        <header class="sticky top-0 z-20 flex items-center justify-between border-b border-white/5 bg-ink/85 px-6 py-4 backdrop-blur sm:px-8">
            <div class="flex items-center gap-3">
                <button class="lg:hidden" @click="sidebar = true">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <div>
                    <h1 class="text-xl font-bold sm:text-[22px]">@yield('page-title', 'แดชบอร์ด')</h1>
                    <div class="text-[13px] text-cream/45">@yield('page-subtitle', 'ภาพรวมระบบ NetWix')</div>
                </div>
            </div>
            <div class="flex items-center gap-3">
                @hasSection('action')@yield('action')@else
                    <a href="{{ route('admin.contents.create') }}" class="nx-gradient flex items-center gap-1.5 rounded-lg px-4 py-2.5 text-sm font-semibold" style="box-shadow:0 8px 22px rgba(176,38,255,0.32)">+ เพิ่มคอนเทนต์</a>
                @endif
            </div>
        </header>

        <div class="flex-1 px-6 py-7 sm:px-8">
            @if (session('status'))
                <div class="mb-5 rounded-lg border border-success/30 bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="mb-5 rounded-lg border border-[#ff6b81]/30 bg-[#ff6b81]/10 px-4 py-3 text-sm text-[#ff6b81]">{{ $errors->first() }}</div>
            @endif

            @yield('content')
        </div>
    </div>
</div>
<style>[x-cloak]{display:none !important;}</style>
@stack('scripts')
</body>
</html>
