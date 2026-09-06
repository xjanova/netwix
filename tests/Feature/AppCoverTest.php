<?php

namespace Tests\Feature;

use App\Jobs\GenerateEpisodeThumb;
use App\Models\Content;
use App\Models\Episode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Covers earned from viewing, from the APP.
 *
 * The web player has grabbed a frame off its own <canvas> since 2026-07-06. The app cannot: video
 * lives in a platform texture that Dart can't read back, so it reports "this episode is playing and
 * has no cover" and the server does the grab. That report is public (guests watch in the app), which
 * makes the ffmpeg queue reachable by anyone — the reason the hourly ceiling exists, and the reason
 * it is pinned here.
 */
class AppCoverTest extends TestCase
{
    use RefreshDatabase;

    private function episode(bool $published = true, ?string $thumb = null): Episode
    {
        $content = Content::create([
            'title' => 'เรื่องไร้ปก', 'slug' => 'no-cover-'.uniqid(), 'type' => 'series',
            'synopsis' => 'ย่อ', 'year' => 2025, 'maturity' => '13+', 'is_published' => $published,
        ]);
        $season = $content->seasons()->create(['number' => 1, 'title' => 'ซีซั่น 1']);

        return $content->episodes()->create([
            'season_id' => $season->id, 'number' => 1, 'title' => 'ตอนที่ 1',
            'video_url' => 'https://example.com/a.mp4', 'thumbnail_path' => $thumb,
        ]);
    }

    public function test_a_watched_episode_with_no_cover_queues_one_grab(): void
    {
        Queue::fake();
        $episode = $this->episode();

        $this->postJson("/api/app/episodes/{$episode->id}/cover")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.queued', true);

        Queue::assertPushed(GenerateEpisodeThumb::class, 1);
    }

    /**
     * A second viewer of the SAME uncovered episode must not queue a second ffmpeg — the reason the
     * per-episode lock is taken before the dispatch and not after it.
     */
    public function test_a_second_viewer_of_the_same_episode_queues_nothing(): void
    {
        Queue::fake();
        $episode = $this->episode();

        $this->postJson("/api/app/episodes/{$episode->id}/cover")->assertOk();
        $this->postJson("/api/app/episodes/{$episode->id}/cover")
            ->assertOk()
            ->assertJsonPath('data.status', 'in_flight');

        Queue::assertPushed(GenerateEpisodeThumb::class, 1);
    }

    public function test_an_episode_that_already_has_a_cover_queues_nothing(): void
    {
        Queue::fake();
        $episode = $this->episode(thumb: 'media/thumbs/1.webp');

        $this->postJson("/api/app/episodes/{$episode->id}/cover")
            ->assertOk()
            ->assertJsonPath('data.skipped', 'exists');

        Queue::assertNothingPushed();
    }

    public function test_an_unpublished_episode_is_not_reachable(): void
    {
        Queue::fake();
        $episode = $this->episode(published: false);

        $this->postJson("/api/app/episodes/{$episode->id}/cover")->assertNotFound();

        Queue::assertNothingPushed();
    }

    /**
     * The ceiling is the whole point of making this endpoint public: without it, one client walking
     * episode ids fills the queue with ffmpeg jobs, which is exactly how the box went down on
     * 2026-07-06. Past the ceiling nothing is queued — and the episode's own lock is released, so it
     * is not ALSO blocked for five minutes by a request we deliberately declined to run.
     */
    public function test_past_the_hourly_ceiling_nothing_is_queued(): void
    {
        Queue::fake();
        $episode = $this->episode();
        Cache::put('episode:gencover:hour:'.now()->format('YmdH'), 10_000, now()->addHour());

        $this->postJson("/api/app/episodes/{$episode->id}/cover")
            ->assertOk()
            ->assertJsonPath('data.queued', false)
            ->assertJsonPath('data.status', 'busy');

        Queue::assertNothingPushed();
        $this->assertNull(Cache::get('episode:gencover:'.$episode->id), 'the declined request must not hold the episode lock');
    }

    /**
     * A title whose cover doesn't load is reported by the app the same way the website reports it.
     * When nothing can be recovered the answer is a null url — and the title is stamped so it lands
     * in the admin's missing-covers queue instead of showing the fallback forever.
     */
    public function test_a_broken_cover_reported_from_the_app_lands_in_the_missing_queue(): void
    {
        $episode = $this->episode();
        $content = $episode->content;

        $this->postJson("/api/app/content/{$content->id}/heal-cover")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.url', null);

        $this->assertNotNull($content->fresh()->cover_missing_at);
    }
}
