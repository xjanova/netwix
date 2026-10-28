import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { VpnPool } from '../vpn-pool.mjs';

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
