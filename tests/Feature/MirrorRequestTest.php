<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Episode;
use App\Models\User;
use App\Support\IngestAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MirrorRequestTest extends TestCase
{
    use RefreshDatabase;

    private function actingWithProfile(): void
    {
        $user = User::factory()->create();
        $profile = $user->profiles()->create(['name' => 'ดู', 'avatar_color' => '#ff2d55']);
        $this->withSession(['profile_id' => $profile->id])->actingAs($user);
    }

    private function rongyokEpisode(): Episode
    {
        $c = Content::create([
            'title' => 'เรื่องแนวตั้ง', 'slug' => 'v1', 'source' => 'rongyok', 'source_key' => '8207',
            'type' => 'vertical', 'is_published' => true, 'maturity' => '15+',
        ]);

        return $c->episodes()->create(['number' => 1, 'title' => 'ตอนที่ 1', 'source' => 'rongyok', 'source_ref' => '1']);
    }

    public function test_unmirrored_rongyok_resolves_a_fresh_url_on_demand(): void
    {
        Cache::flush();
        $this->actingWithProfile();
        $ep = $this->rongyokEpisode();

        // rongyok advertises its current (rotating) resolver endpoint in watch.js; it returns a
        // freshly-signed Discord URL (ex in the future). NetWix resolves this itself — no agent.
        $freshUrl = 'https://cdn.discordapp.com/attachments/1/2/1.mp4?ex='.dechex(time() + 86400).'&is=x&hm=y';
        Http::fake([
            'rongyok.com/watch/watch.js' => Http::response('const r = await fetch(`/watch/xq7bza9k.php?series_id=${id}&ep=${n}`);'),
            'rongyok.com/watch/xq7bza9k.php*' => Http::response(['ok' => true, 'video_url' => $freshUrl]),
        ]);

        $this->getJson(route('episode.source', $ep))
            ->assertOk()
            ->assertJson(['ready' => true, 'kind' => 'mp4', 'url' => $freshUrl]);
    }

    public function test_stale_expired_url_is_rejected(): void
    {
        Cache::flush();
        $this->actingWithProfile();
        $ep = $this->rongyokEpisode();

        // Endpoint responds ok:true but hands back an ALREADY-EXPIRED signature — must not be served.
        $expiredUrl = 'https://cdn.discordapp.com/attachments/1/2/1.mp4?ex='.dechex(time() - 3600).'&is=x&hm=y';
        Http::fake([
            'rongyok.com/watch/watch.js' => Http::response('fetch(`/watch/xq7bza9k.php?series_id=`)'),
            'rongyok.com/watch/xq7bza9k.php*' => Http::response(['ok' => true, 'video_url' => $expiredUrl]),
        ]);

        $this->getJson(route('episode.source', $ep))
            ->assertStatus(202)
            ->assertJson(['ready' => false]);
    }

    public function test_mirrored_episode_resolves_ready(): void
    {
        $this->actingWithProfile();
        $ep = $this->rongyokEpisode();
        $ep->update(['video_url' => 'https://netwix.online/storage/media/rongyok/8207/1.mp4', 'mirrored_at' => now()]);

        $this->getJson(route('episode.source', $ep))
            ->assertOk()
            ->assertJson(['ready' => true, 'kind' => 'mp4']);
    }

    public function test_agent_connection_tracking(): void
    {
        Cache::flush();
        $this->assertFalse(IngestAgent::connected());

        IngestAgent::ping();
        $this->assertTrue(IngestAgent::connected());
        $this->assertNotNull(IngestAgent::status()['last_seen']);
    }
}
