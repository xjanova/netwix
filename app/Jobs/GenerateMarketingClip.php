<?php

namespace App\Jobs;

use App\Models\ClipCampaignPost;
use App\Models\MarketingClip;
use App\Support\CaptionWriter;
use App\Support\ClipMaker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Cuts ONE marketing clip with ffmpeg on the CLI `clips` queue.
 *
 * Same reason as GenerateEpisodeThumb: php-fpm can't spawn ffmpeg (proc_open disabled),
 * so the admin page only enqueues — the scheduled CLI worker (routes/console.php) does
 * the download + encode. Progress + live "agent" cards are reported through Redis so the
 * admin page can show which titles are being cut right now.
 */
class GenerateMarketingClip implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 300;   // segment downloads + a full re-encode
    public int $backoff = 20;

    public function __construct(
        public int $clipId,
        public ?string $batchId = null,
    ) {}

    public function handle(ClipMaker $maker, CaptionWriter $captions): void
    {
        if ($this->batchId && Cache::get("clips:{$this->batchId}:stop")) {
            $this->tally(true, 'stopped', null);

            return;
        }

        $clip = MarketingClip::with('content:id,title', 'episode:id,content_id,number')->find($this->clipId);
        if (! $clip) {
            $this->tally(false, 'error', null);

            return;
        }

        $clip->update(['status' => 'processing']);
        $pid = getmypid() ?: 0;
        $label = trim(($clip->content?->title ?? '—').($clip->episode ? ' · ตอน '.$clip->episode->number : ''));
        $this->reportAgent($pid, $label, false);

        $status = $maker->make($clip);

        // Auto-draft a caption the moment a clip is ready (unless the admin already wrote
        // one). This is a plain HTTP call, safe on the CLI worker; never let it fail the job.
        if ($status === 'ok' && blank($clip->caption)) {
            try {
                $clip->update(['caption' => $captions->for($clip)]);
            } catch (Throwable $e) {
                report($e);
            }
        }

        // Campaign clips flow straight on to Facebook once cut. Success → hand off to the
        // light `clips-post` lane (HTTP upload, never ffmpeg). Failure → flag the campaign
        // post so the admin log shows why this slot produced nothing (source down, etc.).
        if ($clip->auto_post && $clip->campaign_id) {
            if ($status === 'ok') {
                PostClipToFacebook::dispatch($clip->id)->onQueue('clips-post');
            } else {
                $this->flagCampaignPost($clip->id, 'cut_failed:'.$status);
            }
        }

        $this->reportAgent($pid, $label, true);
        $this->tally($status === 'ok', $status, $label);
    }

    /** Mark the campaign-post that owns this clip as failed (best-effort). */
    private function flagCampaignPost(int $clipId, string $reason): void
    {
        try {
            ClipCampaignPost::where('marketing_clip_id', $clipId)
                ->whereNotIn('status', ['posted', 'failed'])
                ->latest('id')->first()?->markFailed($reason);
        } catch (Throwable $e) {
            // best-effort
        }
    }

    /** Publish this worker's live activity → one "agent" card per running worker. */
    private function reportAgent(int $pid, string $label, bool $completed): void
    {
        try {
            $key = 'netwix:clips:agents';
            $cur = json_decode((string) Redis::hget($key, (string) $pid), true) ?: [];
            Redis::hset($key, (string) $pid, json_encode([
                'label' => $label,
                'done' => (int) ($cur['done'] ?? 0) + ($completed ? 1 : 0),
                'ts' => time(),
            ], JSON_UNESCAPED_UNICODE));
            Redis::expire($key, 300);
        } catch (Throwable $e) {
            // best-effort
        }
    }

    public function failed(Throwable $e): void
    {
        // Make sure a hard failure leaves a visible state, not a stuck "processing".
        MarketingClip::whereKey($this->clipId)->where('status', 'processing')
            ->update(['status' => 'failed', 'error' => 'error']);
        $this->flagCampaignPost($this->clipId, 'cut_failed:error');
        $this->tally(false, 'error', null);
    }

    private function tally(bool $ok, string $status, ?string $label): void
    {
        if (! $this->batchId) {
            return;
        }
        $key = "clips:{$this->batchId}:";
        Cache::increment($key.'proc');
        if (! $ok) {
            Cache::increment($key.'fail');
        }
        Cache::put($key.'last', ['ok' => $ok, 'text' => $label ?: '—', 'reason' => $status], now()->addHours(6));
    }
}
