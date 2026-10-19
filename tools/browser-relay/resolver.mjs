import { URBAN_ID, waitForUrban } from './urban.mjs';

export function validVideoUrl(value, now = Math.floor(Date.now() / 1000)) {
    if (typeof value !== 'string' || value.length > 2048) return false;
    try {
        const u = new URL(value);
        const ex = u.searchParams.get('ex') || '';
        return u.protocol === 'https:' && ['cdn.discordapp.com', 'media.discordapp.net'].includes(u.hostname) &&
            !u.username && !u.password && !u.hash && (!u.port || u.port === '443') &&
            /^\/attachments\/\d+\/\d+\/[^/]+\.mp4$/i.test(u.pathname) &&
            /^[a-f0-9]{1,12}$/i.test(ex) && parseInt(ex, 16) > now + 300 && parseInt(ex, 16) <= now + 172800 &&
            !!u.searchParams.get('is') && !!u.searchParams.get('hm');
    } catch { return false; }
}

export function validJob(job) {
    return job?.source === 'rongyok' && Number.isSafeInteger(job.id) && job.id > 0 &&
        typeof job.lease_token === 'string' && /^[a-f0-9]{64}$/.test(job.lease_token) &&
        typeof job.series_id === 'string' && /^\d{1,20}$/.test(job.series_id) &&
        typeof job.episode === 'string' && /^[1-9]\d{0,3}$/.test(job.episode);
}

export function watchUrl(job) {
    if (!validJob(job)) throw new Error('invalid_job');
    const url = new URL('https://rongyok.com/watch/');
    url.searchParams.set('series_id', job.series_id);
    url.searchParams.set('ep', job.episode);
    return url.href;
}

// The episode an episode-API response belongs to, for THIS series only.
export function responseEpisode(value, job) {
    try {
        const u = new URL(value);
        const ep = u.searchParams.get('ep') || '';
        return u.origin === 'https://rongyok.com' && /^\/watch\/[a-z0-9_]{4,64}\.php$/i.test(u.pathname) &&
            u.searchParams.get('series_id') === job.series_id && /^[1-9]\d{0,3}$/.test(ep) ? ep : null;
    } catch { return null; }
}

// Only accept an API response for THIS requested episode as its link. A prefetched next
// episode is reported separately as an extra, never as the current episode.
export function matchesEpisodeResponse(value, job) {
    return responseEpisode(value, job) === job.episode;
}

export const MAX_EXTRAS = 3;

export class ChromeResolver {
    constructor(chromium, options = {}) {
        this.chromium = chromium;
        this.options = options;
        this.context = null;
    }

    async open() {
        if (this.context) return;
        this.context = await this.chromium.launchPersistentContext(this.options.profile, {
            channel: this.options.urbanExtension ? 'chromium' : (this.options.channel || 'chrome'),
            headless: this.options.headless ?? false,
            serviceWorkers: this.options.urbanExtension ? 'allow' : 'block', acceptDownloads: false,
            ...(this.options.urbanExtension ? { args: [
                '--disable-extensions-except=' + this.options.urbanExtension,
                '--load-extension=' + this.options.urbanExtension,
            ] } : {}),
        });
        const context = this.context;
        context.on('close', () => { if (this.context === context) this.context = null; });
    }

    async restrictSourcePage(page) {
        // Scope source restrictions to the episode tab: Urban's own authentication
        // and offscreen pages must retain their ordinary provider connections.
        await page.route('**/*', async route => {
            const request = route.request();
            if (['media', 'image', 'font'].includes(request.resourceType())) return route.abort();
            const u = new URL(request.url());
            const urbanPage = u.protocol === 'chrome-extension:' && u.hostname === URBAN_ID;
            if (request.isNavigationRequest() && !urbanPage && !['https://rongyok.com', 'https://challenges.cloudflare.com'].includes(u.origin)) return route.abort();
            return route.continue();
        });
    }

    // Several tabs may start together: launch the browser and wait for Urban only once.
    async ready() {
        this.starting ??= (async () => {
            try { await this.open(); } catch { throw new Error('browser_unavailable'); }
            if (this.options.urbanExtension) await waitForUrban(this.context);
        })().finally(() => { this.starting = null; });
        return this.starting;
    }

    /** @param {Map<string,string>|null} extras receives other episodes of this series the page fetched */
    async resolve(job, extras = null) {
        const url = watchUrl(job);
        await this.ready();
        let page;
        try { page = await this.context.newPage(); }
        catch { await this.close(); throw new Error('browser_unavailable'); }
        await this.restrictSourcePage(page);
        page.on('popup', popup => popup.close().catch(() => {}));
        let candidate = null;
        let active = true;
        page.on('response', async response => {
            const ep = responseEpisode(response.url(), job);
            if (!active || !ep || !response.ok() || (ep !== job.episode && (!extras || extras.size >= MAX_EXTRAS))) return;
            try {
                const body = await response.json();
                if (!active || !(body.ok === true || body.ok === 'true') || !validVideoUrl(body.video_url)) return;
                if (ep === job.episode) candidate = body.video_url;
                else if (extras.size < MAX_EXTRAS) extras.set(ep, body.video_url);
            } catch { /* Not JSON or the tab already closed. */ }
        });
        const deadline = Date.now() + 45000;
        try {
            const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 25000 });
            if (response?.status() === 429) throw new Error('source_blocked');
            while (Date.now() < deadline) {
                if (candidate) return candidate;
                const srcs = await page.locator('video').evaluateAll(videos => videos.map(v => v.getAttribute('src')));
                const src = srcs.find(value => validVideoUrl(value));
                if (src) return src;
                const title = await page.title();
                if (/ถูกบล็อก|you have been blocked/i.test(title)) {
                    const error = new Error('source_blocked');
                    error.sourceStatus = response?.status();
                    error.sourceTitle = title;
                    throw error;
                }
                // Let an ordinary browser verification finish by itself. Never click or solve a CAPTCHA.
                await page.waitForTimeout(250);
            }
            throw new Error(response?.status() === 403 ? 'source_blocked' : 'no_video');
        } catch (error) {
            if (['source_blocked', 'no_video'].includes(error.message)) throw error;
            throw new Error('timeout');
        } finally {
            active = false;
            await page.close().catch(() => {});
        }
    }

    async close() {
        const context = this.context;
        this.context = null;
        await context?.close().catch(() => {});
    }
}

