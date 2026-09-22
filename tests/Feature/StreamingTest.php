<?php

namespace Tests\Feature;

use App\Http\Controllers\StreamController;
use App\Models\Content;
use App\Models\Genre;
use App\Models\User;
use App\Services\Import\RemoteStream;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StreamingTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserWithProfile(): array
    {
        $user = User::factory()->create();
        $profile = $user->profiles()->create(['name' => 'ทดสอบ', 'avatar_color' => '#ff2d55']);

        return [$user, $profile];
    }

    private function makeContent(): Content
    {
        $genre = Genre::create(['name' => 'ดราม่า', 'slug' => 'drama']);
        $content = Content::create([
            'title' => 'เรื่องทดสอบ', 'slug' => 'test-title', 'type' => 'series',
            'synopsis' => 'ย่อ', 'year' => 2025, 'maturity' => '13+',
            'match_score' => 95, 'rating' => 8.5, 'is_published' => true, 'is_featured' => true,
            'trailer_youtube_id' => 'aqz-KE-bpKQ',
        ]);
        $content->genres()->attach($genre->id, ['is_primary' => true]);
        $season = $content->seasons()->create(['number' => 1, 'title' => 'ซีซั่น 1']);
        $content->episodes()->create(['season_id' => $season->id, 'number' => 1, 'title' => 'ตอนที่ 1', 'video_url' => 'https://example.com/a.mp4']);

        return $content;
    }

    public function test_guest_sees_landing(): void
    {
        $this->get('/')->assertStatus(200)->assertSee('NetWix', false);
    }

    public function test_login_requires_valid_credentials(): void
    {
        [$user] = $this->makeUserWithProfile();

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('profiles.index'));
    }

    public function test_browse_requires_a_selected_profile(): void
    {
        [$user, $profile] = $this->makeUserWithProfile();

        // Logged in but no profile chosen → bounced to the picker.
        $this->actingAs($user)->get(route('browse'))->assertRedirect(route('profiles.index'));

        $this->actingAs($user)->post(route('profiles.select', $profile))->assertRedirect(route('browse'));
    }

    public function test_full_browse_and_title_flow(): void
    {
        [$user, $profile] = $this->makeUserWithProfile();
        $content = $this->makeContent();
        $this->withSession(['profile_id' => $profile->id])->actingAs($user);

        $this->get(route('browse'))->assertStatus(200)->assertSee($content->title, false);
        $this->get(route('title.show', $content))->assertStatus(200);
        $this->get(route('browse.series'))->assertStatus(200);
        $this->get(route('search', ['q' => 'ทดสอบ']))->assertStatus(200)->assertSee($content->title, false);
    }

    public function test_my_list_toggle(): void
    {
        [$user, $profile] = $this->makeUserWithProfile();
        $content = $this->makeContent();
        $this->withSession(['profile_id' => $profile->id])->actingAs($user);

        $this->postJson(route('content.list', $content))->assertJson(['in_list' => true]);
        $this->assertDatabaseHas('my_list_items', ['profile_id' => $profile->id, 'content_id' => $content->id]);
        $this->postJson(route('content.list', $content))->assertJson(['in_list' => false]);
    }

    public function test_admin_area_is_protected(): void
    {
        [$user, $profile] = $this->makeUserWithProfile();
        $this->actingAs($user)->get(route('admin.dashboard'))->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertStatus(200);
    }

    /**
     * Regression: a master playlist's child must be fetched with the SAME Referer as the master.
     *
     * nestedManifestUrl() seals the Referer inside `u` (deliberately — the query string must not
     * leak our upstream), but manifest() used to read it back from `?r=`, which a nested URL never
     * carries. Every child playlist was therefore fetched with no Referer at all. Upstreams that
     * don't check it (hd432) kept working, so the bug shipped; the one that does (getplay-cdn, i.e.
     * every wow-drama title) answered 403, and the junk-body guard then benched the link and
     * recorded a playback failure — the site suspending healthy titles for its own bug.
     */
    public function test_a_master_playlist_child_is_fetched_with_the_sealed_referer(): void
    {
        $episode = $this->makeContent()->episodes()->first();
        $episode->update(['source' => 'wowdrama', 'source_ref' => '106414', 'video_url' => null]);

        $referer = 'https://getplay-cdn.com/embed/e54dbe412d2f561c41418027405bd22d';

        // Seed the resolver cache so the chain walk never touches the network.
        Cache::put("ep_raw:{$episode->id}", [
            'kind' => RemoteStream::KIND_HLS, 'url' => 'https://cdn.test/api/stream/abc/index.m3u8',
            'referer' => $referer, 'source' => 'wowdrama', 'key' => 'the-hidden-shadow', 'ref' => '106414',
            'primary' => true,
        ], now()->addMinutes(10));

        Http::fake([
            'cdn.test/api/stream/abc/index.m3u8' => Http::response(
                "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=2500000,RESOLUTION=1920x1080\nhttps://cdn.test/api/stream/abc/1080p/variant.m3u8\n"
            ),
            // The upstream serves the variant only to a request that carries the Referer — exactly
            // what getplay-cdn does. Without it the player gets a 404 and the episode is unplayable.
            'cdn.test/api/stream/abc/1080p/variant.m3u8' => fn ($request) => $request->hasHeader('Referer', $referer)
                ? Http::response("#EXTM3U\n#EXT-X-TARGETDURATION:5\n#EXTINF:4.800000,\nhttps://cdn.test/seg0.ts\n")
                : Http::response('<html><head><title>403 Forbidden</title></head></html>', 403),
        ]);

        $token = StreamController::token($episode);
        $master = $this->get(route('stream.manifest', $episode).'?t='.$token);
        $master->assertStatus(200);

        $child = collect(preg_split('/\r?\n/', $master->getContent()))
            ->first(fn (string $line) => str_starts_with(trim($line), 'http'));
        $this->assertNotNull($child, 'the master playlist should proxy its variant back through us');

        $this->get(parse_url($child, PHP_URL_PATH).'?'.parse_url($child, PHP_URL_QUERY))
            ->assertStatus(200)
            ->assertSee('#EXTINF', false);

        // The junk-body guard must not have fired: the title is healthy and must stay published.
        $this->assertNull($episode->content->fresh()->suspended_at);
    }

    /** Four clean MPEG-TS packets — HlsSegment passes a body that starts on the 0x47 sync byte as-is. */
    private function tsBytes(): string
    {
        return str_repeat("\x47".str_repeat("\0", 187), 4);
    }

    /**
     * Walk the real path a player takes — manifest → our signed segment URL — and return that URL
     * (path + query) along with the episode. The upstream segment answers with $segment.
     *
     * @return array{0:\App\Models\Episode,1:string}
     */
    private function proxiedSegment(\Closure|\GuzzleHttp\Promise\PromiseInterface $segment): array
    {
        $episode = $this->makeContent()->episodes()->first();
        $episode->update(['source' => 'wowdrama', 'source_ref' => '106414', 'video_url' => null]);

        Cache::put("ep_raw:{$episode->id}", [
            'kind' => RemoteStream::KIND_HLS, 'url' => 'https://cdn.test/api/stream/abc/index.m3u8',
            'referer' => null, 'source' => 'wowdrama', 'key' => 'the-hidden-shadow', 'ref' => '106414',
            'primary' => true,
        ], now()->addMinutes(10));

        Http::fake([
            'cdn.test/api/stream/abc/index.m3u8' => Http::response(
                "#EXTM3U\n#EXT-X-TARGETDURATION:5\n#EXTINF:4.800000,\nhttps://cdn.test/seg0.ts\n#EXT-X-ENDLIST\n"
            ),
            'cdn.test/seg0.ts' => $segment,
        ]);

        $manifest = $this->get(route('stream.manifest', $episode).'?t='.StreamController::token($episode));
        $manifest->assertStatus(200);

        $url = collect(preg_split('/\r?\n/', $manifest->getContent()))
            ->first(fn (string $line) => str_starts_with(trim($line), 'http'));
        $this->assertNotNull($url, 'the media playlist should proxy its segment back through us');

        return [$episode, parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY)];
    }

    /**
     * Cloudflare edge-caches segments for their max-age. It used to be a flat 86400 while the signed
     * URL dies after TTL (6h), so a scraped URL kept playing from the edge for up to 18h after it was
     * supposed to stop working. The cache lifetime must be bounded by the signature's own expiry.
     */
    public function test_a_segment_is_cached_no_longer_than_its_signature_lives(): void
    {
        [, $segment] = $this->proxiedSegment(Http::response($this->tsBytes()));

        $res = $this->get($segment);
        $res->assertStatus(200)->assertHeader('Content-Type', 'video/mp2t');

        $cacheControl = (string) $res->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertMatchesRegularExpression('/max-age=(\d+)/', $cacheControl);
        preg_match('/max-age=(\d+)/', $cacheControl, $m);
        $this->assertLessThanOrEqual(21600, (int) $m[1], 'must not outlive the 6h signature');
        $this->assertGreaterThan(21600 - 120, (int) $m[1], 'a fresh URL should still be cacheable for ~6h');

        // A Set-Cookie makes Cloudflare refuse to cache the response at all.
        $this->assertFalse($res->headers->has('Set-Cookie'));
    }

    /**
     * A hung upstream used to hold the PHP worker for up to ~115s (3 × (8s connect + 30s read)), on a
     * pool shared with every site on the server. Now every attempt comes out of one 20s budget: two
     * attempts that each burn their whole 12s leave no room for a third, and the viewer gets a 502
     * that hls.js retries on its own.
     */
    public function test_a_hung_upstream_segment_gives_up_within_the_budget(): void
    {
        $attempts = 0;
        [, $segment] = $this->proxiedSegment(function () use (&$attempts) {
            $attempts++;
            $this->travel(12)->seconds();   // this attempt used up its entire per-attempt timeout

            return Http::response('', 504);
        });

        $this->get($segment)->assertStatus(502);
        $this->assertSame(2, $attempts, '0s → 12s → 24s: the third attempt would start past the 20s budget');
    }

    /** The budget must not cost the retry that exists for a quick upstream hiccup. */
    public function test_a_quick_upstream_hiccup_is_still_retried(): void
    {
        $attempts = 0;
        [, $segment] = $this->proxiedSegment(function () use (&$attempts) {
            return ++$attempts === 1 ? Http::response('', 503) : Http::response($this->tsBytes());
        });

        $this->get($segment)->assertStatus(200)->assertHeader('Content-Type', 'video/mp2t');
        $this->assertSame(2, $attempts);
    }
}
