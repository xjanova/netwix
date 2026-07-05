<?php

namespace App\Jobs;

use App\Support\EpisodeThumbScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Seeds a cover batch onto the queue FROM A CLI WORKER, so a run keeps going even
 * after the admin closes the page or the whole browser.
 *
 * Previously the browser looped the enqueue itself: close the tab half-way through
 * a 240k "whole site" run and the rest was never queued — and a reopened page saw
 * nothing and made the admin re-issue the entire thing. Now `begin` just dispatches
 * ONE of these; it walks the scope cursor, dispatches [GenerateEpisodeThumb] in
 * chunks, records how many it has enqueued (`thumbs:{batch}:seeded` → the
 * "ส่งเข้าคิว %" bar), and re-dispatches itself for the next slice until the scope
 * is exhausted.
 *
 * It rides the fast `thumbs-now` lane so seeding stays responsive no matter how
 * deep the bulk backlog is, works in a time-boxed slice (never outlives a worker's
 * --max-time window), and bails the instant the batch's stop flag is set.
 */
class SeedEpisodeThumbs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public int $backoff = 5;

    private const CHUNK = 500;         // episodes dispatched per DB pass
    private const SEED_PER_RUN = 4000; // dispatched per invocation, then we yield the worker
    private const TIME_BUDGET = 20;    // hard safety cap (seconds) so we never outlive --max-time

    /**
     * @param  string  $batchId   the batch these covers belong to
     * @param  array   $params    scope descriptor (scope, genre_id, content_id, force)
     * @param  string  $genQueue  lane for the GenerateEpisodeThumb jobs (thumbs-now | thumbs)
     * @param  int     $after     cursor: last episode id already dispatched (scope mode)
     * @param  string  $mode      'scope' (walk the query) | 'ids' (a stored id list — redo-failed)
     * @param  int     $offset    cursor into the stored id list (ids mode)
     */
    public function __construct(
        public string $batchId,
        public array $params,
        public string $genQueue,
        public int $after = 0,
        public string $mode = 'scope',
        public int $offset = 0,
    ) {}

    public function handle(): void
    {
        if ($this->stopped()) {
            return;
        }

        $deadline = time() + self::TIME_BUDGET;
        $force = (bool) ($this->params['force'] ?? false);

        if ($this->mode === 'ids') {
            $this->seedFromIds($force, $deadline);

            return;
        }

        $this->seedFromScope($force, $deadline);
    }

    /** Re-seed an explicit id list (redo-failed) — no catalogue scan at all. */
    private function seedFromIds(bool $force, int $deadline): void
    {
        $ids = Cache::get("thumbs:{$this->batchId}:seedids", []);
        $total = count($ids);
        $dispatched = 0;

        while ($this->offset < $total) {
            if ($this->stopped()) {
                return;
            }
            $slice = array_slice($ids, $this->offset, self::CHUNK);
            foreach ($slice as $id) {
                GenerateEpisodeThumb::dispatch((int) $id, $force, $this->batchId)->onQueue($this->genQueue);
            }
            $this->offset += count($slice);
            $dispatched += count($slice);
            Cache::increment("thumbs:{$this->batchId}:seeded", count($slice));

            if ($this->offset < $total && ($dispatched >= self::SEED_PER_RUN || time() >= $deadline)) {
                $this->continueLater(); // yield the worker; carry on next cycle

                return;
            }
        }

        $this->finishSeeding();
    }

    /** Walk the scope by id cursor, dispatching one gen job per episode. */
    private function seedFromScope(bool $force, int $deadline): void
    {
        $dispatched = 0;

        while (true) {
            if ($this->stopped()) {
                return;
            }

            $episodes = EpisodeThumbScope::query($this->params)
                ->where('episodes.id', '>', $this->after)
                ->orderBy('episodes.id')
                ->take(self::CHUNK)
                ->get(['episodes.id']);

            if ($episodes->isEmpty()) {
                $this->finishSeeding();

                return;
            }

            foreach ($episodes as $ep) {
                GenerateEpisodeThumb::dispatch((int) $ep->id, $force, $this->batchId)->onQueue($this->genQueue);
            }
            $this->after = (int) $episodes->last()->id;
            $dispatched += $episodes->count();
            Cache::increment("thumbs:{$this->batchId}:seeded", $episodes->count());

            if ($episodes->count() < self::CHUNK) {
                $this->finishSeeding(); // last (short) chunk → scope exhausted

                return;
            }

            if ($dispatched >= self::SEED_PER_RUN || time() >= $deadline) {
                $this->continueLater(); // yield the worker; carry on next cycle

                return;
            }
        }
    }

    /** Re-queue ourselves to carry on next worker cycle (state travels in the ctor). */
    private function continueLater(): void
    {
        self::dispatch($this->batchId, $this->params, $this->genQueue, $this->after, $this->mode, $this->offset)
            ->onQueue('thumbs-now');
    }

    /** Mark the batch fully seeded so the UI can flip from "ส่งเข้าคิว" to "สร้างปก". */
    private function finishSeeding(): void
    {
        Cache::put("thumbs:{$this->batchId}:seed_done", true, now()->addHours(48));
    }

    private function stopped(): bool
    {
        return (bool) Cache::get("thumbs:{$this->batchId}:stop");
    }
}
