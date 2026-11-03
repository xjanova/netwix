<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\ContentMirror;
use App\Models\SourceTitle;
use App\Models\User;
use App\Services\Import\Contracts\MediaSource;
use App\Services\Import\RemoteSeries;
use App\Services\Import\RemoteStream;
use App\Services\Import\SourceRegistry;
use App\Support\ReplacementFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The suspended list must offer a way back for a dead title: the same title on another synced site
 * (or a fresh upload on its own), tried in the admin player and wired in with one click.
 */
class SuspendedReplacementTest extends TestCase
{
    use RefreshDatabase;

    private function suspended(array $attrs = []): Content
    {
        $content = Content::create($attrs + [
            'title' => 'Kamen Rider Geats มาสค์ไรเดอร์กีทส์', 'slug' => 'geats', 'type' => 'series',
            'year' => 2022, 'source' => 'animeruka', 'source_key' => 'anime/kamen-rider-geats',
            'is_published' => false, 'suspended_at' => now(), 'suspend_reason' => 'all_links_failed',
        ]);
        $season = $content->seasons()->create(['number' => 1, 'title' => 'ซีซั่น 1']);
        foreach ([1, 2] as $n) {
            $content->episodes()->create(['season_id' => $season->id, 'number' => $n, 'title' => "ตอนที่ {$n}",
                'source' => 'animeruka', 'source_ref' => "ep/geats-{$n}"]);
        }

        return $content;
    }

    private function sourceTitle(string $source, string $key, string $title, ?int $year = 2022): SourceTitle
    {
        return SourceTitle::create(['source' => $source, 'source_key' => $key, 'title' => $title, 'clean_title' => $title, 'year' => $year]);
    }

    public function test_candidates_rank_the_same_title_on_other_sites_and_skip_the_dead_row_itself(): void
    {
        $content = $this->suspended();
        $this->sourceTitle('animeruka', 'anime/kamen-rider-geats', 'Kamen Rider Geats มาสค์ไรเดอร์กีทส์');   // the dead link itself
        $same = $this->sourceTitle('anifume', 'geats-th', 'Kamen Rider Geats มาสค์ไรเดอร์กีทส์ ซับไทย');
        $reupload = $this->sourceTitle('animeruka', 'anime/kamen-rider-geats-2', 'Kamen Rider Geats มาสค์ไรเดอร์กีทส์');
        $this->sourceTitle('anime108', 'revice', 'Kamen Rider Revice');
        $remake = $this->sourceTitle('anime108', 'geats-1999', 'Kamen Rider Geats มาสค์ไรเดอร์กีทส์', 1999);

        $admin = User::factory()->create(['role' => 'admin']);
        $results = $this->actingAs($admin)->getJson(route('admin.suspended.candidates', $content))->assertOk()->json('results');

        $ids = array_column($results, 'id');
        $this->assertSame([$same->id, $reupload->id], array_slice($ids, 0, 2), 'exact matches first');
        $this->assertTrue($results[1]['same_site']);
        $this->assertNotContains(SourceTitle::where('source_key', 'anime/kamen-rider-geats')->value('id'), $ids);
        $remakeRow = collect($results)->firstWhere('id', $remake->id);
        $this->assertNotNull($remakeRow);
        $this->assertLessThan(ReplacementFinder::STRONG, $remakeRow['score'], 'a different year is never a confident match');
    }

