import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { VpnPool, failoverCode } from '../vpn-pool.mjs';

const job = { lease_seconds: 120 };
function provider(id, resolve) { return { id, resolver: { resolve, close: async () => {} } }; }

test('registration failure immediately falls back and keeps the working provider for the next job', async () => {
    const calls = [], events = [];
    const pool = new VpnPool([
        provider('urban', async (_, extras) => { calls.push('urban'); extras.set('2', 'bad'); throw new Error('urban_registration_failed'); }),
        provider('browsec', async (_, extras) => { calls.push('browsec'); extras.set('3', 'good'); return 'video'; }),
    ], { onEvent: (event, fields) => events.push({ event, ...fields }) });
    const extras = new Map();
    assert.equal(await pool.resolve(job, extras), 'video');
    assert.deepEqual([...extras], [['3', 'good']]);
    assert.equal(await pool.resolve(job), 'video');
    assert.deepEqual(calls, ['urban', 'browsec', 'browsec']);
    assert.equal(events.find(e => e.event === 'vpn_failed').retry_in_s, 300);
    assert.deepEqual(pool.status, { provider: 'browsec', connected: true, location: null });
});

test('all failures cool down, wrap around and retry on later requests; no permanent blacklist', async () => {
    let time = 0, succeed = false;
    const calls = [];
    const pool = new VpnPool(['urban', 'browsec'].map(id => provider(id, async () => {
        calls.push(id); if (succeed) return 'ok'; throw new Error('source_blocked');
    })), { now: () => time });
    await assert.rejects(pool.resolve(job), /vpn_unavailable/);
    assert.equal(pool.retryAfter(), 60000);
    await assert.rejects(pool.resolve(job), /vpn_unavailable/);
    assert.deepEqual(calls, ['urban', 'browsec']);
    time = 60000;
    await assert.rejects(pool.resolve(job), /vpn_unavailable/);
    assert.equal(pool.retryAfter(), 120000);
    time += 120000; succeed = true;
    assert.equal(await pool.resolve(job), 'ok');
    assert.deepEqual(calls, ['urban', 'browsec', 'urban', 'browsec', 'urban']);
    assert.equal(pool.providers[0].failures, 0);
});

test('cooldowns and the provider cursor survive a worker restart', async () => {
    const dir = mkdtempSync(join(tmpdir(), 'relay-vpn-'));
    try {
        const stateFile = join(dir, 'vpn-state.json');
        const make = () => [provider('urban', async () => { throw new Error('urban_registration_failed'); }),
            provider('browsec', async () => 'ok')];
        const first = new VpnPool(make(), { stateFile, now: () => 100 });
        await first.resolve(job);
        const restored = new VpnPool(make(), { stateFile, now: () => 200 });
        assert.equal(restored.providers[restored.cursor].id, 'browsec');
        assert.equal(restored.providers[0].until, 300100);
        assert.equal(restored.status.connected, false, 'a saved country is not a verified live connection');
        assert.equal(await restored.resolve(job), 'ok');
    } finally { rmSync(dir, { recursive: true, force: true }); }
});

test('a hung provider is closed and falls through within the attempt deadline', async () => {
    let closed = false;
    const hung = provider('urban', () => new Promise(() => {}));
    hung.resolver.close = async () => { closed = true; };
    const pool = new VpnPool([hung, provider('browsec', async () => 'ok')], { attemptMs: 10 });
    assert.equal(await pool.resolve(job), 'ok');
    assert.equal(closed, true);
});

test('a lease consumed by the first failure is not extended to try another provider', async () => {
    let time = 0, second = false;
    const pool = new VpnPool([
        provider('urban', async () => { time = 90001; throw new Error('timeout'); }),
        provider('browsec', async () => { second = true; return 'ok'; }),
    ], { now: () => time });
    await assert.rejects(pool.resolve(job), /vpn_unavailable/);
    assert.equal(second, false);
    assert.equal(pool.providers[pool.cursor].id, 'browsec', 'next server retry starts with the next provider');
});

