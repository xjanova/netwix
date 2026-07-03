import './bootstrap';
import Alpine from 'alpinejs';

/**
 * POST helper that carries the CSRF token and JSON headers.
 * Used by the my-list / like / watch-progress toggles.
 */
window.nxPost = async function (url, body = {}) {
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    const res = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token,
            'X-Requested-With': 'XMLHttpRequest',
            Accept: 'application/json',
        },
        body: JSON.stringify(body),
    });
    if (!res.ok) throw new Error('request failed: ' + res.status);
    return res.status === 204 ? null : res.json();
};

/**
 * Attach an HLS (.m3u8) or progressive source to a <video> element.
 * Lazily loads hls.js only when an .m3u8 needs it.
 */
window.nxAttachVideo = async function (video, src) {
    if (!video || !src) return;

    // Tear down a previous hls.js instance on this element (e.g. switching episodes) so loaders
    // don't pile up and leak.
    if (video._nxHls) { try { video._nxHls.destroy(); } catch (e) {} video._nxHls = null; }

    const isHls = /\.m3u8($|\?)/i.test(src);
    if (isHls && !video.canPlayType('application/vnd.apple.mpegurl')) {
        const { default: Hls } = await import('hls.js');
        if (Hls.isSupported()) {
            // Our HLS is a same-origin proxy in front of a high-bitrate, SINGLE-rendition CDN, so a
            // segment can be slow and there's no lower quality to fall back to. Buffer generously,
            // retry fragments hard, and — critically — recover from a *fatal* error instead of
            // freezing forever (the #1 cause of "it just stops loading half-way").
            const hls = new Hls({
                enableWorker: true,
                maxBufferLength: 30,
                maxMaxBufferLength: 600,
                backBufferLength: 30,
                fragLoadingTimeOut: 60000,
                fragLoadingMaxRetry: 8,
                fragLoadingRetryDelay: 800,
                manifestLoadingTimeOut: 30000,
                manifestLoadingMaxRetry: 4,
                levelLoadingTimeOut: 30000,
                nudgeMaxRetry: 12,
            });
            video._nxHls = hls;
            let mediaRecover = 0;
            hls.on(Hls.Events.ERROR, function (_evt, data) {
                if (!data || !data.fatal) return;   // non-fatal errors are retried by hls.js itself
                if (data.type === Hls.ErrorTypes.NETWORK_ERROR) {
                    try { hls.startLoad(); } catch (e) {}          // a proxy hiccup → resume loading
                } else if (data.type === Hls.ErrorTypes.MEDIA_ERROR) {
                    if (mediaRecover++ < 3) { try { hls.recoverMediaError(); } catch (e) {} }
                    else { try { hls.destroy(); } catch (e) {} video._nxHls = null; }
                } else {
                    try { hls.destroy(); } catch (e) {} video._nxHls = null;
                }
            });
            hls.loadSource(src);
            hls.attachMedia(video);
            return;
        }
    }
    // Direct MP4 (rongyok's Discord CDN, our stored clips): request it CORS so a <canvas> frame grab
    // isn't tainted — this is how we thumbnail each episode without a proxy (Discord reflects our
    // Origin, so it's allowed). If a source ever refuses CORS the initial load errors once and we
    // silently retry without it, so playback is never broken (we just skip that thumbnail).
    try { video.crossOrigin = 'anonymous'; } catch (e) {}
    let coRetried = false;
    const onCoErr = function () {
        if (coRetried || video.readyState >= 1) return;   // only an initial-load CORS failure
        coRetried = true;
        video.removeEventListener('error', onCoErr);
        video.removeAttribute('crossorigin');
        try { video.crossOrigin = null; } catch (e) {}
        video.src = src;
        video.load();
        video.play?.().catch(() => {});
    };
    video.addEventListener('error', onCoErr);
    video.src = src;
};

/**
 * Toggle real fullscreen on a player *container* (keeps our custom overlay UI,
 * unlike the native <video> fullscreen which drops it). Best-effort locks
 * orientation, and falls back to the iOS-only video.webkitEnterFullscreen when
 * element-fullscreen isn't available.
 */
