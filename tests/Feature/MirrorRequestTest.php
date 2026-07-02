<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Episode;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MirrorRequestTest extends TestCase
{
    use RefreshDatabase;

    private function actingWithProfile(): array
    {
        $user = User::factory()->create();
        $profile = $user->profiles()->create(['name' => 'ดู', 'avatar_color' => '#ff2d55']);
        $this->withSession(['profile_id' => $profile->id])->actingAs($user);

        return [$user, $profile];
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

    public function test_customer_request_queues_and_increments(): void
    {
        $this->actingWithProfile();
        $ep = $this->rongyokEpisode();

        $this->postJson(route('mirror.request', $ep))->assertStatus(202)->assertJson(['queued' => true, 'requests' => 1]);

        $ep->refresh();
        $this->assertNotNull($ep->mirror_requested_at);
        $this->assertSame(1, $ep->mirror_requests);
        $this->assertTrue($ep->is_pending_customer);

        // Same profile within the debounce window doesn't double-count.
        $this->postJson(route('mirror.request', $ep))->assertStatus(202);
        $this->assertSame(1, $ep->fresh()->mirror_requests);
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
}
