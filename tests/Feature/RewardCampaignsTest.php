<?php

namespace Tests\Feature;

use App\Models\AppToken;
use App\Models\Content;
use App\Models\User;
use App\Services\Membership;
use App\Services\RewardCampaigns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class RewardCampaignsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_launch_day_checkin_can_claim_the_new_bonus_without_repeating_the_base_reward(): void
    {
        $u = $this->member();
        app(Membership::class)->addCoins($u, 2, 'daily');
        $this->assertTrue(app(Membership::class)->earn($u, 'daily')['ok']);
        $this->assertSame(5, (int) $u->fresh()->coins);
        $this->assertFalse(app(Membership::class)->earn($u, 'daily')['ok']);
        $this->assertSame(5, (int) $u->fresh()->coins);
    }

    private function member(): User
    {
        $u = User::factory()->create();
        $p = $u->profiles()->create(['name' => 'ผู้ชม', 'avatar_color' => '#ff2d55']);
        $this->actingAs($u)->withSession(['profile_id' => $p->id]);

        return $u;
    }

    private function title(string $type = 'series'): Content
    {
        $c = Content::create(['title' => 'ทดสอบ', 'slug' => uniqid('rewards-'), 'type' => $type, 'maturity' => '13+', 'is_published' => true]);
        $c->episodes()->create(['number' => 1, 'title' => 'ตอนที่ 1']);

        return $c;
    }

    private function watch(User $u, Content $c, int $beats = 20): void
    {
        $s = app(RewardCampaigns::class);
        $token = $s->startWatch($u, $c, $c->episodes()->first(), 0)['token'];
        for ($i = 1; $i <= $beats; $i++) {
            $this->travel(15)->seconds();
            $this->assertTrue($s->watchBeat($u, $token, $i * 15)['ok']);
        }
    }

    public function test_daily_checkin_and_share_award_base_plus_bonus_once_across_web_and_app(): void
    {
        $u = $this->member();
        $this->postJson('/api/rewards/earn', ['kind' => 'daily'])->assertOk()->assertJsonPath('data.coins', 5);
        $token = AppToken::issue($u, 'phone');
        $this->withToken($token)->postJson('/api/app/coins/earn', ['kind' => 'daily'])->assertStatus(422);
        $this->withToken($token)->postJson('/api/app/coins/earn', ['kind' => 'share'])->assertOk()->assertJsonPath('data.coins', 8);
        $this->postJson('/api/rewards/earn', ['kind' => 'share'])->assertOk()->assertJsonPath('data.coins', 9);
        $this->assertDatabaseCount('reward_campaign_completions', 2);
    }

    public function test_three_different_titles_and_categories_pay_once_and_progress_matches_both_clients(): void
    {
        $u = $this->member();
        foreach (['movie', 'series', 'vertical'] as $type) {
            $this->watch($u, $this->title($type));
        }
        $this->assertSame(40, (int) $u->fresh()->coins);
        $web = $this->getJson('/api/rewards/campaigns')->assertOk()->json('data.items');
        $phone = $this->withToken(AppToken::issue($u, 'phone'))->getJson('/api/app/rewards/campaigns')->assertOk()->json('data.items');
        $this->assertSame($web, $phone);
        $this->assertSame('earned', collect($web)->firstWhere('key', 'new_titles')['status']);
        $this->assertSame('earned', collect($web)->firstWhere('key', 'explorer')['status']);
        $this->watch($u, $this->title());
        $this->assertSame(40, (int) $u->fresh()->coins);
    }

    public function test_rewatching_one_title_is_not_three_titles_or_three_categories(): void
    {
        $u = $this->member();
        $c = $this->title();
        for ($i = 0; $i < 3; $i++) {
            $this->watch($u, $c);
        }
        $this->assertSame(0, (int) $u->fresh()->coins);
        $state = collect(app(RewardCampaigns::class)->state($u));
        $this->assertSame(1, $state->firstWhere('key', 'new_titles')['progress']);
        $this->assertSame(1, $state->firstWhere('key', 'explorer')['progress']);
    }

    public function test_seek_spam_background_and_parallel_tokens_cannot_mint_watching_time(): void
    {
        $u = $this->member();
        $c = $this->title();
        $s = app(RewardCampaigns::class);
        $token = $s->startWatch($u, $c, $c->episodes()->first(), 0)['token'];
        $this->assertSame(0, $s->watchBeat($u, $token, 300)['seconds']);
        $this->travel(15)->seconds();
        $this->assertSame(0, $s->watchBeat($u, $token, 3000)['seconds']);
        $this->travel(60)->seconds();
        $this->assertSame(0, $s->watchBeat($u, $token, 3060)['seconds']);
        $s->startWatch($u, $c, $c->episodes()->first(), 3060);
        $this->assertFalse($s->watchBeat($u, $token, 3075)['ok']);
        $this->assertSame(0, (int) $u->fresh()->coins);
    }

    public function test_authenticated_api_heartbeats_use_the_same_server_validation(): void
    {
        $u = $this->member();
        $c = $this->title();
        $this->withToken(AppToken::issue($u, 'phone'));
        $token = $this->postJson('/api/app/rewards/watch/start', ['content_id' => $c->id, 'episode_id' => $c->episodes()->first()->id, 'position' => 0])->assertOk()->json('data.token');
        $this->travel(15)->seconds();
        $this->postJson('/api/rewards/watch/beat', ['token' => $token, 'position' => 15])->assertOk()->assertJsonPath('data.seconds', 15);
        $this->postJson('/api/app/rewards/watch/beat', ['token' => str_repeat('z', 48), 'position' => 300])->assertStatus(409);
    }

    public function test_daily_rewards_reset_at_bangkok_midnight_and_old_watch_tokens_expire(): void
    {
        $this->travelTo(now()->setTimezone('Asia/Bangkok')->setTime(23, 59, 45));
        $u = $this->member();
        $c = $this->title();
        $s = app(RewardCampaigns::class);
        app(Membership::class)->earn($u, 'daily');
        $token = $s->startWatch($u, $c, $c->episodes()->first(), 0)['token'];
        $this->travel(20)->seconds();
        $this->assertFalse($s->watchBeat($u, $token, 20)['ok']);
        $this->assertTrue(app(Membership::class)->earn($u, 'daily')['ok']);
        $this->assertSame(10, (int) $u->fresh()->coins);
    }

    public function test_friend_acceptance_pays_extra_and_redeeming_twice_or_self_does_not(): void
    {
        $u = $this->member();
        $m = app(Membership::class);
        $code = $m->ensureCode($u);
        $friend = User::factory()->create();
        $this->assertTrue($m->redeem($friend, $code)['ok']);
        $this->assertSame(25, (int) $u->fresh()->coins);
        $this->assertFalse($m->redeem($friend, $code)['ok']);
        $this->assertFalse($m->redeem($u, $code)['ok']);
        $this->assertSame(25, (int) $u->fresh()->coins);
        $this->assertDatabaseCount('reward_campaign_completions', 1);
    }

    public function test_line_signup_preserves_the_referral_invite_and_rewards_the_inviter(): void
    {
        $inviter = User::factory()->create();
        $code = app(Membership::class)->ensureCode($inviter);
        $this->withoutVite()->get('/register?ref='.$code)->assertOk();
        $social = (new SocialiteUser)->map(['id' => 'line-reward-new', 'name' => 'เพื่อนใหม่', 'email' => null, 'avatar' => null]);
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($social);
        Socialite::shouldReceive('driver')->with('line')->andReturn($provider);
        $this->get('/auth/line/callback')->assertRedirect();
        $this->assertSame($inviter->id, User::where('provider_id', 'line-reward-new')->firstOrFail()->referred_by);
        $this->assertSame(25, (int) $inviter->fresh()->coins);
    }

    public function test_admin_can_disable_campaigns_without_changing_base_membership_settings(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $cfg = RewardCampaigns::DEFAULTS;
        foreach ($cfg as &$c) {
            $c['enabled'] = false;
        }
        $this->actingAs($admin)->put(route('admin.reward-campaigns.update'), ['campaigns' => $cfg])->assertRedirect();
        $this->assertSame([], app(RewardCampaigns::class)->state($admin));
        $this->assertSame(3, app(Membership::class)->config()['free_episodes']);
        $this->assertTrue(app(Membership::class)->earn($admin, 'daily')['ok']);
        $this->assertSame(2, (int) $admin->fresh()->coins);
    }

    public function test_unpublished_and_mismatched_episodes_are_never_rewarded(): void
    {
        $this->member();
        $c = $this->title();
        $foreign = $this->title()->episodes()->first();
        $this->postJson('/api/rewards/watch/start', ['content_id' => $c->id, 'episode_id' => $foreign->id, 'position' => 0])->assertUnprocessable();
        $c->update(['is_published' => false]);
        $this->postJson('/api/rewards/watch/start', ['content_id' => $c->id, 'position' => 0])->assertNotFound();
    }
}
