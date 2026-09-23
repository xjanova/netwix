<?php

namespace Tests\Feature;

use App\Jobs\GenerateEpisodeThumb;
use App\Models\AppToken;
use App\Models\Content;
use App\Models\Episode;
use App\Models\Setting;
use App\Models\User;
use App\Support\EdgeSecret;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The fixes from the 2026-09-23 audit, each pinned by the scenario that made it a finding.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function content(string $title = 'เรื่องทดสอบ', array $episode = []): Content
    {
        $content = Content::create([
            'title' => $title, 'slug' => 'c-'.uniqid(), 'type' => 'series', 'synopsis' => 'ย่อ',
            'year' => 2025, 'maturity' => '13+', 'is_published' => true,
        ]);
        $content->episodes()->create($episode + ['number' => 1, 'title' => 'ตอนที่ 1', 'video_url' => 'https://example.com/a.mp4']);

        return $content;
    }

    /** A request that arrived through Cloudflare from a real visitor (not our own server). */
    private function asVisitor(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->withHeaders(['CF-Ray' => 'test-ray']);
    }

    // ── admin confirm() dialogs ───────────────────────────────────────────────

    /**
     * Blade's `{{ }}` turns `'` into `&#039;`, which the browser decodes back to `'` before running an
     * onsubmit handler. A scraped title with a quote therefore ran as script in the admin's browser, and
     * a plain apostrophe broke the handler — the delete went through with no confirmation at all.
     */
    public function test_a_title_with_a_quote_cannot_break_out_of_the_delete_confirm(): void
    {
        $this->content("Grey's Anatomy');alert(1);//");
        $admin = User::factory()->create(['role' => 'admin']);

        $html = $this->actingAs($admin)->get(route('admin.contents.index'))->assertOk()->getContent();

        // @js escapes the quote as \u0027 (and Thai as \uXXXX): the handler sees one intact string.
        $this->assertStringContainsString("Grey\\u0027s Anatomy\\u0027);alert(1);\\/\\/?')", $html);
        $this->assertStringNotContainsString("confirm('ลบ Grey&#039;s", $html);
    }

    // ── suspended accounts ────────────────────────────────────────────────────

    public function test_a_suspended_members_app_token_stops_working(): void
    {
        $user = User::factory()->create();
        $plain = AppToken::issue($user, 'phone');

        $this->withToken($plain)->getJson('/api/app/auth/me')->assertOk();

        $user->update(['is_active' => false]);
        $this->withToken($plain)->getJson('/api/app/auth/me')->assertStatus(401);
    }

    public function test_suspending_from_the_admin_panel_revokes_the_app_and_remember_me(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $member = User::factory()->create(['remember_token' => 'old-remember-token']);
        AppToken::issue($member, 'phone');

        $this->actingAs($admin)->post(route('admin.users.active', $member), ['active' => 0])->assertRedirect();

        $this->assertSame(0, AppToken::where('user_id', $member->id)->count());
        $this->assertNotSame('old-remember-token', $member->fresh()->remember_token);
    }

    public function test_a_suspended_session_ends_on_any_page_not_just_the_player(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        // /profiles has no EnsureProfileSelected — it used to stay open to a suspended account.
        $this->actingAs($user)->get(route('profiles.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_suspended_admin_loses_the_admin_area(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => false]);

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // ── playback reports ──────────────────────────────────────────────────────

    /**
     * Five "it didn't play" posts from strangers used to unpublish any title. The failure tally lives in
     * Redis, which the test environment does not run, so these watch the tally itself.
     */
    public function test_reports_from_viewers_never_served_the_title_are_not_counted(): void
    {
        Redis::spy();
        $content = $this->content();

        foreach (['198.51.100.1', '198.51.100.2', '198.51.100.3', '198.51.100.4', '198.51.100.5'] as $ip) {
            $this->asVisitor($ip)->postJson(route('playback.report', $content), ['ok' => false])->assertOk();
        }

        Redis::shouldNotHaveReceived('sadd');
        $this->assertTrue($content->fresh()->is_published);
    }

    /** Served viewers still count — the safety net for genuinely dead titles keeps working. */
    public function test_reports_from_served_viewers_still_suspend_a_dead_title(): void
    {
        Redis::spy();
        Redis::shouldReceive('scard')->andReturn(1, 2, 3, 4, 5);   // one more distinct viewer per report
        $content = $this->content();

        foreach (['198.51.100.11', '198.51.100.12', '198.51.100.13', '198.51.100.14', '198.51.100.15'] as $ip) {
            // The watch page hands a stored episode's URL straight to the player — that is the serving.
            $this->asVisitor($ip)->get(route('watch', $content))->assertOk();
            $this->asVisitor($ip)->postJson(route('playback.report', $content), ['ok' => false])->assertOk();
        }

        Redis::shouldHaveReceived('sadd')->with("netwix:playfail:{$content->id}", '198.51.100.11');
        $this->assertFalse($content->fresh()->is_published);
    }

    /** One IPv6 household rotates through its /64 — it is one viewer, not five. */
    public function test_one_ipv6_household_is_one_viewer(): void
    {
        Redis::spy();
        $content = $this->content();

        foreach (range(1, 5) as $i) {
            $ip = "2001:db8:1:2::{$i}";
            $this->asVisitor($ip)->get(route('watch', $content))->assertOk();
            $this->asVisitor($ip)->postJson(route('playback.report', $content), ['ok' => false])->assertOk();
        }

        Redis::shouldHaveReceived('sadd')->with("netwix:playfail:{$content->id}", '2001:db8:1:2::/64')->times(5);
        Redis::shouldNotHaveReceived('sadd', ["netwix:playfail:{$content->id}", '2001:db8:1:2::1']);
    }

    // ── episode covers ────────────────────────────────────────────────────────

    /** A member's uploaded frame used to become the episode's cover for everyone. */
    public function test_a_members_uploaded_frame_is_never_stored_as_a_cover(): void
    {
        Queue::fake();
        Storage::fake('public');
        $episode = $this->content(episode: ['video_url' => null, 'source' => 'wowdrama', 'source_ref' => '1'])->episodes()->first();
        $member = User::factory()->create();
        $profile = $member->profiles()->create(['name' => 'ทดสอบ', 'avatar_color' => '#ff2d55']);

        $this->actingAs($member)->withSession(['profile_id' => $profile->id])
            ->postJson(route('episode.thumb', $episode), ['image' => 'data:image/jpeg;base64,'.base64_encode('planted')])
            ->assertOk();

        $this->assertNull($episode->fresh()->thumbnail_path);
        Queue::assertPushed(GenerateEpisodeThumb::class, 1);   // the server takes the frame itself
    }

    // ── headers, CORS, uploads ────────────────────────────────────────────────

    public function test_pages_carry_the_browser_security_headers(): void
    {
        $res = $this->get('/')->assertOk();

        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $res->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $res->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString("frame-ancestors 'self'", (string) $res->headers->get('Content-Security-Policy'));
    }

    /** Laravel's default answered every api/* call with `Access-Control-Allow-Origin: *`. */
    public function test_another_website_cannot_read_our_api_from_its_visitors_browsers(): void
    {
        // With a single allowed origin the CORS layer always names OURS; the browser then refuses to
        // hand the response to any other page. What must never come back is `*` or the asker's origin.
        $allowed = $this->getJson('/api/app/genres', ['Origin' => 'https://pirate.test'])
            ->headers->get('Access-Control-Allow-Origin');
        $this->assertNotContains($allowed, ['*', 'https://pirate.test']);

        $own = rtrim((string) config('app.url'), '/');
        $this->getJson('/api/app/genres', ['Origin' => $own])
            ->assertHeader('Access-Control-Allow-Origin', $own);
    }

    /** `mimes:` checked the bytes, but the file kept the uploader's name — x.html was served as a page. */
    public function test_a_preroll_video_keeps_no_extension_of_the_uploaders_choosing(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);
        // A REAL uploaded file: Laravel's fake() reports a MIME type from the NAME, which is exactly the
        // thing this test must not trust. The bytes are an MP4 header; the name says HTML.
        $path = tempnam(sys_get_temp_dir(), 'nxmp4');
        file_put_contents($path, "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64));

        $this->actingAs($admin)->post(route('admin.ads.store'), [
            'name' => 'ทดสอบ', 'media_type' => 'video',
            'media_file' => new UploadedFile($path, 'evil.html', null, null, true),
            'skip_after' => 5, 'image_seconds' => 5, 'target' => 'all', 'frequency' => 'always',
        ])->assertSessionHasNoErrors();

        $stored = Storage::disk('public')->allFiles('media/ads');
        $this->assertCount(1, $stored);
        $this->assertStringEndsWith('.mp4', $stored[0]);
    }

    // ── the Cloudflare edge secret ────────────────────────────────────────────

    /** Until a secret exists the gate behaves exactly as before: CF headers in, nothing else. */
    public function test_without_a_secret_the_origin_gate_is_unchanged(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->get('/')->assertForbidden();
        $this->asVisitor('203.0.113.7')->get('/')->assertOk();
    }

    /** Observing: the old check still decides while the admin watches Cloudflare's header arrive. */
    public function test_an_unenforced_secret_only_observes(): void
    {
        $secret = EdgeSecret::generate();

        $this->asVisitor('203.0.113.8')->get('/')->assertOk();   // forged CF-Ray, no secret: still let in
        $this->assertSame(1, EdgeSecret::missingThisHour());

        $this->asVisitor('203.0.113.8')->withHeaders([EdgeSecret::HEADER => $secret])->get('/')->assertOk();
        $this->assertTrue(EdgeSecret::seenRecently());
    }

    /** Enforced: a forged CF-Ray no longer opens the door; only Cloudflare's secret does. */
    public function test_an_enforced_secret_refuses_a_forged_cloudflare_header(): void
    {
        $secret = EdgeSecret::generate();
        Setting::write('cf_edge_enforce', '1');

        $this->asVisitor('203.0.113.9')->get('/')->assertForbidden();
        $this->asVisitor('203.0.113.9')->withHeaders([EdgeSecret::HEADER => 'guess'])->get('/')->assertForbidden();
        $this->asVisitor('203.0.113.9')->withHeaders([EdgeSecret::HEADER => $secret])->get('/')->assertOk();
        // Our own box and certificate renewal are never gated.
        $this->get('/')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/.well-known/acme-challenge/x')->assertNotFound();
    }

    /** The admin page shows the secret exactly once — on the response right after it is made. */
    public function test_the_security_page_shows_a_new_secret_once(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.security.index'))->assertOk()->assertSee('รหัสลับจาก Cloudflare');

        $this->actingAs($admin)->from(route('admin.security.index'))
            ->post(route('admin.security.edge'), ['action' => 'generate'])->assertRedirect(route('admin.security.index'));
        $secret = EdgeSecret::secret();

        $this->actingAs($admin)->get(route('admin.security.index'))->assertOk()->assertSee($secret, false);
        $this->actingAs($admin)->get(route('admin.security.index'))->assertOk()->assertDontSee($secret, false);
    }

    /** Enforcing before Cloudflare sends the header would refuse every visitor — so it is refused. */
    public function test_enforcement_waits_until_cloudflare_is_seen_sending_the_secret(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.security.edge'), ['action' => 'generate'])
            ->assertSessionHas('edge_secret');
        $secret = EdgeSecret::secret();
        $this->assertNotSame('', $secret);
        $this->assertNotSame($secret, Setting::query()->where('key', 'cf_edge_secret')->value('value'), 'stored encrypted');

        $this->actingAs($admin)->post(route('admin.security.edge'), ['action' => 'enforce'])->assertSessionHasErrors('edge');
        $this->assertFalse(EdgeSecret::enforcing());

        $this->asVisitor('203.0.113.10')->withHeaders([EdgeSecret::HEADER => $secret])->get(route('terms'))->assertOk();
        $this->actingAs($admin)->post(route('admin.security.edge'), ['action' => 'enforce'])->assertSessionHasNoErrors();
        $this->assertTrue(EdgeSecret::enforcing());
    }
}
