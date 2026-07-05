<?php

namespace App\Jobs;

use App\Models\Episode;
use App\Support\EpisodeThumbnailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Generates ONE episode cover off the web request.
 *
 * The admin "สร้างปกตอน" page runs in php-fpm, where proc_open / exec / shell_exec
 * are DISABLED — so ffmpeg simply cannot be spawned there (every inline attempt
 * silently failed). This job instead runs on the scheduled `queue:work --queue=thumbs`
 * worker (see routes/console.php), which is a CLI process where proc_open works.
 *
 * Progress is reported back through per-batch cache counters the admin page polls,
 * so the bar moves correctly for BOTH "skip existing" and "regenerate all" (force) —
 * a null-thumbnail count can't measure force runs, but the counter can.
 */
class GenerateEpisodeThumb implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 150;   // one big download + ffmpeg frame grab
    public int $backoff = 15;

    public function __construct(
        public int $episodeId,
        public bool $force = false,
        public ?string $batchId = null,
    ) {}

    public function handle(EpisodeThumbnailer $thumbnailer): void
    {
        @ini_set('memory_limit', '512M'); // whole-file download can be sizeable

        // Honour a hard-stop pressed on the admin page: jobs already sitting in
        // the queue skip their work but still tally so the bar can complete.
        if ($this->batchId && Cache::get("thumbs:{$this->batchId}:stop")) {
            $this->tally(true, 'stopped', null);

            return;
        }

        $ep = Episode::with('content:id,title,source_key')->find($this->episodeId);
        if (! $ep) {
            $this->tally(false, 'error', null);

            return;
        }

        $pid = getmypid() ?: 0;
        $this->reportAgent($pid, $ep, false);           // "this agent is now working on …"
        $status = $thumbnailer->generate($ep, $this->force);
        $ok = in_array($status, ['ok', 'exists'], true);
        $this->reportAgent($pid, $ep, true);            // bump this agent's done count
        $this->tally($ok, $status, trim(($ep->content?->title ?? '—').' · ตอน '.$ep->number));
    }

    /**
     * Publish this worker's live activity to a shared hash so the admin page can
     * render one "Agent" card per running worker. Keyed by PID; each field also
     * carries a timestamp so the reader can drop entries from dead/finished
     * workers (no per-field TTL in a Redis hash).
     */
    private function reportAgent(int $pid, Episode $ep, bool $completed): void
    {
        try {
            $key = 'netwix:thumbs:agents';
            $cur = json_decode((string) Redis::hget($key, (string) $pid), true) ?: [];
            Redis::hset($key, (string) $pid, json_encode([
                'title' => $ep->content?->title ?? '—',
                'ep' => (int) $ep->number,
                'done' => (int) ($cur['done'] ?? 0) + ($completed ? 1 : 0),
                'ts' => time(),
            ], JSON_UNESCAPED_UNICODE));
            Redis::expire($key, 120); // whole hash self-cleans if every worker stops
        } catch (Throwable $e) {
            // activity reporting is best-effort — never fail a job over it
        }
    }

    /** Final failure (throw / timeout) still counts, so the bar never stalls. */
    public function failed(Throwable $e): void
    {
        $this->tally(false, 'error', null);
    }

    private function tally(bool $ok, string $status, ?string $label): void
    {
        if (! $this->batchId) {
            return;
        }
        $key = "thumbs:{$this->batchId}:";
        Cache::increment($key.'proc');
        if (! $ok) {
            Cache::increment($key.'fail');
        }
        Cache::put($key.'last', [
            'ok' => $ok,
            'text' => $label ?: '—',
            'reason' => $status,
        ], now()->addMinutes(30));
    }
}
