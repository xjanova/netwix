<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\SourceTitle;
use App\Models\User;
use App\Services\Import\ImportService;
use App\Support\PosterRelay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PosterRelayTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'poster-relay-test-0123456789abcdef0123456789';
    private User $admin;
    private SourceTitle $title;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Cache::flush();
        Http::preventStrayRequests();
        config(['services.rongyok.browser_relay_enabled' => true, 'services.rongyok.browser_relay_token' => $this->token,
            'services.rongyok.free_proxy_auto' => false, 'services.rongyok.proxy_url' => null]);
        Cache::put('browser-relay:heartbeat', now()->timestamp, 300);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->title = SourceTitle::create(['source' => 'rongyok', 'source_key' => '100852240', 'title' => 'Example',
            'poster_url' => 'https://rongyok.com/images/poster/example.webp']);
    }

    private function png(bool $advert = false): string
    {
        $im = imagecreatetruecolor(300, 450);
        imagefill($im, 0, 0, imagecolorallocate($im, ...($advert ? [20, 110, 40] : [120, 70, 160])));
        ob_start(); imagepng($im); $bytes = ob_get_clean(); imagedestroy($im);
        return $bytes;
    }

    private function claim(): array
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)->postJson('/api/browser-relay/jobs/claim', ['posters' => true])
            ->assertOk()->json('job');
    }

    private function complete(array $job, bool $advert = false)
    {
        return $this->postJson('/api/browser-relay/posters/'.$job['id'].'/complete', [
            'lease_token' => $job['lease_token'], 'image_url' => $this->title->poster_url,
            'image' => 'data:image/png;base64,'.base64_encode($this->png($advert)),
        ]);
    }

    public function test_blocked_image_queues_one_poster_and_serves_verified_local_bytes_after_completion(): void
    {
        Http::fake(['rongyok.com/*' => Http::response('blocked', 403)]);
        $url = route('admin.img-proxy', ['url' => $this->title->poster_url]);
        $this->actingAs($this->admin)->get($url)->assertStatus(503)->assertHeader('Retry-After', '4');
        $this->get($url)->assertStatus(503);
        $this->assertDatabaseCount('browser_relay_posters', 1);
        // Existing workers never receive an unsupported poster payload.
        $this->withHeader('Authorization', 'Bearer '.$this->token)->postJson('/api/browser-relay/jobs/claim')
            ->assertJsonPath('job', null);
        $job = $this->claim();
        $this->assertSame('poster', $job['type']);
        $this->complete($job)->assertOk();
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->assertNotNull(PosterRelay::cached($this->title->poster_url));
        $this->assertDatabaseCount('contents', 0);
        $this->assertDatabaseCount('episodes', 0);
        $this->complete($job)->assertStatus(409);
    }

    public function test_an_advert_served_as_a_valid_image_is_rejected(): void
    {
        PosterRelay::enqueue($this->title, $this->admin);
        $job = $this->claim();
        $this->complete($job, true)->assertStatus(422);
        $this->assertNull(PosterRelay::cached($this->title->poster_url));
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    public function test_import_uses_verified_cover_and_stable_episode_refs_without_saving_expiring_urls(): void
    {
        PosterRelay::enqueue($this->title, $this->admin);
        $this->complete($this->claim())->assertOk();
        Http::fake(['rongyok.com/watch/*' => Http::response('<script>const seriesData = {"episodes_count":2,"episodes":[{"episode_number":1},{"episode_number":2}]};</script>')]);
        $content = app(ImportService::class)->import($this->title->fresh(), ['type' => 'vertical', 'publish' => false]);
        $this->assertStringStartsWith('media/posters/', $content->poster_path);
        Storage::disk('public')->assertExists($content->poster_path);
        $this->assertSame(2, $content->episodes()->count());
        $this->assertSame(['1', '2'], $content->episodes()->orderBy('number')->pluck('source_ref')->all());
        $this->assertSame(0, $content->episodes()->whereNotNull('video_url')->count());
    }

    public function test_a_cover_completed_after_import_updates_poster_and_backdrop(): void
    {
        $content = Content::create(['title' => 'Example', 'slug' => 'example', 'source' => 'rongyok', 'source_key' => $this->title->source_key,
            'type' => 'vertical', 'poster_path' => $this->title->poster_url, 'backdrop_path' => $this->title->poster_url]);
        PosterRelay::enqueue($this->title, $this->admin);
        $this->complete($this->claim())->assertOk();
        $content->refresh();
        $this->assertStringStartsWith('media/posters/', $content->poster_path);
        $this->assertSame($content->poster_path, $content->backdrop_path);
    }

    public function test_a_blocked_cover_during_import_is_queued_and_healed_when_the_worker_finishes(): void
    {
        Http::fake(['rongyok.com/*' => fn ($r) => str_contains($r->url(), '/watch/')
            ? Http::response('<script>const seriesData = {"episodes_count":2,"episodes":[{"episode_number":1},{"episode_number":2}]};</script>')
            : Http::response('blocked', 403)]);
        $content = app(ImportService::class)->import($this->title, ['type' => 'vertical', 'publish' => false]);
        $this->assertDatabaseCount('browser_relay_posters', 1);
        $this->assertSame(2, $content->episodes()->count());
        $this->complete($this->claim())->assertOk();
        $this->assertStringStartsWith('media/posters/', $content->fresh()->poster_path);
    }

    public function test_non_admin_and_non_source_requests_cannot_enqueue_posters(): void
    {
        $member = User::factory()->create(['role' => 'user']);
        $this->assertFalse(PosterRelay::enqueue($this->title, $member));
        $this->actingAs($member)->get(route('admin.img-proxy', ['url' => $this->title->poster_url]))->assertForbidden();
        $this->assertDatabaseCount('browser_relay_posters', 0);
    }

    public function test_expired_poster_lease_cannot_upload_and_is_reclaimed_with_a_new_token(): void
    {
        PosterRelay::enqueue($this->title, $this->admin);
        $first = $this->claim();
        $this->travel(121)->seconds();
        $this->complete($first)->assertStatus(409);
        $next = $this->claim();
        $this->assertSame($first['id'], $next['id']);
        $this->assertNotSame($first['lease_token'], $next['lease_token']);
        $this->complete($next)->assertOk();
    }
}
