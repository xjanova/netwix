<!DOCTYPE html>
<html lang="th">
<head>
    @include('partials.head')
</head>
<body class="bg-ink text-cream antialiased min-h-screen">
    @include('partials.nav')

    <main>
        @yield('content')
    </main>

    @include('partials.site-footer')

    {{-- Title detail modal host --}}
    <div
        x-data="titleModal()"
        x-on:open-title.window="open($event.detail)"
        x-on:close-title.window="close()"
        x-on:keydown.escape.window="close()"
        x-show="visible"
        x-cloak
        style="display:none"
        class="fixed inset-0 z-[200] overflow-y-auto"
    >
        <div class="fixed inset-0 bg-black/70" x-on:click="close()"></div>
        <div class="relative mx-auto my-8 w-[min(920px,94vw)]">
            <div class="nx-card overflow-hidden" x-html="html"></div>
        </div>
    </div>

    @include('partials.cookie-consent')

    @stack('scripts')

    <script>
        function titleModal() {
            return {
                visible: false,
                html: '<div class="p-16 text-center text-cream/60">กำลังโหลด…</div>',
                async open(url) {
                    this.visible = true;
                    document.body.style.overflow = 'hidden';
                    this.html = '<div class="p-16 text-center text-cream/60">กำลังโหลด…</div>';
                    try {
                        const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                        this.html = await res.text();
                    } catch (e) {
                        this.html = '<div class="p-16 text-center text-cream/60">โหลดข้อมูลไม่สำเร็จ</div>';
                    }
                },
                close() {
                    this.visible = false;
                    document.body.style.overflow = '';
                    // tear down the injected content so its hero <video> stops buffering/playing and its
                    // IntersectionObserver disconnects — otherwise every opened title leaks a background
                    // video + observer and the tab eventually hangs after a long session.
                    this.html = '';
                },
            };
        }
    </script>
    <style>[x-cloak]{display:none !important;}</style>
</body>
</html>
