<?php

namespace App\Jobs;

use App\Models\SourceTitle;
use App\Services\Import\ImportService;
use App\Services\Import\SourceRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Catalogue sync as a BACKGROUND job (drained by the scheduled `sync`-queue worker, see
 * routes/console.php). Moved off the web request because a full scrape (24hdx=66 pages, 9nung=92)
 * outran Cloudflare's ~100s proxy timeout → the browser saw a failed request and the auto-retry fired
 * another while the first was still running server-side → 5+ concurrent 30-minute scrapes hammering
 * the source (incident 2026-07-06). Here the sync runs to completion regardless of any HTTP timeout,
 * the admin UI just polls [ImportController::syncProgress], and single-flight is guaranteed.
 *
 * State lives in cache under `sync:{source}:*`:
 *   status  — queued | running | done | stopped | error
 *   synced  — titles emitted so far this run (drives the live count)
 *   added   — NEW titles vs the pre-run count
 *   message — final human summary · error — final human error
 *   stop    — hard-stop flag (checked each page → SyncStopped)
 */
class SyncCatalogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A big/throttled catalogue can be slow — but never let it run away. */
    public int $timeout = 600;

    /** The admin controls retries (the UI re-dispatches); never silently re-run a failed sync. */
    public int $tries = 1;

    public function __construct(public string $source, public int $maxPages = 100) {}

    /** Hard single-flight per source at the queue level (belt-and-suspenders with the controller check). */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('sync:'.$this->source))->dontRelease()->expireAfter(900)];
    }

    public function handle(ImportService $importer, SourceRegistry $registry): void
    {
        $key = 'sync:'.$this->source;
        $ttl = now()->addHours(2);
        $before = SourceTitle::where('source', $this->source)->count();

        Cache::put("{$key}:status", 'running', $ttl);
        Cache::put("{$key}:synced", 0, $ttl);
        Cache::forget("{$key}:error");
        Cache::forget("{$key}:message");

        try {
            $count = $importer->sync($this->source, $this->maxPages, function (int $running) use ($key, $ttl) {
                Cache::put("{$key}:synced", $running, $ttl);
                if (Cache::get("{$key}:stop")) {
                    throw new SyncStopped();
                }
            });

            $added = max(0, SourceTitle::where('source', $this->source)->count() - $before);
            $name = $registry->get($this->source)?->displayName() ?? $this->source;
            Cache::put("{$key}:added", $added, $ttl);
            Cache::put("{$key}:message", "ซิงค์จาก {$name} แล้ว ({$count} เรื่อง"
                .($added > 0 ? " · ใหม่ {$added} เรื่อง" : ' · ไม่มีเรื่องใหม่').')', $ttl);
            Cache::put("{$key}:status", 'done', $ttl);
        } catch (SyncStopped $e) {
            $added = max(0, SourceTitle::where('source', $this->source)->count() - $before);
            Cache::put("{$key}:added", $added, $ttl);
            Cache::put("{$key}:message", "หยุดการซิงค์แล้ว".($added > 0 ? " (ได้เรื่องใหม่ {$added} เรื่อง)" : ''), $ttl);
            Cache::put("{$key}:status", 'stopped', $ttl);
        } catch (\Throwable $e) {
            // A dropped connection here usually means the source throttled/blocked us (often after too
            // many requests) — say so plainly instead of a raw stack message.
            $msg = $e instanceof ConnectionException
                ? 'เชื่อมต่อแหล่งต้นทางไม่ได้ (อาจถูกบล็อกชั่วคราวจากการเรียกถี่เกินไป) — ลองใหม่ภายหลัง'
                : 'ซิงค์ไม่สำเร็จ: '.$e->getMessage();
            Cache::put("{$key}:error", $msg, $ttl);
            Cache::put("{$key}:status", 'error', $ttl);
        } finally {
            Cache::forget("{$key}:stop");
        }
    }

    /** Worker killed the job (timeout) or handle() threw before recording → don't spin on "running". */
    public function failed(\Throwable $e): void
    {
        $key = 'sync:'.$this->source;
        $ttl = now()->addHours(2);
        Cache::put("{$key}:status", 'error', $ttl);
        Cache::put("{$key}:error", 'ซิงค์ไม่สำเร็จ (หมดเวลา หรือมีข้อผิดพลาด) กรุณาลองใหม่อีกครั้ง', $ttl);
        Cache::forget("{$key}:stop");
    }
}
