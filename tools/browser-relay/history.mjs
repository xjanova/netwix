// Read-only history for the control window's "ประวัติย้อนหลัง": prints the jobs in relay.db started
// since --since <ISO time> as one JSON object and exits. It never writes status.json or worker.log
// (worker.mjs does on errors), so it is safe to run beside a busy worker. Like relay.db itself it
// carries no signed links, cookies or tokens.
//
//   node history.mjs --since 2026-10-06T00:00:00Z [--limit 5000]
import { existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { openRelayDb, RETENTION_DAYS, MIN_RETENTION_DAYS } from './relay-db.mjs';

const arg = name => { const i = process.argv.indexOf(name); return i < 0 ? undefined : process.argv[i + 1]; };
const asked = Date.parse(arg('--since') ?? '');
const since = new Date(Number.isNaN(asked) ? Date.now() - 86400000 : asked);
const limit = Math.min(20000, Math.max(1, parseInt(arg('--limit'), 10) || 5000));
const file = resolve(process.env.BROWSER_RELAY_DB || 'relay.db');

const out = { since: since.toISOString(), retention_days: RETENTION_DAYS, min_retention_days: MIN_RETENTION_DAYS, total: 0, rows: [], summary: null };
if (!existsSync(file)) {
    out.error = 'no_history';
} else {
    let db = null;
    try {
        db = await openRelayDb(file);
        if (!db) throw new Error('sqlite_unavailable');
        Object.assign(out, db.history(since, limit));
        const s = db.stats(since, 0);
        out.summary = { jobs: s.jobs, completed: s.completed, failed: s.failed, blocks: s.blocks, switches: s.switches, avg_ms: s.avg_ms };
    } catch (error) {
        out.error = error.message === 'sqlite_unavailable' ? error.message : 'history_failed';
        process.exitCode = 1;
    } finally {
        db?.close();
    }
}
// Nulls dropped: thousands of rows stay small for Windows PowerShell's ConvertFrom-Json.
process.stdout.write(JSON.stringify(out, (key, value) => (value === null && key !== 'summary' ? undefined : value)));
