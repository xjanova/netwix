<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Setting;
use App\Models\User;
use App\Services\GuestWatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class GuestWatchSignupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
    }

    private function title(string $type = 'series'): Content
    {
        $content = Content::create([
            'title' => 'เรื่องทดสอบ', 'slug' => uniqid('guest-'), 'type' => $type,
            'maturity' => '13+', 'is_published' => true,
        ]);
        foreach (range(1, 5) as $n) {
            $content->episodes()->create([
                'number' => $n, 'title' => 'ตอนที่ '.$n,
                'video_url' => 'https://'.($n > 3 ? 'locked-video' : 'free-video').'.example.test/ep'.$n.'.mp4',
            ]);
        }

        return $content->load('episodes');
    }

    public function test_guests_get_three_previews_then_a_signup_response_without_a_video_url(): void
    {
        $eps = $this->title()->episodes;
        foreach ([0, 2] as $i) {
            $this->getJson(route('episode.source', $eps[$i]))->assertOk()->assertJsonPath('ready', true);
        }
        $this->getJson(route('episode.source', $eps[3]))->assertForbidden()
            ->assertJsonPath('error', 'signup_required')
            ->assertJsonPath('signupUrl', app(GuestWatch::class)->signupUrl($eps[3]->content, $eps[3]))
            ->assertJsonMissingPath('url');
        Http::assertNothingSent();
    }

    public function test_both_players_hide_locked_links_and_keep_the_requested_episode(): void
    {
        foreach (['series', 'vertical'] as $type) {
            $content = $this->title($type);
            $this->get(route('watch', [$content, $content->episodes[3]]))->assertOk()
                ->assertViewHas($type === 'vertical' ? 'start' : 'startIndex', 3)
                ->assertSee('สมัครฟรี เพื่อดูต่อ')
                ->assertSee('สมัครเลย')
                ->assertSee('free-video.example.test', false)
                ->assertDontSee('locked-video.example.test', false);
        }
    }

    public function test_the_quota_uses_the_admin_setting_including_zero(): void
    {
        $eps = $this->title()->episodes;
        Setting::write('membership_config', json_encode(['free_episodes' => 5]));
        $this->getJson(route('episode.source', $eps[4]))->assertOk();

        Setting::write('membership_config', json_encode(['free_episodes' => 0]));
        $this->getJson(route('episode.source', $eps[0]))->assertForbidden()->assertJsonPath('error', 'signup_required');
    }

    public function test_a_basic_signed_in_member_is_not_asked_to_register_again(): void
    {
        $content = $this->title();
        $user = User::factory()->create(['plan' => 'basic', 'pro_until' => null]);
        $profile = $user->profiles()->create(['name' => 'สมาชิก', 'avatar_color' => '#ff2d55']);
        $this->actingAs($user)->withSession(['profile_id' => $profile->id]);
        $this->getJson(route('episode.source', $content->episodes[4]))->assertOk()->assertJsonPath('ready', true);
        $this->get(route('watch', [$content, $content->episodes[4]]))->assertOk()
            ->assertSee('locked-video.example.test', false)->assertDontSee('สมัครฟรี เพื่อดูต่อ');
    }

    public function test_line_signup_returns_to_the_episode_after_selecting_a_profile(): void
    {
        $content = $this->title('vertical');
        $episode = $content->episodes[3];
        $watch = route('watch', [$content, $episode]);
        config(['services.line.client_id' => 'configured-line']);
        $this->get(app(GuestWatch::class)->signupUrl($content, $episode))->assertOk()
            ->assertSee('ดำเนินการต่อด้วย LINE')->assertSessionHas('url.intended', $watch);

        $social = (new SocialiteUser)->map(['id' => 'line-guest', 'name' => 'สมาชิก LINE', 'email' => null, 'avatar' => null]);
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->once()->andReturn($social);
        Socialite::shouldReceive('driver')->with('line')->andReturn($provider);
        $this->get('/auth/line/callback')->assertRedirect($watch)->assertSessionHasNoErrors();

        $user = User::where('provider_id', 'line-guest')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->get($watch)->assertRedirect(route('profiles.index'))->assertSessionHas('url.intended', $watch);
        $this->post(route('profiles.select', $user->profiles()->firstOrFail()))->assertRedirect($watch);
        $this->get($watch)->assertOk()->assertViewHas('start', 3)->assertDontSee('สมัครฟรี เพื่อดูต่อ');
    }

    public function test_email_signup_also_preserves_the_episode_destination(): void
    {
        $episode = $this->title()->episodes[3];
        $this->get(app(GuestWatch::class)->signupUrl($episode->content, $episode))->assertOk();
        $this->post('/register', [
            'name' => 'สมาชิกใหม่', 'email' => 'guest-signup@example.test',
            'password' => 'password-123', 'password_confirmation' => 'password-123', 'accept_terms' => '1',
        ])->assertRedirect(route('watch', [$episode->content, $episode]));
    }

    public function test_signup_rejects_external_or_malformed_return_paths(): void
    {
        foreach (['https://evil.test/watch/1/4', '//evil.test/watch/1/4', '/watch/1/4?next=https://evil.test', ['/watch/1/4']] as $next) {
            $this->get(route('register', ['next' => $next]))->assertOk()->assertSessionMissing('url.intended');
        }
    }

    public function test_a_thai_slug_returns_to_the_same_episode_and_a_foreign_episode_is_rejected(): void
    {
        $content = $this->title();
        $content->update(['slug' => 'ซีรีส์-ทดสอบ']);
        $foreign = $this->title()->episodes[3];
        $this->get(route('register', ['next' => route('watch', [$content, $foreign], false)]))
            ->assertOk()->assertSessionMissing('url.intended');
        $episode = $content->episodes[3];
        $this->get(app(GuestWatch::class)->signupUrl($content, $episode))
            ->assertOk()->assertSessionHas('url.intended', route('watch', [$content, $episode]));
    }

    public function test_zero_quota_hides_a_standalone_movie_source_too(): void
    {
        Setting::write('membership_config', json_encode(['free_episodes' => 0]));
        $content = Content::create([
            'title' => 'หนังทดสอบ', 'slug' => 'standalone-movie', 'type' => 'movie',
            'maturity' => '13+', 'is_published' => true, 'video_url' => 'https://locked-video.example.test/movie.mp4',
        ]);
        $this->get(route('watch', $content))->assertOk()->assertSee('สมัครฟรี เพื่อดูต่อ')
            ->assertDontSee('locked-video.example.test', false);
        $this->get(app(GuestWatch::class)->signupUrl($content))->assertOk()
            ->assertSessionHas('url.intended', route('watch', $content));
    }

    public function test_adult_and_vip_titles_keep_their_existing_access_checks(): void
    {
        foreach (['maturity' => '18+', 'is_vip' => true] as $key => $value) {
            $content = $this->title();
            $content->update([$key => $value]);
            $this->get(route('watch', [$content, $content->episodes[0]]))->assertRedirect(route('login'));
            $this->getJson(route('episode.source', $content->episodes[0]))->assertForbidden()
                ->assertJsonPath('error', $key === 'maturity' ? 'pro_required' : 'vip_required');
        }
    }

    public function test_the_mobile_source_contract_is_unchanged(): void
    {
        $episode = $this->title()->episodes[3];
        $this->getJson('/api/app/episodes/'.$episode->id.'/source')->assertOk()->assertJsonPath('data.ready', true);
    }
}
