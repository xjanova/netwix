<?php

namespace Tests\Feature;

use App\Models\AppToken;
use App\Models\Content;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The app follows the same admin settings and account record as the web. */
class AppWebSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function title(): Content
    {
        $content = Content::create([
            'title' => 'เรื่องทดสอบ', 'slug' => uniqid('sync-'), 'type' => 'series',
            'maturity' => '13+', 'is_published' => true,
        ]);
        foreach (range(1, 5) as $n) {
            $content->episodes()->create([
                'number' => $n, 'title' => 'ตอนที่ '.$n,
                'video_url' => 'https://video.example.test/ep'.$n.'.mp4',
            ]);
        }

        return $content->load('episodes');
    }

    public function test_an_app_that_declares_the_guest_quota_gets_the_web_signup_answer(): void
    {
        Setting::write('membership_config', json_encode(['free_episodes' => 2]));
        $eps = $this->title()->episodes;
        $quota = ['X-NetWix-Guest-Quota' => '1'];

        $this->getJson('/api/app/episodes/'.$eps[1]->id.'/source', $quota)->assertOk();
        $this->getJson('/api/app/episodes/'.$eps[2]->id.'/source', $quota)->assertForbidden()
            ->assertJsonPath('data.error', 'signup_required')
            ->assertJsonPath('data.freeEpisodes', 2)
            ->assertJsonMissingPath('data.url');
    }

    public function test_older_app_builds_and_members_keep_playing_past_the_quota(): void
    {
        Setting::write('membership_config', json_encode(['free_episodes' => 2]));
        $ep = $this->title()->episodes[4];

        // A build that never sends the header cannot show the prompt, so it is not gated.
        $this->getJson('/api/app/episodes/'.$ep->id.'/source')->assertOk();

        $member = User::factory()->create(['plan' => 'basic']);
        $this->withToken(AppToken::issue($member, 'phone'))
            ->getJson('/api/app/episodes/'.$ep->id.'/source', ['X-NetWix-Guest-Quota' => '1'])->assertOk();
    }

    public function test_contact_details_round_trip_between_app_and_web(): void
    {
        $user = User::factory()->create(['name' => 'เดิม', 'email' => 'viewer@example.test', 'plan' => 'basic']);
        $token = AppToken::issue($user, 'phone');

        $this->withToken($token)->postJson('/api/app/account', [
            'name' => '  สมชาย  ', 'phone' => '0812345678', 'address' => 'กรุงเทพฯ',
        ])->assertOk()->assertJsonPath('data.name', 'สมชาย')->assertJsonPath('data.phone', '0812345678');

        // The web settings page reads these same columns.
        $this->assertSame(['สมชาย', '0812345678', 'กรุงเทพฯ'], [$user->refresh()->name, $user->phone, $user->address]);
        $this->withToken($token)->getJson('/api/app/account')->assertOk()->assertJsonPath('data.address', 'กรุงเทพฯ');

        $this->withToken($token)->postJson('/api/app/account', ['name' => '', 'phone' => str_repeat('9', 40)])
            ->assertUnprocessable();
        $this->withToken($token)->postJson('/api/app/account', ['name' => 'สมชาย', 'email' => 'x@evil.test'])->assertOk();
        $this->assertSame('viewer@example.test', $user->refresh()->email);
        $this->assertNull($user->phone);
    }

    public function test_contact_details_need_a_signed_in_device(): void
    {
        $this->getJson('/api/app/account')->assertUnauthorized();
        $this->postJson('/api/app/account', ['name' => 'x'])->assertUnauthorized();
    }
}
