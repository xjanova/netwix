import { chromium } from 'playwright';
import { resolve as resolvePath } from 'node:path';
import { existsSync, writeFileSync } from 'node:fs';
import { ChromeResolver, validJob } from './resolver.mjs';
import { waitForUrban, openUrbanPanel, switchUrbanLocation, DEFAULT_LOCATIONS } from './urban.mjs';
import { openRelayDb } from './relay-db.mjs';

const urbanExtension = process.env.BROWSER_RELAY_URBAN_EXTENSION ||
    (existsSync('urban-extension/manifest.json') ? resolvePath('urban-extension') : null);
const resolver = new ChromeResolver(chromium, {
    profile: resolvePath(process.env.BROWSER_RELAY_PROFILE || (urbanExtension ? '.urban-profile' : '.chrome-profile')),
    channel: process.env.BROWSER_RELAY_CHANNEL || 'chrome',
    headless: process.env.BROWSER_RELAY_HEADLESS === 'true',
    urbanExtension: urbanExtension ? resolvePath(urbanExtension) : null,
});
let stopping = false;
process.on('SIGINT', () => { stopping = true; });
process.on('SIGTERM', () => { stopping = true; });
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const stopRequested = () => stopping || existsSync('worker.stop');
// Parallel episode tabs in the one isolated browser. Two keeps RongYok traffic ordinary.
const concurrency = Math.min(4, Math.max(1, parseInt(process.env.BROWSER_RELAY_CONCURRENCY || '2', 10) || 2));
const db = await openRelayDb(resolvePath(process.env.BROWSER_RELAY_DB || 'relay.db')).catch(() => null);
const active = new Map();
// Urban exit rotation: a location RongYok blocked rests for 30 minutes before it is tried again.
const preferredLocations = (process.env.BROWSER_RELAY_URBAN_LOCATIONS || '').split(',').map(name => name.trim()).filter(Boolean);
const LOCATION_COOLDOWN_MS = 30 * 60000;
const coolingUntil = new Map();
let switching = null;
let lastSwitchAt = 0;
let urbanAt = null;
let stats = null;
let statsAt = 0;
function refreshStats(force) {
    if (!db || (!force && Date.now() - statsAt < 10000)) return;
    try { stats = db.stats(); statsAt = Date.now(); } catch { /* Keep the previous summary. */ }
}
const log = (event, fields = {}) => {
    const record = { time: new Date().toISOString(), pid: process.pid, event, ...fields };
    console.log(JSON.stringify(record));
    try { db?.event(record); } catch { /* worker.log still has it. */ }
    refreshStats(event.startsWith('job_'));
    const status = { ...record, active: active.size, concurrency, sqlite: !!db, ...(urbanAt ? { urban: urbanAt } : {}),
        ...(stats ? { stats: { ...stats, recent: stats.recent.slice(0, 5) } } : {}) };
    try { writeFileSync('status.json', JSON.stringify(status), { mode: 0o600 }); } catch { /* Logs remain available. */ }
};
const expiresAt = url => new Date(parseInt(new URL(url).searchParams.get('ex'), 16) * 1000).toISOString();
const KNOWN_ERRORS = ['source_blocked', 'timeout', 'no_video', 'browser_unavailable', 'invalid_url', 'urban_not_connected'];

/**
 * Move Urban to another location right away. Single-flight: every tab that hit the same block
 * shares one switch. Tries up to three locations; one that does not connect rests like a blocked one.
 * @returns {Promise<boolean>} whether Urban is now on a new location
 */
function rotateUrban(reason, target = null) {
    if (!urbanExtension) return Promise.resolve(false);
    if (switching) return switching;
    if (!target && Date.now() - lastSwitchAt < 20000) return Promise.resolve(true); // another tab just switched
    switching = (async () => {
        try { await resolver.open(); } catch { return false; }
        for (let attempt = 0; attempt < 3; attempt++) {
            const now = Date.now();
            for (const [name, until] of coolingUntil) if (until <= now) coolingUntil.delete(name);
            try {
                const moved = await switchUrbanLocation(resolver.context, {
                    cooling: new Set(coolingUntil.keys()), target,
                    preferred: preferredLocations.length ? preferredLocations : DEFAULT_LOCATIONS,
                });
                if (moved.from && !target) coolingUntil.set(moved.from, Date.now() + LOCATION_COOLDOWN_MS);
                lastSwitchAt = Date.now();
                urbanAt = moved.to;
                log('urban_switched', { reason, from: moved.from, to: moved.to, code: moved.code });
                return true;
            } catch (error) {
                const code = /^urban_[a-z_]+$/.test(error.message) ? error.message : 'urban_switch_failed';
                if (error.location) coolingUntil.set(error.location, Date.now() + LOCATION_COOLDOWN_MS);
                log('urban_switch_failed', { reason, error: code, location: error.location ?? null });
                // Everything is resting: start the round again rather than stay on a blocked exit.
                if (code === 'urban_no_location') coolingUntil.clear();
                if (target) return false;
            }
        }
        return false;
    })().finally(() => { switching = null; });
    return switching;
}

