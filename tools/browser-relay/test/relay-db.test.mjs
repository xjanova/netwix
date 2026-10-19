import test from 'node:test';
import assert from 'node:assert/strict';
import { openRelayDb } from '../relay-db.mjs';

const sqlite = await import('node:sqlite').then(() => true, () => false);
const skip = sqlite ? false : 'node:sqlite needs Node 22.13+';

const job = { id: 7, lease_token: 'a'.repeat(64), source: 'rongyok', series_id: '8457', episode: '51', priority: 0 };

test('job history and stats are kept without signed links', { skip }, async () => {
    const db = await openRelayDb(':memory:');
    assert.ok(db, 'node:sqlite is available');
    const started = new Date(Date.now() - 5000);
    const ok = db.jobStarted(job, started, { title: 'เรื่องทดสอบ', episode_number: 51, reason: 'viewer', requester: 'guest', via: 'app' });
    db.jobFinished(ok, started, { outcome: 'completed', vpn: 'DE', video_host: 'cdn.discordapp.com', expires_at: '2026-10-20T00:00:00.000Z', extras_found: 2, extras_accepted: 1 });
    const bad = db.jobStarted({ ...job, id: 8, episode: '52', priority: 1 }, started);
    db.jobFinished(bad, started, { outcome: 'failed', error: 'source_blocked' });
    db.event({ time: new Date().toISOString(), event: 'job_completed', job_id: 7 });

    const stats = db.stats();
    assert.equal(stats.jobs, 2);
    assert.equal(stats.completed, 1);
    assert.equal(stats.failed, 1);
    assert.equal(stats.extras, 1);
    assert.ok(stats.avg_ms >= 4000);
    assert.deepEqual(stats.errors, { source_blocked: 1 });
    assert.equal(stats.recent[0].episode, '52');
    assert.deepEqual([stats.recent[1].title, stats.recent[1].requester, stats.recent[1].via, stats.recent[1].vpn], ['เรื่องทดสอบ', 'guest', 'app', 'DE']);
    assert.equal(JSON.stringify(stats).includes('https://'), false);
    db.event({ time: new Date().toISOString(), event: 'worker_waiting', error: 'connection_failed' });
    assert.deepEqual(db.events(started).map(row => row.event), ['job_completed']);
    db.close();
});

test('history older than the retention window is pruned', { skip }, async () => {
    const db = await openRelayDb(':memory:');
    const old = new Date(Date.now() - 40 * 86400000);
    db.jobFinished(db.jobStarted(job, old), old, { outcome: 'completed' }, old);
    db.event({ time: old.toISOString(), event: 'worker_started' });
    db.jobStarted({ ...job, id: 9 });
    db.prune();
    assert.equal(db.db.prepare('SELECT COUNT(*) AS n FROM jobs').get().n, 1);
    assert.equal(db.db.prepare('SELECT COUNT(*) AS n FROM events').get().n, 0);
    db.close();
});

test('a relay.db from an older version gains the new columns on open', { skip }, async () => {
    const { DatabaseSync } = await import('node:sqlite');
    const { RelayDb } = await import('../relay-db.mjs');
    const raw = new DatabaseSync(':memory:');
    raw.exec(`CREATE TABLE jobs (id INTEGER PRIMARY KEY, job_id INTEGER NOT NULL, series_id TEXT NOT NULL, episode TEXT NOT NULL,
        priority INTEGER NOT NULL DEFAULT 0, started_at TEXT NOT NULL, finished_at TEXT, duration_ms INTEGER, outcome TEXT, error TEXT,
        video_host TEXT, expires_at TEXT, extras_found INTEGER NOT NULL DEFAULT 0, extras_accepted INTEGER NOT NULL DEFAULT 0)`);
    raw.exec(`INSERT INTO jobs (job_id, series_id, episode, started_at) VALUES (1, '8457', '51', '2026-10-19T00:00:00Z')`);
    const db = new RelayDb(raw);
    const row = db.jobStarted(job, new Date(), { title: 'ใหม่', via: 'web' });
    db.jobFinished(row, new Date(), { outcome: 'completed', vpn: 'US' });
    assert.deepEqual(db.stats(new Date(0)).recent.map(r => r.title), ['ใหม่', null]);
    db.close();
});

test('blocks and VPN switches are counted for the day and in total', { skip }, async () => {
    const db = await openRelayDb(':memory:');
    const now = new Date();
    const old = new Date(now - 3 * 86400000);
    for (const [when, error] of [[now, 'source_blocked'], [now, 'source_blocked'], [old, 'source_blocked'], [now, 'timeout']]) {
        db.jobFinished(db.jobStarted(job, when), when, { outcome: 'failed', error }, when);
    }
    db.event({ time: now.toISOString(), event: 'urban_switched', reason: 'source_blocked', from: 'United States (USA)', to: 'Germany', code: 'DE' });
    db.event({ time: now.toISOString(), event: 'urban_switched', reason: 'source_blocked', from: 'Germany', to: 'United Kingdom (UK)', code: 'GB' });
    db.event({ time: old.toISOString(), event: 'urban_switched', reason: 'manual', from: 'Germany', to: 'Germany', code: 'DE' });
    db.event({ time: now.toISOString(), event: 'urban_switch_failed', error: 'urban_switch_failed' });
    const s = db.stats();
    assert.deepEqual([s.blocks, s.blocks_total, s.switches, s.switches_total, s.switch_failures, s.locations_used], [2, 3, 2, 3, 1, 2]);
    assert.deepEqual(s.recent_switches[0], { time: now.toISOString(), from: 'Germany', to: 'United Kingdom (UK)', code: 'GB', reason: 'source_blocked' });
    db.close();
});
