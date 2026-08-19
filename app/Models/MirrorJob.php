<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per source: what the admin asked the downloader to do, and what it has actually done.
 *
 * The admin page writes `status`/`scope`/`episode_limit` and nothing else; the worker
 * ([App\Console\Commands\MirrorRun]) writes the counters and the heartbeat and nothing else. Keeping
 * those two halves apart is what makes "หยุด" trustworthy — the worker re-reads `status` between every
 * episode, so pressing stop takes effect after the file in flight, not after the whole run.
 */
class MirrorJob extends Model
{
    public const STATUS_IDLE = 'idle';
    public const STATUS_QUEUED = 'queued';      // admin pressed start, no worker has claimed it yet
    public const STATUS_RUNNING = 'running';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_DONE = 'done';
    public const STATUS_ERROR = 'error';

    /** A worker that hasn't checked in for this long is treated as gone. */
    public const HEARTBEAT_SECONDS = 120;

    protected $fillable = [
        'source', 'status', 'scope', 'episode_limit', 'done_count', 'fail_count',
        'bytes_done', 'last_episode_id', 'last_title', 'last_error',
        'worker_seen_at', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'worker_seen_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** The admin wants this source downloading right now. */
    public function isWanted(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);
    }

    /** A worker has checked in recently — so the state on screen is being acted on by something. */
    public function hasWorker(): bool
    {
        return $this->worker_seen_at !== null
            && $this->worker_seen_at->greaterThan(now()->subSeconds(self::HEARTBEAT_SECONDS));
    }

    /**
     * What the monitor should actually SAY, as opposed to what the column holds.
     *
     * `running` with no heartbeat is the one state that would otherwise lie to the admin: the row says
     * work is happening while nothing on the box is doing it. That becomes "stalled" here rather than
     * a spinner that never advances.
     */
    public function displayState(): string
    {
        if ($this->status === self::STATUS_RUNNING && ! $this->hasWorker()) {
            return 'stalled';
        }
        if ($this->status === self::STATUS_QUEUED && ! $this->hasWorker()) {
            return 'waiting';
        }

        return $this->status;
    }
}
