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
    const ok = db.jobStarted(job, started);
    db.jobFinished(ok, started, { outcome: 'completed', video_host: 'cdn.discordapp.com', expires_at: '2026-10-20T00:00:00.000Z', extras_found: 2, extras_accepted: 1 });
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
