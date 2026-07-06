<!DOCTYPE html>
<html lang="th">
<head>
    @include('partials.head')
</head>
<body class="text-cream antialiased min-h-screen">
    {{-- Ambient "dream fibers" background (see nxDreamBg in app.js). The base ink colour lives on
         <html> so this fixed canvas at z-index:-1 shows through the transparent body. --}}
    <style>
        html { background: #07050c; }
        #nx-dream { position: fixed; inset: 0; z-index: -1; pointer-events: none; display: block; }
    </style>
    <canvas id="nx-dream" aria-hidden="true"></canvas>
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
                    // Freeze every background preview (poster-card clips + the hero billboard) while the
                    // modal is open. They sit hidden behind it but their IntersectionObserver still thinks
                    // they're on-screen, so they keep decoding — and on phones (a tiny media-element
                    // budget) 200 vertical-card clips starve the modal's OWN preview, which then reloads
                    // and loses its mute state (owner: กดลำโพงแล้วเสียงไม่ปิด/วิดีโอเปลี่ยนไปเรื่อยๆ).
                    window.nxPreviewsSuspended = true;
                    window.dispatchEvent(new Event('nx-previews-suspend'));
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
                    window.nxPreviewsSuspended = false;
                    window.dispatchEvent(new Event('nx-previews-resume'));
                },
            };
        }
    </script>
    <style>[x-cloak]{display:none !important;}</style>
</body>
</html>
