<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Episode;
use App\Models\User;
use App\Support\IngestAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    public function test_unmirrored_rongyok_resolves_as_not_ready(): void
    {
        $this->actingWithProfile();
        $ep = $this->rongyokEpisode();

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
