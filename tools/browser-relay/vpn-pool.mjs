import { readFileSync, writeFileSync, renameSync } from 'node:fs';

const FAILOVER_ERRORS = new Set(['urban_not_connected', 'urban_registration_failed', 'browsec_not_connected', 'browsec_setup_required',
    'urban_switch_failed', 'browsec_switch_failed', 'urban_no_location',
    'vpn_not_connected', 'source_blocked', 'timeout', 'no_video', 'browser_unavailable']);

/** One job at a time: each provider owns a separate browser/profile. Never change a live tab's proxy. */
export class VpnPool {
    constructor(providers, { now = Date.now, onEvent = () => {}, stateFile = null,
        cooldownMs = 60000, maxCooldownMs = 300000, attemptMs = 45000 } = {}) {
        if (!providers.length || new Set(providers.map(p => p.id)).size !== providers.length) throw new Error('invalid_vpn_providers');
        this.providers = providers.map(p => ({ ...p, failures: 0, until: 0, rotateNext: false }));
        this.now = now; this.onEvent = onEvent; this.stateFile = stateFile;
        this.cooldownMs = cooldownMs; this.maxCooldownMs = maxCooldownMs; this.attemptMs = attemptMs;
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

    retryAfter() { return Math.max(0, Math.min(...this.providers.map(p => p.until)) - this.now()); }

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
                    return !tried.has(p.id) && p.until <= this.now();
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
                try {
                    const attemptBudget = Math.min(this.attemptMs, deadline - this.now());
                    const operation = (async () => {
                        if (p.rotateNext && p.rotate) {
                            const moved = await p.rotate();
                            if (expired) throw new Error('timeout');
                            p.rotateNext = false;
                            this.onEvent('vpn_location_changed', { provider: p.id, ...moved });
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
                    await p.resolver.close();
                    if (!FAILOVER_ERRORS.has(error.message)) throw error;
                    lastError = error;
                    p.failures++;
                    if (['source_blocked', 'timeout', 'no_video'].includes(error.message)) p.rotateNext = true;
                    const delay = ['urban_registration_failed', 'browsec_setup_required'].includes(error.message) ? this.maxCooldownMs
                        : Math.min(this.maxCooldownMs, this.cooldownMs * 2 ** Math.min(p.failures - 1, 10));
                    p.until = this.now() + delay;
                    this.status = { provider: p.id, connected: false, location: null };
                    this.onEvent('vpn_failed', { provider: p.id, error: error.message, retry_in_s: Math.ceil(delay / 1000) });
                    this.cursor = (this.cursor + 1) % this.providers.length;
                    this.save();
                }
            }
            const error = new Error('vpn_unavailable');
            error.cause = lastError;
            throw error;
        } finally { this.busy = false; }
    }

    async close() { await Promise.allSettled(this.providers.map(p => p.resolver.close())); }
}
