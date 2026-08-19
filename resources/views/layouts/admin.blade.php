<!DOCTYPE html>
<html lang="th">
<head>
    @include('partials.head')
</head>
<body class="bg-ink text-cream antialiased">
@php
    $suspendedCount = \App\Support\PlaybackHealth::suspendedCount();
    try {
        $adPending = \App\Models\AdBooking::where('status', 'paid')->count();
    } catch (\Throwable $e) {
        $adPending = 0;   // pre-migrate — a nav badge must never break the whole admin
    }
    try {
        // Titles showing nothing but the branded fallback — no poster stored, or one a browser proved
        // it can't load (see [App\Http\Controllers\Admin\CoverController]).
        $missingCovers = \App\Models\Content::withoutGlobalScopes()
            ->where(fn ($q) => $q->whereNull('poster_path')->orWhere('poster_path', '')->orWhereNotNull('cover_missing_at'))
            ->count();
    } catch (\Throwable $e) {
        $missingCovers = 0;   // pre-migrate (no cover_missing_at column yet)
    }

    /**
     * The menu grew to 33 flat entries, at which point finding anything meant reading the whole
     * list. Grouped by the JOB being done rather than by which controller owns it — "why is this
     * title not playing" walks นำเข้า→ปัญหา→ลิ้งค์สำรอง, so those live together even though they're
     * separate features. Each group collapses; the one containing the current page opens itself.
     */
    $groups = [
        ['key' => 'content', 'label' => 'คอนเทนต์', 'icon' => '🎬', 'items' => [
            ['route' => 'admin.contents.index', 'label' => 'จัดการคอนเทนต์', 'badge' => \App\Models\Content::count()],
            ['route' => 'admin.genres.index', 'label' => 'หมวดหมู่'],
            ['route' => 'admin.thumbs.index', 'label' => 'สร้างปกตอน'],
            ['route' => 'admin.covers.index', 'label' => 'ปกที่หายไป'] + ($missingCovers > 0 ? ['badge' => $missingCovers, 'alert' => true] : []),
            ['route' => 'admin.storage.index', 'label' => 'จัดเก็บสื่อ'],
            ['route' => 'admin.comments.index', 'label' => 'ความคิดเห็น'],
        ]],
        ['key' => 'sources', 'label' => 'แหล่งหนัง & ลิ้งค์', 'icon' => '🔗', 'items' => [
            ['route' => 'admin.import.index', 'label' => 'นำเข้าหนัง'],
            ['route' => 'admin.import-logs.index', 'label' => 'ประวัตินำเข้า'],
            ['route' => 'admin.suspended.index', 'label' => 'หยุดเผยแพร่ (ปัญหา)'] + ($suspendedCount > 0 ? ['badge' => $suspendedCount, 'alert' => true] : []),
            ['route' => 'admin.backups.index', 'label' => 'หนังที่ใช้ลิ้งค์สำรอง'],
            ['route' => 'admin.force-link.index', 'label' => 'บังคับอัพเดทลิ้งค์'],
        ]],
        ['key' => 'ads', 'label' => 'โฆษณา', 'icon' => '📢', 'items' => [
            ['route' => 'admin.ad-market.index', 'label' => 'ขายโฆษณาให้ลูกค้า'] + ($adPending > 0 ? ['badge' => $adPending, 'alert' => true] : []),
            ['route' => 'admin.google-ads.index', 'label' => 'โฆษณา Google'],
            ['route' => 'admin.house-banners.index', 'label' => 'โฆษณาสำรอง (ของเราเอง)'],
            ['route' => 'admin.ads.index', 'label' => 'โฆษณาก่อนเล่น (Pre-roll)'],
        ]],
        ['key' => 'marketing', 'label' => 'การตลาด', 'icon' => '🚀', 'items' => [
            ['route' => 'admin.clips.index', 'label' => 'ตัดคลิป → เฟซบุ๊ก'],
            ['route' => 'admin.clip-campaigns.index', 'label' => 'แคมเปญคลิปอัตโนมัติ'],
            ['route' => 'admin.fb-dm.index', 'label' => 'DM ชวนดูหนัง'],
            ['route' => 'admin.announcements.index', 'label' => 'ข่าวสารหน้าแรก'],
        ]],
        ['key' => 'members', 'label' => 'สมาชิก & เงิน', 'icon' => '💎', 'items' => [
            ['route' => 'admin.users.index', 'label' => 'สมาชิก'],
            ['route' => 'admin.membership.index', 'label' => 'โปรโมชัน / รางวัล'],
            ['route' => 'admin.missions.index', 'label' => 'ภารกิจรับเหรียญ'],
            ['route' => 'admin.payments.index', 'label' => 'เหรียญทอง / ชำระ USDT'],
        ]],
        ['key' => 'app', 'label' => 'แอปมือถือ', 'icon' => '📱', 'items' => [
            ['route' => 'admin.app-notifications.index', 'label' => 'แจ้งเตือนในแอป'],
            ['route' => 'admin.app-banners.index', 'label' => 'แบนเนอร์ในแอป'],
            ['route' => 'admin.downloads.index', 'label' => 'ยอดดาวน์โหลดแอป'],
            ['route' => 'admin.app-stats.index', 'label' => 'สถิติแอป (อุปกรณ์)'],
            ['route' => 'admin.debug.index', 'label' => 'Debug แอป'],
        ]],
        ['key' => 'reports', 'label' => 'รายงาน', 'icon' => '📊', 'items' => [
            ['route' => 'admin.analytics', 'label' => 'วิเคราะห์ข้อมูล'],
            ['route' => 'admin.seo', 'label' => 'SEO / ทราฟฟิก'],
        ]],
        ['key' => 'system', 'label' => 'ระบบ', 'icon' => '⚙️', 'items' => [
            ['route' => 'admin.settings.index', 'label' => 'ตั้งค่า / เชื่อมต่อ'],
            ['route' => 'admin.line-alerts.index', 'label' => 'แจ้งเตือนปัญหาเข้า LINE'],
            ['route' => 'admin.legal.index', 'label' => 'นโยบาย / ข้อตกลง'],
        ]],
    ];

    // Match the CURRENT route to a group so it can be opened on load. Also used per-item; the
    // ".index" → ".*" widening keeps a child page (edit/create) highlighting its parent entry.
    $isOn = fn (string $r) => request()->routeIs($r) || request()->routeIs(str_replace('.index', '.*', $r));
    $openGroup = '';
    foreach ($groups as $g) {
        foreach ($g['items'] as $it) {
            if ($isOn($it['route'])) {
                $openGroup = $g['key'];
                break 2;
            }
        }
    }

    // Anything needing attention bubbles up to the group header, so a collapsed group still shouts.
    $groupAlerts = [];
    foreach ($groups as $g) {
        $groupAlerts[$g['key']] = collect($g['items'])->sum(fn ($i) => ($i['alert'] ?? false) ? (int) ($i['badge'] ?? 0) : 0);
    }

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
            {{-- Dashboard sits outside the groups: it's the landing page, not a category. --}}
            <a href="{{ route('admin.dashboard') }}"
               class="nx-glass {{ request()->routeIs('admin.dashboard') ? 'nx-glass-on' : '' }} flex items-center gap-3 px-3.5 py-2.5 text-sm font-semibold">
                <span class="text-[15px]">🏠</span>
                <span>แดชบอร์ด</span>
            </a>

            {{-- Grouped sections. `open` starts on the group holding the current page and is
                 remembered per admin, so the menu you left is the menu you come back to. --}}
            <div class="mt-1 space-y-1.5"
                 x-data="{
                     open: '{{ $openGroup }}',
                     init() {
                         const saved = localStorage.getItem('nx-admin-group');
                         if (!this.open && saved) this.open = saved;
                     },
                     toggle(k) {
                         this.open = this.open === k ? '' : k;
                         localStorage.setItem('nx-admin-group', this.open);
                     },
                 }">
                @foreach ($groups as $g)
                    <div>
                        <button type="button" @click="toggle('{{ $g['key'] }}')"
                                class="nx-glass flex w-full items-center gap-2.5 px-3 py-2.5 text-left text-[13px] font-semibold text-cream/80"
                                :class="open === '{{ $g['key'] }}' ? 'text-cream' : ''">
                            <span class="text-[15px]">{{ $g['icon'] }}</span>
                            <span class="flex-1">{{ $g['label'] }}</span>

                            @if (($groupAlerts[$g['key']] ?? 0) > 0)
                                <span x-show="open !== '{{ $g['key'] }}'"
                                      class="rounded-full bg-[#e5484d] px-1.5 py-0.5 text-[10px] font-bold text-white">{{ $groupAlerts[$g['key']] }}</span>
                            @endif

                            <svg class="h-3.5 w-3.5 shrink-0 text-cream/40 transition-transform duration-200"
                                 :class="open === '{{ $g['key'] }}' ? 'rotate-90' : ''"
                                 fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                <path d="M9 5l7 7-7 7" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>

                        {{-- x-transition rather than x-collapse: the collapse plugin isn't installed,
                             and a fade/slide reads just as well here without adding a dependency. --}}
                        <div x-show="open === '{{ $g['key'] }}'" x-cloak class="mt-1 space-y-1 pl-2.5"
                             x-transition:enter="transition ease-out duration-200"
                             x-transition:enter-start="opacity-0 -translate-y-1"
                             x-transition:enter-end="opacity-100 translate-y-0"
                             x-transition:leave="transition ease-in duration-120"
                             x-transition:leave-start="opacity-100"
                             x-transition:leave-end="opacity-0">
                            @foreach ($g['items'] as $item)
                                @php $active = $isOn($item['route']); @endphp
                                <a href="{{ route($item['route']) }}"
                                   class="nx-glass {{ $active ? 'nx-glass-on font-semibold text-cream' : 'text-cream/65' }} flex items-center gap-2.5 px-3 py-2 text-[13px]">
                                    <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $active ? 'bg-cream' : 'bg-cream/25' }}"></span>
                                    <span class="min-w-0 flex-1 truncate">{{ $item['label'] }}</span>
                                    @isset($item['badge'])
                                        <span class="shrink-0 rounded-full px-1.5 py-0.5 text-[10px] font-bold {{ ($item['alert'] ?? false) ? 'bg-[#e5484d] text-white' : 'bg-white/15 text-cream/80' }}">{{ $item['badge'] }}</span>
                                    @endisset
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
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
