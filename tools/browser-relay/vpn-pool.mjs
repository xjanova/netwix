import { readFileSync, writeFileSync, renameSync } from 'node:fs';

const FAILOVER_ERRORS = new Set(['urban_not_connected', 'urban_registration_failed', 'browsec_not_connected', 'browsec_setup_required',
    'urban_switch_failed', 'browsec_switch_failed', 'urban_no_location',
    'vpn_not_connected', 'vpn_switch_failed', 'source_blocked', 'timeout', 'no_video', 'browser_unavailable']);
// Playwright failures inside a provider's ready/country-change hooks (popup goto, clicks, a context
// closed under it). Program errors (TypeError, invalid_job) are not network failures and still throw.
const BROWSER_FAILURE = /Timeout \d+ms exceeded|Target page, context or browser has been closed|Browser has been closed|browser has disconnected|Execution context was destroyed|frame was detached|net::ERR_/i;

/** The failover code for a provider error, or null when it must not rotate networks. */
export function failoverCode(error, phase = 'ready') {
    const message = String(error?.message ?? '');
    if (FAILOVER_ERRORS.has(message)) return message;
    if (error?.name === 'TimeoutError' || BROWSER_FAILURE.test(message)) return phase === 'rotate' ? 'vpn_switch_failed' : 'vpn_not_connected';
    return null;
}

/** One job at a time: each provider owns a separate browser/profile. Never change a live tab's proxy. */
export class VpnPool {
    constructor(providers, { now = Date.now, onEvent = () => {}, stateFile = null,
        cooldownMs = 60000, maxCooldownMs = 300000, attemptMs = 45000, closeMs = 10000 } = {}) {
        if (!providers.length || new Set(providers.map(p => p.id)).size !== providers.length) throw new Error('invalid_vpn_providers');
        this.providers = providers.map(p => ({ ...p, failures: 0, until: 0, rotateNext: false, settling: null }));
        this.now = now; this.onEvent = onEvent; this.stateFile = stateFile;
        this.cooldownMs = cooldownMs; this.maxCooldownMs = maxCooldownMs; this.attemptMs = attemptMs; this.closeMs = closeMs;
        this.cursor = 0; this.busy = false; this.status = { provider: null, connected: false, location: null };
        try {
            const saved = JSON.parse(readFileSync(stateFile, 'utf8'));
            const index = this.providers.findIndex(p => p.id === saved.current);
            if (index >= 0) this.cursor = index;
            for (const p of this.providers) {
                const old = saved.providers?.[p.id];
                p.failures = Math.min(10, Math.max(0, Number(old?.failures) || 0));
                p.until = Math.min(this.now() + maxCooldownMs, Math.max(0, Number(old?.until) || 0));
                p.rotateNext = old?.rotateNext === true;
            }
        } catch { /* First launch or invalid state: establish actual connections again. */ }
    }

    save() {
        if (!this.stateFile) return;
        const providers = Object.fromEntries(this.providers.map(p => [p.id, { failures: p.failures, until: p.until, rotateNext: p.rotateNext }]));
        try {
            writeFileSync(this.stateFile + '.tmp', JSON.stringify({ current: this.providers[this.cursor].id, providers }), { mode: 0o600 });
            renameSync(this.stateFile + '.tmp', this.stateFile);
        } catch { /* State is only a restart hint; it never contains credentials. */ }
    }

    // A provider whose timed-out attempt is still unwinding is not offered until its browser is closed.
    retryAfter() {
        return Math.min(...this.providers.map(p => Math.max(p.settling ? 1000 : 0, p.until - this.now())));
    }

    // A hung Chromium must not hold the job past its lease: closing is bounded, never awaited forever.
    async closeProvider(p) {
        let timer;
        await Promise.race([p.resolver.close().catch(() => {}),
            new Promise(resolve => { timer = setTimeout(resolve, this.closeMs); })]);
        clearTimeout(timer);
    }