async function main() {
    if (process.argv.includes('--stats')) {
        if (!db) throw new Error('sqlite_unavailable');
        const days = Math.min(30, Math.max(1, parseInt(process.argv[process.argv.indexOf('--stats') + 1], 10) || 1));
        console.log(JSON.stringify(db.stats(new Date(Date.now() - days * 86400000), 20), null, 2));
        return;
    }
    if (process.argv.includes('--urban-switch')) {
        // Diagnostic/manual: move Urban once (optionally to a named location, e.g. "Germany").
        const name = process.argv[process.argv.indexOf('--urban-switch') + 1];
        await resolver.open();
        await waitForUrban(resolver.context).catch(() => {});
        if (!await rotateUrban('manual', name && !name.startsWith('--') ? name : null)) throw new Error('urban_switch_failed');
        return;
    }
    if (process.argv.includes('--urban-setup') || process.argv.includes('--urban-status')) {
        await resolver.open();
        if (process.argv.includes('--urban-status')) {
            log('urban_status', await waitForUrban(resolver.context));
            return;
        }
        const page = await openUrbanPanel(resolver.context);
        log('urban_setup_opened');
        while (!stopping && !page.isClosed() && !existsSync('worker.stop')) await sleep(1000);
        return;
    }
    if (process.argv.includes('--probe')) {
        const index = process.argv.indexOf('--probe');
        const job = { id: 1, lease_token: '0'.repeat(64), source: 'rongyok', series_id: process.argv[index + 1], episode: process.argv[index + 2] };
        log('probe_started', { series_id: job.series_id, episode: job.episode });
        const video = new URL(await resolver.resolve(job));
        if (process.argv.includes('--probe-export')) {
            // A private diagnostic artifact for an explicit outside-VPN test; never a routine log.
            writeFileSync('probe-result.json', JSON.stringify({ series_id: job.series_id, episode: job.episode, video_url: video.href }), { mode: 0o600 });
        }
        // Do not put signed links, cookies or the API token into routine logs.
        log('probe_success', { series_id: job.series_id, episode: job.episode, video_host: video.hostname, expires_at: expiresAt(video.href) });
        return;
    }
    const token = process.env.NETWIX_RELAY_TOKEN || '';
    const base = new URL(process.env.NETWIX_RELAY_URL || 'https://netwix.online');
    if (base.username || base.password || base.search || base.hash || base.pathname !== '/' ||
        (base.protocol !== 'https:' && !(base.protocol === 'http:' && ['localhost', '127.0.0.1'].includes(base.hostname)))) {
        throw new Error('invalid_server_url');
    }
    if (token.length < 32) throw new Error('missing_relay_token');
    async function api(path, body = {}) {
        const response = await fetch(new URL('/api/browser-relay/' + path, base), {
            method: 'POST', redirect: 'error', headers: { Authorization: 'Bearer ' + token, Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify(body), signal: AbortSignal.timeout(20000),
        });
        if (!response.ok) throw new Error('api_' + response.status);
        return response.json();
    }

    let pauseUntil = 0;
    let restartBrowser = false;
    let lastJobAt = 0;
    let failStreak = 0;

    async function runJob(job) {
        const startedAt = new Date();
        let row = null;
        try { row = db?.jobStarted(job, startedAt) ?? null; } catch { /* History is best effort. */ }
        const finish = result => { try { if (row !== null) db.jobFinished(row, startedAt, result); } catch { /* Best effort. */ } };
        log('job_started', { job_id: job.id, series_id: job.series_id, episode: job.episode, priority: job.priority ?? 0 });
        const extras = new Map();
        try {
            const video = await resolver.resolve(job, extras);
            const sent = [...extras].map(([episode, video_url]) => ({ episode, video_url }));
            const reply = await api(`jobs/${job.id}/complete`, { lease_token: job.lease_token, video_url: video, ...(sent.length ? { extras: sent } : {}) });
            const accepted = Number.isInteger(reply?.extras_accepted) ? reply.extras_accepted : 0;
            finish({ outcome: 'completed', video_host: new URL(video).hostname, expires_at: expiresAt(video),
                extras_found: sent.length, extras_accepted: accepted });
            failStreak = 0;
            log('job_completed', { job_id: job.id, series_id: job.series_id, episode: job.episode,
                duration_ms: Date.now() - startedAt, extras: accepted });
        } catch (error) {
            const apiError = /^api_\d+$/.test(error.message);
            const code = KNOWN_ERRORS.includes(error.message) || apiError ? error.message : 'timeout';
            // Completion may have succeeded even if its response was lost. A 409 is harmless;
            // otherwise the bounded lease allows recovery after a network failure.
            if (!apiError) await api(`jobs/${job.id}/fail`, { lease_token: job.lease_token, error: code }).catch(() => {});
            finish({ outcome: 'failed', error: code, extras_found: extras.size });
            log('job_failed', { job_id: job.id, series_id: job.series_id, episode: job.episode, error: code, duration_ms: Date.now() - startedAt });
            if (code === 'browser_unavailable' || code === 'urban_not_connected') restartBrowser = true;
            if (!apiError) failStreak++;
            // A block applies to the whole exit: move Urban to another location immediately. Three
            // failures in a row without a block page (silent drops, endless challenges) count too.
            const blocked = code === 'source_blocked' || (failStreak >= 3 && ['timeout', 'no_video'].includes(code));
            if (blocked && await rotateUrban(code === 'source_blocked' ? 'source_blocked' : 'repeated_failures')) {
                failStreak = 0;
                pauseUntil = Math.max(pauseUntil, Date.now() + 1000);
            } else {
                // No Urban, or no location connected: back off as before.
                pauseUntil = Math.max(pauseUntil, Date.now() + (code === 'source_blocked' ? 30000 : 3000));
            }
        }
    }

    log('worker_started', { server: base.hostname, concurrency, sqlite: !!db });
    try { db?.prune(); } catch { /* Best effort. */ }
    let prunedAt = Date.now();
    while (!stopRequested()) {
        // Restart a broken browser only once no other tab is still using it.
        if (restartBrowser && active.size === 0) { restartBrowser = false; await resolver.close(); }
        if (active.size >= concurrency || restartBrowser || switching || Date.now() < pauseUntil) {
            await Promise.race([...active.values(), sleep(1000)]);
            continue;
        }
        let job;
        try { ({ job } = await api('jobs/claim')); }
        catch (error) {
            const code = /^api_\d+$/.test(error.message) ? error.message : 'connection_failed';
            log('worker_waiting', { error: code });
            if (process.argv.includes('--once')) throw new Error(code);
            await sleep(15000);
            continue;
        }
        if (job) {
            if (!validJob(job)) { log('worker_waiting', { error: 'invalid_job' }); await sleep(15000); continue; }
            lastJobAt = Date.now();
            active.set(job.id, runJob(job).finally(() => active.delete(job.id)));
            if (process.argv.includes('--once')) break;
            continue; // fill the remaining tabs straight away
        }
        if (process.argv.includes('--once')) break;
        if (Date.now() - prunedAt > 86400000) { try { db?.prune(); } catch { /* Best effort. */ } prunedAt = Date.now(); }
        // Viewers arrive in bursts: poll quickly for a while after recent work.
        await sleep(Date.now() - lastJobAt < 120000 ? 1500 : 4000);
    }
    await Promise.allSettled(active.values());
}

try { await main(); }
catch (error) {
    const code = /^[a-z_0-9]+$/.test(error.message) ? error.message : 'worker_failed';
    log('worker_stopped', { error: code, ...(process.argv.includes('--probe') && error.sourceTitle
        ? { source_status: error.sourceStatus, source_title: error.sourceTitle } : {}) });
    process.exitCode = 1;
} finally {
    await resolver.close();
    if (stopRequested()) log('worker_stopped', { reason: 'requested' });
    db?.close();
}
