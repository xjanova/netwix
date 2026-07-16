<?php

namespace App\Support;

use App\Models\MarketingClip;
use App\Models\Setting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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
 * Upload style differs per surface, for a reason — see postReel():
 *   - feed  → HOSTED: hand FB the clip's public URL and it fetches the file itself.
 *   - reels → BYTES: FB's Reels fetcher honours robots.txt, which the edge uses to block Meta,
 *             so a hosted Reel upload can never work here. We POST the file instead.
 * Neither path runs ffmpeg, so both stay safe on a shared worker.
 */
class FacebookPublisher
{
    /**
     * True once a page id + token are present. Two ways to connect:
     *   1. Admin UI "เชื่อมต่อ Facebook" (OAuth) → credentials in the settings table
     *      (token encrypted at rest). Connecting via the UI is an explicit act, so it
     *      enables posting by itself.
     *   2. Legacy .env FB_PAGE_ID/FB_PAGE_TOKEN — still honoured, but only with the
     *      FB_AUTOPOST_ENABLED master flag, exactly as before.
     */
    public function enabled(): bool
    {
        if (filled(Setting::get('fb_page_id')) && filled(Setting::get('fb_page_token'))) {
            return true;
        }

        return (bool) config('services.facebook.enabled')
            && filled(config('services.facebook.page_id'))
            && filled(config('services.facebook.page_token'));
    }

    /** Display name of the connected page (only known for UI-connected pages). */
    public function pageName(): ?string
    {
        return Setting::get('fb_page_name');
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

        // Reels needs the bytes off disk (see postReel); feed just needs the public URL.
        $filePath = $clip->file_path ? Storage::disk('public')->path($clip->file_path) : null;
        if (in_array('reels', $targets, true) && (! $filePath || ! is_readable($filePath))) {
            return ['dry_run' => false, 'results' => [], 'error' => 'no_local_file'];
        }

        $caption = (string) ($clip->caption ?? '');
        $results = [];
        $errors = [];

        foreach (array_unique($targets) as $target) {
            try {
                $id = $target === 'reels'
                    ? $this->postReel($filePath, $caption)
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
     * Reels use the 3-phase resumable API (start → upload → finish=PUBLISHED).
     *
     * The upload sends the BYTES, unlike the feed video (which hands FB a file_url to fetch).
     * That asymmetry is deliberate and hard-won: Reels' fetcher obeys robots.txt, and
     * Cloudflare's managed robots.txt serves `User-agent: meta-externalagent / Disallow: /`
     * at the edge — so every hosted-upload Reel died on
     *   422 FileUrlProcessingError "got status code: 403 Restricted by robots.txt"
     * even though the mp4 itself serves 200 to everyone. Allowing that agent through would
     * also invite Meta to crawl the whole site for AI training, so we post the bytes instead.
     * ~10MB over HTTP on the light `clips-post` lane — I/O-bound, no ffmpeg, no re-read.
     */
    private function postReel(string $filePath, string $caption): ?string
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

        $size = @filesize($filePath);
        if ($size === false || $size <= 0) {
            throw new RuntimeException('reel_file_unreadable');
        }

        $upload = Http::withBody(file_get_contents($filePath), 'application/octet-stream')
            ->timeout(300)
            ->withHeaders([
                'Authorization' => 'OAuth '.$this->token(),
                'offset' => '0',
                'file_size' => (string) $size,
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
        return (string) (Setting::get('fb_page_id') ?: config('services.facebook.page_id'));
    }

    private function token(): string
    {
        return (string) (Setting::get('fb_page_token') ?: config('services.facebook.page_token'));
    }
}
