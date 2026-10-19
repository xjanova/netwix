// Local work history in SQLite (relay.db). Holds job ids, episode numbers, timings and fixed
// error codes only — never signed video URLs, cookies or the API token.

const SCHEMA = `
PRAGMA journal_mode = WAL;
PRAGMA busy_timeout = 3000;
CREATE TABLE IF NOT EXISTS jobs (
    id INTEGER PRIMARY KEY,
    job_id INTEGER NOT NULL,
    series_id TEXT NOT NULL,
    episode TEXT NOT NULL,
    priority INTEGER NOT NULL DEFAULT 0,
    started_at TEXT NOT NULL,
    finished_at TEXT,
    duration_ms INTEGER,
    outcome TEXT,
    error TEXT,
    video_host TEXT,
    expires_at TEXT,
    extras_found INTEGER NOT NULL DEFAULT 0,
    extras_accepted INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS jobs_started ON jobs (started_at);
CREATE INDEX IF NOT EXISTS jobs_episode ON jobs (series_id, episode);
CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY,
    time TEXT NOT NULL,
    event TEXT NOT NULL,
    data TEXT
);
CREATE INDEX IF NOT EXISTS events_time ON events (time);
`;

export const RETENTION_DAYS = 30;

export class RelayDb {
    constructor(db) {
        this.db = db;
        db.exec(SCHEMA);
        this.insertEvent = db.prepare('INSERT INTO events (time, event, data) VALUES (?, ?, ?)');
        this.insertJob = db.prepare('INSERT INTO jobs (job_id, series_id, episode, priority, started_at) VALUES (?, ?, ?, ?, ?)');
        this.finishJob = db.prepare(`UPDATE jobs SET finished_at = ?, duration_ms = ?, outcome = ?, error = ?,
            video_host = ?, expires_at = ?, extras_found = ?, extras_accepted = ? WHERE id = ?`);
    }

    event(record) {
        const { time, event, ...data } = record;
        this.insertEvent.run(time, event, Object.keys(data).length ? JSON.stringify(data) : null);
    }

    jobStarted(job, startedAt = new Date()) {
        return Number(this.insertJob.run(job.id, job.series_id, job.episode, job.priority ?? 0, startedAt.toISOString()).lastInsertRowid);
    }

    jobFinished(rowId, startedAt, result, finishedAt = new Date()) {
        this.finishJob.run(finishedAt.toISOString(), finishedAt - startedAt, result.outcome, result.error ?? null,
            result.video_host ?? null, result.expires_at ?? null, result.extras_found ?? 0, result.extras_accepted ?? 0, rowId);
    }

    /** Totals since `since` plus the most recent jobs, for status.json, the control window and --stats. */
    stats(since = new Date(Date.now() - 86400000), recent = 10) {
        const totals = this.db.prepare(`SELECT
                COUNT(*) AS jobs,
                SUM(outcome = 'completed') AS completed,
                SUM(outcome = 'failed') AS failed,
                SUM(extras_accepted) AS extras,
                CAST(AVG(CASE WHEN outcome = 'completed' THEN duration_ms END) AS INTEGER) AS avg_ms
            FROM jobs WHERE started_at >= ?`).get(since.toISOString());
        const errors = this.db.prepare(`SELECT error, COUNT(*) AS count FROM jobs
            WHERE started_at >= ? AND outcome = 'failed' GROUP BY error ORDER BY count DESC`).all(since.toISOString());
        const last = this.db.prepare(`SELECT job_id, series_id, episode, priority, started_at, duration_ms, outcome, error, extras_accepted
            FROM jobs ORDER BY id DESC LIMIT ?`).all(recent);
        return {
            since: since.toISOString(),
            jobs: totals.jobs ?? 0, completed: totals.completed ?? 0, failed: totals.failed ?? 0,
            extras: totals.extras ?? 0, avg_ms: totals.avg_ms ?? null,
            errors: Object.fromEntries(errors.map(row => [row.error, row.count])),
            recent: last.map(row => ({ ...row })),
        };
    }

    /** Events since `since`, oldest first, without the idle `worker_waiting` noise. */
    events(since, limit = 500) {
        return this.db.prepare(`SELECT time, event, data FROM events WHERE time >= ? AND event <> 'worker_waiting'
            ORDER BY id LIMIT ?`).all(since.toISOString(), limit).map(row => ({ ...row }));
    }

    prune(days = RETENTION_DAYS, now = Date.now()) {
        const cutoff = new Date(now - days * 86400000).toISOString();
        this.db.prepare('DELETE FROM events WHERE time < ?').run(cutoff);
        this.db.prepare('DELETE FROM jobs WHERE started_at < ?').run(cutoff);
    }

    close() {
        try { this.db.close(); } catch { /* Already closed. */ }
    }
}

/** null when this Node build has no node:sqlite (before 22.13 without --experimental-sqlite). */
export async function openRelayDb(file) {
    let DatabaseSync;
    try { ({ DatabaseSync } = await import('node:sqlite')); } catch { return null; }
    return new RelayDb(new DatabaseSync(file));
}
