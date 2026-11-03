<?php

namespace Tests\Feature;

use App\Models\AppToken;
use App\Models\Content;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Like / comment / share on the web players, and the same rows seen from the app. */
class PlayerSocialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function title(string $type = 'series'): Content
    {
        $content = Content::create([
            'title' => 'เรื่องโซเชียล', 'slug' => uniqid('social-'), 'type' => $type,
            'maturity' => '13+', 'is_published' => true,
        ]);
        foreach (range(1, 2) as $n) {
            $content->episodes()->create([
                'number' => $n, 'title' => 'ตอนที่ '.$n,
                'video_url' => 'https://video.example.test/ep'.$n.'.mp4',
            ]);
        }

        return $content->load('episodes');
    }

    private function member(): array
    {
        $user = User::factory()->create();
        $profile = $user->profiles()->create(['name' => 'ผู้ชม', 'avatar_color' => '#ff2d55']);

        return [$user, $profile];
    }

    public function test_both_players_render_the_rail_and_the_comments_drawer(): void
    {
        foreach (['series', 'vertical'] as $type) {
            $content = $this->title($type);
            $this->get(route('watch', [$content, $content->episodes[0]]))->assertOk()
                ->assertSee('soToggleLike()', false)
                ->assertSee('soOpenComments()', false)
                ->assertSee('soShareTitle()', false)
                ->assertSee('window.nxSocial', false)
                ->assertSee('...nxSocial(cfg.social)', false);
        }
    }

    public function test_a_member_likes_from_the_player_and_gets_the_new_count(): void
    {
        [$user, $profile] = $this->member();
        $content = $this->title();

        $this->actingAs($user)->withSession(['profile_id' => $profile->id]);
        $this->postJson(route('content.like', $content))->assertOk()
            ->assertJson(['liked' => true, 'count' => 1]);
        $this->postJson(route('content.like', $content))->assertOk()
            ->assertJson(['liked' => false, 'count' => 0]);
    }

    public function test_a_web_comment_shows_in_the_app_and_an_app_comment_shows_on_the_web(): void
    {
        [$user, $profile] = $this->member();
        $content = $this->title();

        $this->actingAs($user)->withSession(['profile_id' => $profile->id]);
        $this->postJson(route('content.comment', $content), ['body' => 'สนุกมาก'])->assertOk()
            ->assertJsonPath('count', 1);

        $this->getJson('/api/app/content/'.$content->id.'/comments')->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.items.0.text', 'สนุกมาก')
            ->assertJsonPath('data.items.0.initial', $profile->initial)
            ->assertJsonStructure(['data' => ['items' => [['id', 'author', 'avatar_color', 'initial', 'text', 'created_at', 'ago']]]]);

        $this->withToken(AppToken::issue($user, 'phone'))
            ->postJson('/api/app/content/'.$content->id.'/comments', ['body' => 'จากแอป'])->assertOk()
            ->assertJsonPath('data.count', 2);

        // The player seeds its drawer from the same table (the page itself JSON-escapes the Thai).
        $seed = \App\Support\PlayerSocial::seed($content, $profile);
        $this->assertSame(2, $seed['commentCount']);
        $this->assertSame('จากแอป', $seed['comments'][0]['text']);
    }

    public function test_guests_get_counts_from_the_state_endpoint_and_members_get_their_flags(): void
    {
        [$user, $profile] = $this->member();
        $content = $this->title();
        $profile->likes()->attach($content->id);
        $content->comments()->create(['profile_id' => $profile->id, 'body' => 'ดี']);

        $this->getJson('/api/app/content/'.$content->id.'/state')->assertOk()
            ->assertJsonPath('data.liked', false)
            ->assertJsonPath('data.in_list', false)
            ->assertJsonPath('data.my_rating', null)
            ->assertJsonPath('data.likes_count', 1)
            ->assertJsonPath('data.comments_count', 1);

        $this->withToken(AppToken::issue($user, 'phone'))
            ->getJson('/api/app/content/'.$content->id.'/state')->assertOk()
            ->assertJsonPath('data.liked', true);
    }

    public function test_guests_cannot_like_or_comment(): void
    {
        $content = $this->title();

        $this->postJson(route('content.like', $content))->assertUnauthorized();
        $this->postJson(route('content.comment', $content), ['body' => 'x'])->assertUnauthorized();
        $this->postJson('/api/app/content/'.$content->id.'/like')->assertUnauthorized();
        $this->postJson('/api/app/content/'.$content->id.'/comments', ['body' => 'x'])->assertUnauthorized();
        $this->assertSame(0, $content->comments()->count());
    }

    public function test_app_comment_posting_is_rate_limited(): void
    {
        [$user] = $this->member();
        $content = $this->title();
        $token = AppToken::issue($user, 'phone');

        // 30 a minute per member (the very first call can land on the pre-auth key, so allow one slack).
        $codes = [];
        for ($i = 0; $i < 32; $i++) {
            $codes[] = $this->withToken($token)
                ->postJson('/api/app/content/'.$content->id.'/comments', ['body' => 'ข้อความ '.$i])->status();
        }
        $this->assertGreaterThanOrEqual(30, count(array_filter($codes, fn ($c) => $c === 200)));
        $this->assertSame(429, end($codes));
    }

    public function test_a_profile_colour_can_never_carry_css_into_comment_lists(): void
    {
        [$user, $profile] = $this->member();
        $content = $this->title();
        $content->comments()->create(['profile_id' => $profile->id, 'body' => 'สวัสดี']);

        // An old row saved before validation tightened: the model refuses to hand it out.
        $profile->forceFill(['avatar_color' => 'red;position:fixed;inset:0'])->saveQuietly();
        $this->getJson('/api/app/content/'.$content->id.'/comments')->assertOk()
            ->assertJsonPath('data.items.0.avatar_color', null);
        $this->assertSame('#8b2ff0', \App\Support\PlayerSocial::seed($content, null)['comments'][0]['color']);
        $this->get(route('watch', [$content, $content->episodes[0]]))->assertOk()
            ->assertDontSee('position:fixed', false);

        // And neither the web nor the app can save one any more.
        $this->actingAs($user)->withSession(['profile_id' => $profile->id]);
        $this->post(route('profiles.update', $profile), ['name' => 'ผู้ชม', 'avatar_color' => 'red;position:fixed'])
            ->assertSessionHasErrors('avatar_color');
        $this->withToken(AppToken::issue($user, 'phone'))
            ->postJson('/api/app/profiles/'.$profile->id, ['name' => 'ผู้ชม', 'avatar_color' => 'url(//x.test/p)'])
            ->assertUnprocessable();
        $this->withToken(AppToken::issue($user, 'phone'))
            ->postJson('/api/app/profiles/'.$profile->id, ['name' => 'ผู้ชม', 'avatar_color' => '#46d369'])
            ->assertOk();
        $this->assertSame('#46d369', $profile->fresh()->avatar_color);
    }

    public function test_comment_text_is_escaped_on_the_player_page(): void
    {
        [$user, $profile] = $this->member();
        $content = $this->title();
        $content->comments()->create(['profile_id' => $profile->id, 'body' => '<script>alert(1)</script>']);

        $this->get(route('watch', [$content, $content->episodes[0]]))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    }
}
