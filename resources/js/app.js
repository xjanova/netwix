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
window.nxAttachVideo = async function (video, src, reportUrl = null) {
    if (!video || !src) return;

    // Report playback health once per attach — the server auto-suspends a title once enough distinct
    // viewers can't play it. Only the real watch/vertical players pass reportUrl; previews don't.
    let _reported = false;
    const nxReport = (ok) => {
        if (!reportUrl || _reported) return;
        _reported = true;
        try { window.nxPost(reportUrl, { ok: ok }).catch(() => {}); } catch (e) {}
    };

    // Tear down a previous hls.js instance on this element (e.g. switching episodes) so loaders
    // don't pile up and leak.
    if (video._nxHls) { try { video._nxHls.destroy(); } catch (e) {} video._nxHls = null; }

    const isHls = /\.m3u8($|\?)/i.test(src);
    if (isHls) {
        // Prefer hls.js wherever it's supported. Chrome/Firefox return canPlayType('…mpegurl') ===
        // 'maybe' but CANNOT actually play HLS natively — so never gate on canPlayType (that made the
        // web set video.src to a raw .m3u8 and freeze). Only fall through to the native <video> path
        // (Safari/iOS, which really does play HLS) when hls.js itself isn't supported.
        let Hls = null;
        try { Hls = (await import('hls.js')).default; } catch (e) {}
        if (Hls && Hls.isSupported()) {
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
            let mediaRecover = 0, netRetry = 0;
            hls.on(Hls.Events.FRAG_BUFFERED, function () { nxReport(true); }); // a segment played → alive
            hls.on(Hls.Events.ERROR, function (_evt, data) {
                if (!data || !data.fatal) return;   // non-fatal errors are retried by hls.js itself
                if (data.type === Hls.ErrorTypes.NETWORK_ERROR) {
                    // A dead manifest (404/timeout) never recovers by reloading — report it + stop,
                    // instead of looping startLoad forever (which is what "the source is gone" looks like).
                    const dead = /manifestLoad/i.test(data.details || '');
                    if (dead || netRetry++ >= 4) { nxReport(false); try { hls.destroy(); } catch (e) {} video._nxHls = null; }
                    else { try { hls.startLoad(); } catch (e) {} }   // a transient proxy hiccup → resume
                } else if (data.type === Hls.ErrorTypes.MEDIA_ERROR) {
                    if (mediaRecover++ < 3) { try { hls.recoverMediaError(); } catch (e) {} }
                    else { nxReport(false); try { hls.destroy(); } catch (e) {} video._nxHls = null; }
                } else {
                    nxReport(false); try { hls.destroy(); } catch (e) {} video._nxHls = null;
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
    video.addEventListener('playing', () => nxReport(true), { once: true });
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
    // Optional lazy-load: a rail that carries data-lazy-url keeps fetching the next page of cards as
    // you slide toward the right end, so a genre row can browse EVERY title in it (page 1 is rendered
    // server-side; we pull page 2+). Rows without data-lazy-url behave exactly as before.
    lazyPage: 2,
    lazyDone: false,
    lazyLoading: false,
    _lz: null,
    init() {
        const r = this.$refs.rail;
        if (r && r.dataset.lazyUrl) {
            this._lz = { url: r.dataset.lazyUrl, type: r.dataset.lazyType || '', genre: r.dataset.lazyGenre || '', scope: r.dataset.lazyScope || '', seed: r.dataset.lazySeed || '' };
            this.$nextTick(() => this.lazyFill());
        } else {
            this.lazyDone = true;
        }
    },
    async lazyMore() {
        if (this.lazyLoading || this.lazyDone || !this._lz) return;
        this.lazyLoading = true;
        try {
            const u = new URL(this._lz.url, location.origin);
            ['type', 'genre', 'scope', 'seed'].forEach((k) => { if (this._lz[k]) u.searchParams.set(k, this._lz[k]); });
            u.searchParams.set('page', this.lazyPage);
            const res = await fetch(u, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const d = await res.json();
            if (d && d.html) this.$refs.rail.insertAdjacentHTML('beforeend', d.html);
            this.lazyPage = d.next;
            this.lazyDone = !!d.done;
        } catch (e) { /* keep the row usable on a transient error */ } finally {
            this.lazyLoading = false;
            this.$nextTick(() => this.lazyFill());
        }
    },
    // keep pulling pages until the rail actually overflows, so a wide screen never shows a short stub
    lazyFill() {
        const r = this.$refs.rail;
        if (!r || this.lazyLoading || this.lazyDone) return;
        if (r.scrollWidth <= r.clientWidth + 320) this.lazyMore();
    },
    // fetch the next page as the viewer nears the right end (drag, wheel, arrows, or edge-glide)
    onScroll() {
        const r = this.$refs.rail;
        if (r && r.scrollLeft + r.clientWidth >= r.scrollWidth - 800) this.lazyMore();
    },
    scroll(dir) {
        const r = this.$refs.rail;
        if (!r) return;
        this._vel = 0;                                       // cancel any edge glide
        // animate scrollLeft ourselves — native scrollBy({behavior:'smooth'}) no-ops on this flex rail
        const start = r.scrollLeft, dist = dir * r.clientWidth * 0.85, t0 = performance.now(), dur = 340;
        const step = (now) => {
            const p = Math.min(1, (now - t0) / dur);
            r.scrollLeft = start + dist * (1 - Math.pow(1 - p, 3));   // easeOutCubic
            if (p < 1) requestAnimationFrame(step);
        };
        requestAnimationFrame(step);
    },
    edgeMove(e) {
        if (!window.matchMedia('(any-hover: hover) and (any-pointer: fine)').matches) return; // needs a mouse (true on touch-capable PCs too, false on phones)
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
    // Hovering an arrow glides the row that way at full speed — no matchMedia gate (the arrows are
    // desktop-only via `hidden lg:flex`), so this works even where the pointer media query misreports.
    edgeStart(dir) { this._vel = dir; if (!this._raf) this._loop(); },
    edgeStop() { this._vel = 0; },
    _loop() {
        this._raf = requestAnimationFrame(() => {
            if (this._vel && this.$refs.rail) { this.$refs.rail.scrollLeft += this._vel * 26; this._loop(); }
            else { this._raf = null; }
        });
    },
});

/**
 * Landing-page social-proof counter: animates 0 → real value the first time it
 * scrolls into view, then keeps drifting gently upward so the platform always
 * feels alive and growing (the "numbers keep ticking up" psychology). The base
 * value is the real server total; `drift`/`every` control the live growth.
 *
 *   x-data="nxCounter(48210, { drift: 5, every: 1400 })"  x-init="init()"
 *     <span x-text="formatted">48,210</span>
 *   drift — max random amount added each live tick (0 = count-up only, no growth)
 *   every — ms between live ticks (jittered ±100% so it never looks robotic)
 */
window.nxCounter = (value, opts = {}) => ({
    target: Math.max(0, Math.round(value) || 0),
    display: Math.max(0, Math.round(value) || 0),
    drift: opts.drift ?? 0,
    every: opts.every ?? 1000,
    started: false,
    init() {
        // Respect users who asked for less motion: show the real number, skip animation.
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            this.display = this.target;
            if (this.drift > 0) this.live();
            return;
        }
        const io = new IntersectionObserver((entries) => {
            entries.forEach((e) => {
                if (e.isIntersecting && !this.started) {
                    this.started = true;
                    this.countUp();
                    io.disconnect();
                }
            });
        }, { threshold: 0.35 });
        io.observe(this.$el);
    },
    countUp() {
        const to = this.target, dur = 1600, t0 = performance.now();
        const ease = (t) => 1 - Math.pow(1 - t, 3); // easeOutCubic
        const step = (now) => {
            const p = Math.min(1, (now - t0) / dur);
            this.display = Math.round(to * ease(p));
            if (p < 1) requestAnimationFrame(step);
            else this.live();
        };
        requestAnimationFrame(step);
    },
    live() {
        if (this.drift <= 0) return;
        const tick = () => {
            this.target += Math.floor(Math.random() * this.drift) + 1;
            this.display = this.target;
            setTimeout(tick, this.every + Math.random() * this.every);
        };
        setTimeout(tick, this.every + Math.random() * this.every);
    },
    get formatted() {
        return this.display.toLocaleString('en-US');
    },
});

/**
 * Silent hover/scroll preview for a poster card (the vertical-browse 9:16 cards reuse this; the
 * 16:9 content-card has its own inline copy). Mouse device → plays the ep1 clip while hovered;
 * touch → plays while the card is well-centred on screen. Lazy: src is set only the first time shown.
 *   x-data="nxCardPreview(@js($content->preview_url))" x-init="$nextTick(() => initPreview())"
 */
window.nxCardPreview = (src) => ({
    src: src || null,
    hv: false,
    playing: false,
    io: null,
    playT: null,
    reduced: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
    hoverCapable: window.matchMedia('(any-hover: hover) and (any-pointer: fine)').matches,
    initPreview() {
        const v = this.$refs.clip;
        if (!v || !this.src || this.reduced || this.hoverCapable) return;   // hover devices use @mouseenter
        this.io = new IntersectionObserver((e) => {
            (e[0].isIntersecting && e[0].intersectionRatio >= 0.75) ? this.arm() : this.release();
        }, { threshold: [0, 0.75] });
        this.io.observe(this.$root);
    },
    arm() {
        if (this.playing || !this.src) return;
        this.playing = true;
        const v = this.$refs.clip;
        this.playT = setTimeout(() => {
            if (!this.playing) return;
            if (!v.src) v.src = v.dataset.src;                 // lazy: fetch only once shown
            v.play().then(() => { if (this.playing) this.hv = true; }).catch(() => {});
        }, 180);
    },
    release() {
        this.playing = false;
        clearTimeout(this.playT);
        this.hv = false;
        const v = this.$refs.clip;
        if (v) { v.pause(); v.removeAttribute('src'); v.load(); }   // free the buffer off-screen
    },
});

/**
 * Title-modal header preview (episode-1 clip). Plays continuously behind the title/info like a
 * moving background (owner: "ให้วีดีโอเล่นไปเหมือนแบล็กกราวด์" — does NOT pause on scroll). Plays
 * WITH sound by default — muted for 18+/20+ — with a mute/unmute toggle. Autoplay-with-sound that
 * the browser blocks falls back to muted so the clip always plays; the toggle (a real gesture) can
 * turn sound on. IMPORTANT: no x-init — Alpine auto-calls init() once; adding x-init double-inits
 * (two resolves + two <video> loads) and hard-freezes the renderer.
 *   x-data="heroPreview({ src, resolve, adult })"
 */
window.heroPreview = (cfg) => ({
    muted: !!cfg.adult,        // 18+/20+ → muted by default; everything else starts with sound
    ready: false,
    async init() {
        const v = this.$refs.hero;
        if (!v) return;
        let url = cfg.src;
        if (!url && cfg.resolve) {
            try {
                const r = await fetch(cfg.resolve, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const d = await r.json();
                if (d && d.ready && d.url) url = d.url;
            } catch (e) {}
        }
        if (!url) return;
        window.nxAttachVideo(v, url);
        v.muted = this.muted;
        this.ready = true;
        this.tryPlay();        // loops + keeps playing as the header background; no pause-on-scroll
    },
    tryPlay() {
        const v = this.$refs.hero;
        const p = v.play?.();
        if (p && p.catch) p.catch(() => {                       // autoplay-with-sound blocked
            if (!v.muted) { v.muted = true; this.muted = true; v.play?.().catch(() => {}); }
        });
    },
    toggleMute() {
        const v = this.$refs.hero;
        this.muted = !this.muted;
        v.muted = this.muted;
        // ALWAYS keep it playing — muting must never pause the background clip (that was the bug:
        // clicking 🔊→🔇 paused the video). play() is safe on an already-playing element.
        v.play?.().catch(() => {});
    },
    // Alpine calls this when the element is removed (modal close/replace) — stop + free the video so
    // nothing lingers/buffers in the background.
    destroy() {
        const v = this.$refs.hero;
        if (v) { try { v.pause(); v.removeAttribute('src'); v.load(); } catch (e) {} }
    },
});

/**
 * Pause every playing <video> while the tab is hidden and resume them on return. A tab left open in
 * the background keeps decoding looping previews/players otherwise, and that resource buildup is what
 * makes the page hang / go blank after a long time. Remembering what was playing keeps it seamless.
 */
document.addEventListener('visibilitychange', () => {
    const hidden = document.hidden;
    document.querySelectorAll('video').forEach((v) => {
        if (hidden) {
            if (!v.paused && !v.ended) { v.dataset.nxResume = '1'; v.pause(); }
        } else if (v.dataset.nxResume) {
            delete v.dataset.nxResume;
            v.play?.().catch(() => {});
        }
    });
});

window.Alpine = Alpine;
Alpine.start();

/**
 * Ambient "dream fibers" — a full-page flow-field of glowing filaments in the NetWix pink/purple,
 * drifting slowly behind everything so the page never reads as flat black. Fading trails + additive
 * blending make the streaks look like silk threads. Cheap (a few dozen 1px line segments/frame),
 * pauses on a hidden tab, and skips entirely when the user asked for reduced motion.
 */
function nxDreamBg() {
    const c = document.getElementById('nx-dream');
    if (!c || (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches)) return;
    const ctx = c.getContext('2d');
    const cols = ['#ff2d55', '#b026ff', '#8b2ff0'];
    let W, H, DPR, parts = [], raf = null;
    const resize = () => {
        DPR = Math.min(1.75, window.devicePixelRatio || 1);
        W = c.width = Math.floor(innerWidth * DPR); H = c.height = Math.floor(innerHeight * DPR);
        c.style.width = innerWidth + 'px'; c.style.height = innerHeight + 'px';
        const n = Math.round(Math.min(64, innerWidth / 24));
        parts = Array.from({ length: n }, () => ({
            x: Math.random() * W, y: Math.random() * H, col: cols[(Math.random() * cols.length) | 0],
            w: (0.5 + Math.random() * 1.1) * DPR, spd: (0.5 + Math.random() * 0.9) * DPR,
        }));
        ctx.fillStyle = '#07050c'; ctx.fillRect(0, 0, W, H);
    };
    const angle = (x, y, t) => Math.sin(x * 0.0016 + t * 0.00018) * 1.7 + Math.cos(y * 0.0018 - t * 0.00015) * 1.7;
    const frame = (t) => {
        ctx.fillStyle = 'rgba(7,5,12,0.05)'; ctx.fillRect(0, 0, W, H);   // fade → trails
        ctx.globalCompositeOperation = 'lighter';
        for (const p of parts) {
            const a = angle(p.x, p.y, t);
            const nx = p.x + Math.cos(a) * p.spd * 2.4, ny = p.y + Math.sin(a) * p.spd * 2.4;
            ctx.strokeStyle = p.col; ctx.globalAlpha = 0.085; ctx.lineWidth = p.w;
            ctx.beginPath(); ctx.moveTo(p.x, p.y); ctx.lineTo(nx, ny); ctx.stroke();
            p.x = nx; p.y = ny;
            if (p.x < 0 || p.x > W || p.y < 0 || p.y > H) { p.x = Math.random() * W; p.y = Math.random() * H; }
        }
        ctx.globalCompositeOperation = 'source-over'; ctx.globalAlpha = 1;
        raf = requestAnimationFrame(frame);
    };
    let rt; addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(resize, 200); });
    resize(); raf = requestAnimationFrame(frame);
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) { cancelAnimationFrame(raf); raf = null; }
        else if (!raf) raf = requestAnimationFrame(frame);
    });
}

/**
 * Footer fireflies — warm little lights that drift up and gently blink, contained to the footer.
 * Soft radial glow so it feels magical without stealing attention from the links.
 */
function nxFireflies() {
    const c = document.getElementById('nx-fireflies');
    if (!c || (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches)) return;
    const ctx = c.getContext('2d');
    let W, H, DPR, flies = [], raf = null, t = 0;
    const spawn = () => ({
        x: Math.random() * W, y: Math.random() * H,
        vx: (Math.random() - 0.5) * 0.3 * DPR, vy: (-0.1 - Math.random() * 0.28) * DPR,
        r: (1 + Math.random() * 1.5) * DPR, ph: Math.random() * 6.28, sp: 0.6 + Math.random() * 1.3,
    });
    const resize = () => {
        const r = c.parentElement.getBoundingClientRect();
        DPR = Math.min(1.75, window.devicePixelRatio || 1);
        W = c.width = Math.max(1, Math.floor(r.width * DPR)); H = c.height = Math.max(1, Math.floor(r.height * DPR));
        c.style.width = r.width + 'px'; c.style.height = r.height + 'px';
        flies = Array.from({ length: Math.round(Math.min(30, r.width / 42)) }, spawn);
    };
    const frame = () => {
        t += 0.016; ctx.clearRect(0, 0, W, H);
        for (const f of flies) {
            f.x += f.vx + Math.sin(t * 0.6 + f.ph) * 0.15 * DPR; f.y += f.vy;
            if (f.y < -12) { f.y = H + 12; f.x = Math.random() * W; }
            if (f.x < -12) f.x = W + 12; else if (f.x > W + 12) f.x = -12;
            const glow = 0.3 + 0.7 * (0.5 + 0.5 * Math.sin(t * f.sp * 2 + f.ph));
            const g = ctx.createRadialGradient(f.x, f.y, 0, f.x, f.y, f.r * 7);
            g.addColorStop(0, 'rgba(255,224,140,' + (0.85 * glow) + ')');
            g.addColorStop(0.4, 'rgba(255,180,90,' + (0.3 * glow) + ')');
            g.addColorStop(1, 'rgba(255,150,60,0)');
            ctx.fillStyle = g; ctx.beginPath(); ctx.arc(f.x, f.y, f.r * 7, 0, 6.283); ctx.fill();
            ctx.fillStyle = 'rgba(255,242,200,' + glow + ')'; ctx.beginPath(); ctx.arc(f.x, f.y, f.r, 0, 6.283); ctx.fill();
        }
        raf = requestAnimationFrame(frame);
    };
    let rt; addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(resize, 200); });
    resize(); raf = requestAnimationFrame(frame);
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) { cancelAnimationFrame(raf); raf = null; }
        else if (!raf) raf = requestAnimationFrame(frame);
    });
}

nxDreamBg();
nxFireflies();

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