test('invalid jobs/programming errors do not rotate networks and concurrent proxy changes are refused', async () => {
    const bad = new VpnPool([provider('urban', async () => { throw new Error('invalid_job'); })]);
    await assert.rejects(bad.resolve(job), /invalid_job/);
    assert.equal(bad.providers[0].failures, 0);
    let finish;
    const pool = new VpnPool([provider('urban', () => new Promise(resolve => { finish = resolve; }))]);
    const running = pool.resolve(job);
    await assert.rejects(pool.resolve(job), /vpn_pool_busy/);
    finish('ok'); await running;
});

test('a timed-out country change cannot later start an orphan episode resolution', async () => {
    let releaseRotation, resolves = 0;
    const first = provider('urban', async () => { if (++resolves === 1) throw new Error('source_blocked'); return 'bad'; });
    first.rotate = () => new Promise(resolve => { releaseRotation = resolve; });
    const pool = new VpnPool([first], { cooldownMs: 0, attemptMs: 10 });
    await assert.rejects(pool.resolve(job), /vpn_unavailable/);
    await assert.rejects(pool.resolve(job), /vpn_unavailable/);
    releaseRotation({ from: 'a', to: 'b' });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(resolves, 1);
});

const timeoutError = message => Object.assign(new Error(message), { name: 'TimeoutError' });

test('raw Playwright failures from provider hooks fail over instead of bypassing the pool', async () => {
    const events = [];
    const pool = new VpnPool([
        provider('urban', async () => { throw timeoutError('page.goto: Timeout 15000ms exceeded.\nCall log: chrome-extension://x/popup.html'); }),
        provider('browsec', async () => 'ok'),
    ], { onEvent: (event, fields) => events.push({ event, ...fields }) });
    assert.equal(await pool.resolve(job), 'ok');
    const failed = events.find(e => e.event === 'vpn_failed');
    assert.equal(failed.error, 'vpn_not_connected');
    assert.equal(failed.cause, 'TimeoutError');
    assert.equal(JSON.stringify(events).includes('chrome-extension'), false, 'raw messages are not logged');
    assert.equal(pool.providers[0].failures, 1);
    assert.ok(pool.providers[0].until > 0, 'the failing provider cools down');
});

test('a closed context during a country change counts as a switch failure and retries the change later', async () => {
    const first = provider('urban', async () => 'unreached');
    first.rotate = async () => { throw new Error('locator.click: Target page, context or browser has been closed'); };
    const pool = new VpnPool([first, provider('browsec', async () => 'ok')]);
    pool.providers[0].rotateNext = true;
    assert.equal(await pool.resolve(job), 'ok');
    assert.equal(pool.providers[0].rotateNext, true);
    assert.equal(failoverCode(new Error('locator.click: Target page, context or browser has been closed'), 'rotate'), 'vpn_switch_failed');
    assert.equal(failoverCode(new TypeError("Cannot read properties of undefined (reading 'code')")), null);
    assert.equal(failoverCode(new Error('invalid_job')), null);
});

test('a hung browser close is bounded so the job still reaches the next provider', async () => {
    const stuck = provider('urban', async () => { throw new Error('urban_not_connected'); });
    stuck.resolver.close = () => new Promise(() => {});
    const pool = new VpnPool([stuck, provider('browsec', async () => 'ok')], { closeMs: 10 });
    assert.equal(await pool.resolve(job), 'ok');
});

test('a timed-out attempt that opens its browser late is closed and held back until it settles', async () => {
    let finishLaunch, closes = 0;
    const late = provider('urban', () => new Promise((_, reject) => { finishLaunch = reject; }));
    late.resolver.close = async () => { closes++; };
    const pool = new VpnPool([late], { attemptMs: 10, cooldownMs: 0 });
    await assert.rejects(pool.resolve(job), /vpn_unavailable/);
    assert.equal(closes, 1);
    assert.ok(pool.providers[0].settling, 'still unwinding');
    assert.equal(pool.retryAfter(), 1000, 'the worker waits instead of claiming a job nobody can take');
    await assert.rejects(pool.resolve(job), /vpn_unavailable/);
    finishLaunch(new Error('Target page, context or browser has been closed'));
    await new Promise(resolve => setTimeout(resolve, 5));
    assert.equal(closes, 2, 'whatever the late attempt opened is closed');
    assert.equal(pool.providers[0].settling, null);
    assert.equal(pool.retryAfter(), 0);
});
