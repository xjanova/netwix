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

window.Alpine = Alpine;
Alpine.start();
