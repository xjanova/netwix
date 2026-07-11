<?php

namespace App\Support;

use App\Models\MarketingClip;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Publishes a finished marketing clip to the NetWix Facebook page via the Graph API.
 *
 * Design guarantees:
 *   - NEVER throws to the caller. Every path returns a structured result the pipeline can
 *     record, so one bad post can't crash the queue worker.
 *   - Graceful degradation: with no page token configured it runs in DRY-RUN — it sends
 *     nothing and returns dry_run=true. The whole campaign pipeline is therefore testable
 *     today; drop `FB_PAGE_ID` + `FB_PAGE_TOKEN` into .env later and real posting turns on
 *     with zero code change. (Same "works with zero credentials" philosophy as CaptionWriter.)
 *
 * Uploads are HOSTED: we hand Facebook the clip's public URL (netwix.online/storage/…) and
 * FB fetches it. No binary streaming from PHP, so this is light on CPU/RAM — unlike ffmpeg,
 * it is safe to run on a shared worker.
 */
class FacebookPublisher
{
    /** True once a page id + token are present and auto-post is switched on. */
    public function enabled(): bool
    {
        return (bool) config('services.facebook.enabled')
            && filled(config('services.facebook.page_id'))
            && filled(config('services.facebook.page_token'));
    }

    /**
     * Post a clip to the requested surfaces (subset of reels|feed). Returns:
     *   ['dry_run' => bool, 'results' => ['feed' => '<id>', …], 'error' => ?string]
     * dry_run=true means Facebook was not connected and nothing was sent.
     *
     * @param  array<int, string>  $targets
     * @return array{dry_run: bool, results: array<string, string>, error: ?string}
     */
    public function postClip(MarketingClip $clip, array $targets): array
    {
        if (! $this->enabled()) {
            return ['dry_run' => true, 'results' => [], 'error' => null];
        }

        $fileUrl = $clip->file_url;
        if (blank($fileUrl)) {
            return ['dry_run' => false, 'results' => [], 'error' => 'no_file_url'];
        }

        $caption = (string) ($clip->caption ?? '');
        $results = [];
        $errors = [];

        foreach (array_unique($targets) as $target) {
            try {
                $id = $target === 'reels'
                    ? $this->postReel($fileUrl, $caption)
                    : $this->postFeedVideo($fileUrl, $caption);
                if ($id) {
                    $results[$target] = $id;
                } else {
                    $errors[] = "{$target}:no_id";
                }
            } catch (Throwable $e) {
                $errors[] = $target.':'.mb_substr($e->getMessage(), 0, 80);
            }
        }

        return [
            'dry_run' => false,
            'results' => $results,
            'error' => $errors ? implode('; ', $errors) : null,
        ];
    }

    // ---- Graph API calls ----------------------------------------------------

    /** Simple one-shot feed video (also appears in the page's Videos tab). */
    private function postFeedVideo(string $fileUrl, string $caption): ?string
    {
        $resp = Http::asForm()->timeout(120)->post("{$this->base()}/{$this->page()}/videos", [
            'file_url' => $fileUrl,
            'description' => $caption,
            'access_token' => $this->token(),
        ]);
        $this->assertOk($resp);

        return $resp->json('id');
    }

    /**
     * Reels use the 3-phase resumable API (start → hosted upload → finish=PUBLISHED).
     * The upload phase is "hosted": we pass the public file_url in a header and FB pulls it.
     */
    private function postReel(string $fileUrl, string $caption): ?string
    {
        $start = Http::asForm()->timeout(60)->post("{$this->base()}/{$this->page()}/video_reels", [
            'upload_phase' => 'start',
            'access_token' => $this->token(),
        ]);
        $this->assertOk($start);

        $videoId = $start->json('video_id');
        $uploadUrl = $start->json('upload_url');
        if (! $videoId || ! $uploadUrl) {
            throw new RuntimeException('reel_start_no_url');
        }

        $upload = Http::timeout(180)->withHeaders([
            'Authorization' => 'OAuth '.$this->token(),
            'file_url' => $fileUrl,
        ])->post($uploadUrl);
        $this->assertOk($upload);

        $finish = Http::asForm()->timeout(60)->post("{$this->base()}/{$this->page()}/video_reels", [
            'upload_phase' => 'finish',
            'video_id' => $videoId,
            'video_state' => 'PUBLISHED',
            'description' => $caption,
            'access_token' => $this->token(),
        ]);
        $this->assertOk($finish);

        return (string) $videoId;
    }

    private function assertOk(Response $resp): void
    {
        if ($resp->successful() && ! $resp->json('error')) {
            return;
        }
        $msg = $resp->json('error.message') ?: ('HTTP '.$resp->status());
        throw new RuntimeException((string) $msg);
    }

    private function base(): string
    {
        return 'https://graph.facebook.com/'.config('services.facebook.api_version', 'v21.0');
    }

    private function page(): string
    {
        return (string) config('services.facebook.page_id');
    }

    private function token(): string
    {
        return (string) config('services.facebook.page_token');
    }
}