window.nxToggleFullscreen = function (container, video, orientation) {
    const d = document;
    if (d.fullscreenElement || d.webkitFullscreenElement) {
        (d.exitFullscreen || d.webkitExitFullscreen || function () {}).call(d);
        try { screen.orientation && screen.orientation.unlock && screen.orientation.unlock(); } catch (e) {}
        return;
    }
    const req = container.requestFullscreen || container.webkitRequestFullscreen;
    if (req) {
        Promise.resolve(req.call(container)).then(function () {
            if (orientation && screen.orientation && screen.orientation.lock) {
                try { screen.orientation.lock(orientation).catch(function () {}); } catch (e) {}
            }
        }).catch(function () {
            if (video && video.webkitEnterFullscreen) video.webkitEnterFullscreen();
        });
    } else if (video && video.webkitEnterFullscreen) {
        video.webkitEnterFullscreen(); // iOS Safari: only the <video> itself can go fullscreen
    }
};

window.nxFullscreenActive = function () {
    return !!(document.fullscreenElement || document.webkitFullscreenElement);
};

/**
 * Horizontal content rail: arrow buttons, plus a desktop "hover the edge to auto-scroll" behaviour
 * (the closer the cursor is to the left/right edge, the faster it scrolls). Touch devices keep the
 * native swipe. Used by every content row via x-data="nxRail()".
 */
window.nxRail = () => ({
    _raf: null,
    _vel: 0,
    scroll(dir) {
        const r = this.$refs.rail;
        if (r) r.scrollBy({ left: dir * r.clientWidth * 0.85, behavior: 'smooth' });
    },
    edgeMove(e) {
        if (!window.matchMedia('(hover: hover) and (pointer: fine)').matches) return; // desktop only
        const r = this.$refs.rail.getBoundingClientRect();
        const frac = (e.clientX - r.left) / r.width;
        const zone = 0.16;
        let v = 0;
        if (frac < zone) v = -(1 - frac / zone);
        else if (frac > 1 - zone) v = (frac - (1 - zone)) / zone;
        this._vel = Math.max(-1, Math.min(1, v));
        if (this._vel && !this._raf) this._loop();
    },
    edgeLeave() { this._vel = 0; },
    _loop() {
        this._raf = requestAnimationFrame(() => {
            if (this._vel && this.$refs.rail) { this.$refs.rail.scrollLeft += this._vel * 26; this._loop(); }
            else { this._raf = null; }
        });
    },
});

window.Alpine = Alpine;
Alpine.start();

/**
 * Admin hover-zoom: any element carrying data-zoom-src shows a large, cursor-following
 * floating preview of that image on hover. Rendered at <body> level with position:fixed so it
 * never gets clipped by a table's overflow — used where posters/thumbnails are shown small
 * (จัดการคอนเทนต์ / จัดเก็บสื่อ). Pointer-events:none so it never steals the hover.
 */
(function () {
    let box;
    const ensure = () => {
        if (box) return box;
        box = document.createElement('div');
        box.style.cssText = 'position:fixed;z-index:9999;pointer-events:none;display:none;';
        box.innerHTML = '<img referrerpolicy="no-referrer" alt="" style="max-height:70vh;max-width:340px;width:auto;height:auto;border-radius:12px;box-shadow:0 16px 48px rgba(0,0,0,.65);outline:1px solid rgba(255,255,255,.16);display:block;background:#111;">';
        document.body.appendChild(box);
        return box;
    };
    const place = (e) => {
        const b = ensure();
        const pad = 20, w = 360, h = Math.min(window.innerHeight * 0.7, 520);
        let x = e.clientX + pad, y = e.clientY + pad;
        if (x + w > window.innerWidth) x = Math.max(8, e.clientX - w - pad);
        if (y + h > window.innerHeight) y = Math.max(8, window.innerHeight - h - 8);
        b.style.left = x + 'px';
        b.style.top = y + 'px';
    };
    document.addEventListener('mouseover', (e) => {
        const el = e.target.closest?.('[data-zoom-src]');
        if (!el) return;
        const src = el.getAttribute('data-zoom-src');
        if (!src) return;
        const b = ensure();
        b.firstChild.src = src;
        b.style.display = 'block';
        place(e);
    });
    document.addEventListener('mousemove', (e) => {
        if (!box || box.style.display === 'none') return;
        if (!e.target.closest?.('[data-zoom-src]')) { box.style.display = 'none'; return; }
        place(e);
    });
    document.addEventListener('mouseout', (e) => {
        if (box && e.target.closest?.('[data-zoom-src]')) box.style.display = 'none';
    });
})();
