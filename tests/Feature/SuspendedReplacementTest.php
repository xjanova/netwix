<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\ContentMirror;
use App\Models\SourceTitle;
use App\Models\User;
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

    public function test_only_admins_reach_the_replacement_endpoints(): void
    {
        $content = $this->suspended();
        $user = User::factory()->create();

        $this->actingAs($user)->getJson(route('admin.suspended.candidates', $content))->assertForbidden();
        $this->actingAs($user)->postJson(route('admin.suspended.attach', $content), ['source_title' => 1])->assertForbidden();
    }
}
