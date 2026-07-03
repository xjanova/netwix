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
    const isHls = /\.m3u8($|\?)/i.test(src);
    if (isHls && !video.canPlayType('application/vnd.apple.mpegurl')) {
        const { default: Hls } = await import('hls.js');
        if (Hls.isSupported()) {
            const hls = new Hls({ enableWorker: true });
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