    public function test_a_candidate_that_does_not_play_asks_before_it_is_used(): void
    {
        Http::fake(['*' => Http::response('gone', 404)]);
        $content = $this->suspended();
        $st = $this->sourceTitle('anifume', 'geats-th', 'Kamen Rider Geats');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('admin.suspended.attach', $content), ['source_title' => $st->id])
            ->assertOk()->assertJson(['ok' => false, 'need_confirm' => true]);
        $this->assertSame(0, ContentMirror::count());
        $this->assertNotNull($content->fresh()->suspended_at);
    }

    public function test_using_a_candidate_makes_it_every_episodes_first_link_and_republishes(): void
    {
        $content = $this->suspended();
        $st = $this->sourceTitle('anifume', 'geats-th', 'Kamen Rider Geats');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('admin.suspended.attach', $content), ['source_title' => $st->id, 'force' => true])
            ->assertOk()->assertJson(['ok' => true]);
        // Picking it again keeps one row.
        $this->actingAs($admin)->postJson(route('admin.suspended.attach', $content), ['source_title' => $st->id, 'force' => true])->assertOk();

        $mirror = ContentMirror::sole();
        $this->assertSame(['anifume', 'geats-th', true], [$mirror->source, $mirror->source_key, $mirror->is_manual]);
        $this->assertSame([$mirror->id], $content->episodes()->pluck('active_mirror_id')->unique()->values()->all());
        $fresh = $content->fresh();
        $this->assertNull($fresh->suspended_at);
        $this->assertTrue((bool) $fresh->is_published);
    }

    public function test_the_page_plays_through_the_admin_preview_not_the_public_watch_page(): void
    {
        $content = $this->suspended();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.suspended.index'))->assertOk()
            // @js() escapes the slashes
            ->assertSee(str_replace('/', '\/', route('admin.preview.episode', $content->episodes()->orderBy('number')->first())), false)
            ->assertDontSee(route('watch', $content), false);
    }

    /**
     * Swap in sources that play episodes 1..$upTo of any key (as MP4, so no playlist fetch), and
     * record every ref asked for.
     *
     * @param  array<string,int>  $upTo  source id → last episode it has
     */
    private function fakeSources(array $upTo): \ArrayObject
    {
        $calls = new \ArrayObject;
        $fakes = [];
        foreach ($upTo as $id => $last) {
            $fakes[$id] = new class($id, $last, $calls) implements MediaSource
            {
                public function __construct(private string $sid, private int $last, private \ArrayObject $calls) {}

                public function id(): string { return $this->sid; }

                public function displayName(): string { return strtoupper($this->sid); }

                public function defaultContentType(): string { return 'series'; }

                public function isProgressive(): bool { return true; }

                public function umbrellaGenre(): ?string { return null; }

                public function fetchCatalog(callable $onBatch, int $maxPages = 100): int { return 0; }

                public function fetchEpisodes(RemoteSeries $series): array { return []; }

                public function resolveByRef(string $sourceKey, string $sourceRef, array $extra = []): ?RemoteStream
                {
                    $this->calls[] = "{$this->sid}:{$sourceKey}:{$sourceRef}";

                    return (int) $sourceRef <= $this->last ? new RemoteStream(RemoteStream::KIND_MP4, "https://{$this->sid}.test/{$sourceRef}.mp4") : null;
                }
            };
        }
        $this->app->instance(SourceRegistry::class, new class($fakes) extends SourceRegistry
        {
            public function __construct(private array $fakes)
            {
                parent::__construct();
            }

            public function get(string $id): ?MediaSource
            {
                return $this->fakes[$id] ?? parent::get($id);
            }
        });

        return $calls;
    }

    public function test_the_bot_skips_short_copies_remembers_failed_ones_and_checks_the_last_episode(): void
    {
        $content = $this->suspended();   // episodes 1 and 2
        $short = $this->sourceTitle('anifume', 'geats-short', 'Kamen Rider Geats มาสค์ไรเดอร์กีทส์');
        $short->update(['episodes_count' => 1]);
        $stops = $this->sourceTitle('anime108', 'geats-stops', 'Kamen Rider Geats มาสค์ไรเดอร์กีทส์');
        $stops->update(['view_count' => 10]);   // tried first among equals
        $this->sourceTitle('wowdrama', 'geats-full', 'Kamen Rider Geats มาสค์ไรเดอร์กีทส์')->update(['view_count' => 5]);
        $calls = $this->fakeSources(['anifume' => 2, 'anime108' => 1, 'wowdrama' => 2]);

        $copy = app(ReplacementFinder::class)->rescue($content);

        $this->assertSame(['kind' => 'mirror', 'source' => 'wowdrama'], ['kind' => $copy['kind'], 'source' => $copy['source']]);
        $this->assertNotContains('anifume:geats-short:1', (array) $calls, 'a copy the site lists with fewer episodes is never tried');
        $this->assertContains('anime108:geats-stops:2', (array) $calls, 'a copy is asked for our LAST episode too');
        $this->assertTrue((bool) $content->fresh()->is_published);

        $rows = collect(app(ReplacementFinder::class)->candidates($content->fresh()))->keyBy('key');
        $this->assertFalse($rows['geats-short']['covers']);
        $this->assertTrue($rows['geats-stops']['failed'], 'the copy that stopped half-way is remembered');
        $this->assertFalse($rows['geats-full']['failed']);

        // The next run does not ask the remembered copy again.
        $content->forceFill(['is_published' => false, 'suspended_at' => now()])->save();
        $calls->exchangeArray([]);
        app(ReplacementFinder::class)->rescue($content->fresh());
        $this->assertEmpty(array_filter((array) $calls, fn ($c) => str_starts_with($c, 'anime108:')));
    }

    public function test_a_short_copy_only_leads_the_episodes_it_has(): void
    {
        $content = $this->suspended();
        $st = $this->sourceTitle('anifume', 'geats-th', 'Kamen Rider Geats');
        $st->update(['episodes_count' => 1]);

        app(ReplacementFinder::class)->attach($content, 'anifume', 'geats-th');

        $active = $content->episodes()->orderBy('number')->pluck('active_mirror_id', 'number')->all();
        $this->assertNotNull($active[1]);
        $this->assertNull($active[2], 'episode 2 keeps starting at its own link');
    }

    public function test_a_dead_entry_whose_re_upload_is_already_live_is_retired_and_redirects(): void
    {
        $dead = $this->suspended(['source' => 'rongyok', 'source_key' => '8453', 'title' => 'บังเอิญเจอบังเอิญรัก', 'slug' => 'old', 'type' => 'series']);
        $live = Content::create(['title' => 'บังเอิญเจอบังเอิญรัก', 'slug' => 'new', 'type' => 'series', 'year' => 2022,
            'source' => 'rongyok', 'source_key' => '8460', 'is_published' => true]);
        $season = $live->seasons()->create(['number' => 1, 'title' => 'ซีซั่น 1']);
        $liveEp2 = $live->episodes()->create(['season_id' => $season->id, 'number' => 2, 'title' => 'ตอนที่ 2']);
        $this->sourceTitle('rongyok', '8460', 'บังเอิญเจอบังเอิญรัก');
        $calls = $this->fakeSources([]);

        $result = app(ReplacementFinder::class)->rescue($dead);

        $this->assertSame(['kind' => 'duplicate', 'duplicate_of' => $live->id], ['kind' => $result['kind'], 'duplicate_of' => $result['duplicate_of']]);
        $this->assertSame(0, ContentMirror::count(), 'no second copy is published');
        $fresh = $dead->fresh();
        $this->assertFalse((bool) $fresh->is_published);
        $this->assertNull($fresh->suspended_at, 'it leaves the suspended list');
        $this->assertSame('duplicate:'.$live->id, $fresh->suspend_reason);

        $this->get(route('title.show', $fresh))->assertRedirect(route('title.show', $live))->assertStatus(301);
        $deadEp2 = $dead->episodes()->where('number', 2)->first();
        $this->get(route('watch', [$fresh, $deadEp2]))->assertRedirect(route('watch', [$live, $liveEp2]));
    }

    public function test_the_admin_can_send_viewers_to_the_live_copy(): void
    {
        $dead = $this->suspended(['source' => 'rongyok', 'source_key' => '8453', 'title' => 'บังเอิญเจอบังเอิญรัก', 'slug' => 'old']);
        $live = Content::create(['title' => 'บังเอิญเจอบังเอิญรัก', 'slug' => 'new', 'type' => 'series',
            'source' => 'rongyok', 'source_key' => '8460', 'is_published' => true]);
        $st = $this->sourceTitle('rongyok', '8460', 'บังเอิญเจอบังเอิญรัก');
        $admin = User::factory()->create(['role' => 'admin']);

        $row = collect($this->actingAs($admin)->getJson(route('admin.suspended.candidates', $dead))->json('results'))->firstWhere('id', $st->id);
        $this->assertSame(['id' => $live->id, 'published' => true], ['id' => $row['imported_as']['id'], 'published' => $row['imported_as']['published']]);

        $this->actingAs($admin)->postJson(route('admin.suspended.attach', $dead), ['source_title' => $st->id, 'merge' => true])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertSame('duplicate:'.$live->id, $dead->fresh()->suspend_reason);
    }

    public function test_only_admins_reach_the_replacement_endpoints(): void
    {
        $content = $this->suspended();
        $user = User::factory()->create();

        $this->actingAs($user)->getJson(route('admin.suspended.candidates', $content))->assertForbidden();
        $this->actingAs($user)->postJson(route('admin.suspended.attach', $content), ['source_title' => 1])->assertForbidden();
    }
}