    async resolve(job, extras = null) {
        if (this.busy) throw new Error('vpn_pool_busy');
        this.busy = true;
        // Leave time to POST the result and close a browser before the server's lease expires.
        const budget = Math.min(90000, (Number(job.lease_seconds) || 120) * 1000 - 30000);
        const deadline = this.now() + budget;
        const tried = new Set();
        let lastError;
        try {
            while (tried.size < this.providers.length && deadline - this.now() >= 1000) {
                const offset = this.providers.findIndex((_, n) => {
                    const p = this.providers[(this.cursor + n) % this.providers.length];
                    return !tried.has(p.id) && !p.settling && p.until <= this.now();
                });
                if (offset < 0) break;
                this.cursor = (this.cursor + offset) % this.providers.length;
                const p = this.providers[this.cursor];
                tried.add(p.id);
                const previous = this.status.provider;
                this.status = { provider: p.id, connected: false, location: null };
                if (previous && previous !== p.id) this.onEvent('vpn_switched', { from: previous, to: p.id, reason: 'provider_failed' });
                this.onEvent('vpn_selected', { provider: p.id });
                const captured = new Map();
                let timer;
                let expired = false;
                let phase = 'ready';
                let operation;
                try {
                    const attemptBudget = Math.min(this.attemptMs, deadline - this.now());
                    operation = (async () => {
                        if (p.rotateNext && p.rotate) {
                            phase = 'rotate';
                            const moved = await p.rotate();
                            if (expired) throw new Error('timeout');
                            p.rotateNext = false;
                            this.onEvent('vpn_location_changed', { provider: p.id, ...moved });
                            phase = 'ready';
                        }
                        return p.resolver.resolve(job, captured);
                    })();
                    const video = await Promise.race([operation, new Promise((_, reject) => {
                        timer = setTimeout(() => { expired = true; reject(new Error('timeout')); }, attemptBudget);
                    })]);
                    clearTimeout(timer);
                    p.failures = 0; p.until = 0;
                    this.status = { provider: p.id, connected: true, location: p.location?.() ?? null };
                    for (const [ep, url] of captured) extras?.set(ep, url);
                    this.save();
                    this.onEvent('vpn_ready', this.status);
                    return video;
                } catch (error) {
                    clearTimeout(timer);
                    // A failed provider's prefetched links must never leak into the next attempt.
                    await this.closeProvider(p);
                    if (expired) {
                        // The abandoned attempt may still be launching a browser after this close. Hold the
                        // provider back until it settles, then close whatever it opened.
                        // Bounded too, so a promise that never settles cannot retire the provider for good.
                        let wait;
                        p.settling = Promise.race([operation.catch(() => {}),
                            new Promise(resolve => { wait = setTimeout(resolve, this.maxCooldownMs); wait.unref?.(); })])
                            .then(() => { clearTimeout(wait); return this.closeProvider(p); })
                            .finally(() => { p.settling = null; });
                    }
                    const code = failoverCode(error, phase);
                    if (!code) throw error;
                    lastError = code === error.message ? error : Object.assign(new Error(code), { cause: error });
                    p.failures++;
                    if (['source_blocked', 'timeout', 'no_video'].includes(code)) p.rotateNext = true;
                    const delay = ['urban_registration_failed', 'browsec_setup_required'].includes(code) ? this.maxCooldownMs
                        : Math.min(this.maxCooldownMs, this.cooldownMs * 2 ** Math.min(p.failures - 1, 10));
                    p.until = this.now() + delay;
                    this.status = { provider: p.id, connected: false, location: null };
                    // Only the error class of a raw browser failure: its message can carry page URLs.
                    this.onEvent('vpn_failed', { provider: p.id, error: code, retry_in_s: Math.ceil(delay / 1000),
                        ...(code !== error.message ? { cause: error.name || 'Error' } : {}) });
                    this.cursor = (this.cursor + 1) % this.providers.length;
                    this.save();
                }
            }
            const error = new Error('vpn_unavailable');
            error.cause = lastError;
            throw error;
        } finally { this.busy = false; }
    }

    async close() { await Promise.allSettled(this.providers.map(p => this.closeProvider(p))); }
}
