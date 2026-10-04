<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Genre;
use App\Models\User;
use App\Services\AppRelease;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every category page carries a "มาใหม่" row: the newest titles of THAT category only, newest first.
 * And the app's update manifest never tells customers what changed in a release.
 */
class NewArrivalsRowTest extends TestCase
{
    use RefreshDatabase;

    private Genre $drama;

    private Genre $anime;

    protected function setUp(): void
    {
        parent::setUp();

        $this->drama = Genre::create(['name' => 'ดราม่า', 'slug' => 'drama']);
        $this->anime = Genre::create(['name' => 'อนิเมะ', 'slug' => 'anime']);

        $user = User::factory()->create();
        $profile = $user->profiles()->create(['name' => 'ทดสอบ', 'avatar_color' => '#ff2d55']);
        $this->withSession(['profile_id' => $profile->id])->actingAs($user);
    }

    private function title(string $name, string $type, bool $anime = false, bool $published = true): Content
    {
        $c = Content::create([
            'title' => $name, 'slug' => Str::slug($name).'-'.Str::random(5), 'type' => $type,
            'synopsis' => 'ย่อ', 'year' => 2025, 'maturity' => '13+',
            'is_published' => $published,
        ]);
        $c->genres()->attach($anime ? $this->anime->id : $this->drama->id, ['is_primary' => true]);

        return $c;
    }

    /** @return int[] ids in the "มาใหม่" row of the page, in display order (null when the row is absent). */
    private function newRow(string $route): ?array
    {
        $rows = $this->get(route($route))->assertOk()->viewData('rows');
        $row = collect($rows)->firstWhere('title', 'มาใหม่');

        return $row ? $row['items']->pluck('id')->all() : null;
    }

    public function test_movies_page_lists_the_newest_movies_first_and_nothing_else(): void
    {
        $old = $this->title('Old Movie', 'movie');
        $this->title('A Series', 'series');
        $this->title('Anime Movie', 'movie', anime: true);
        $this->title('Hidden Movie', 'movie', published: false);
        $new = $this->title('New Movie', 'movie');

        $this->assertSame([$new->id, $old->id], $this->newRow('browse.movies'));
    }

    public function test_series_page_has_its_own_new_row(): void
    {
        $this->title('A Movie', 'movie');
        $s1 = $this->title('Series One', 'series');
        $s2 = $this->title('Series Two', 'series');

        $this->assertSame([$s2->id, $s1->id], $this->newRow('browse.series'));
    }

    public function test_anime_page_lists_only_anime_and_no_vertical_shorts(): void
    {
        $this->title('Drama Series', 'series');
        $this->title('Mis-tagged Short', 'vertical', anime: true);
        $a1 = $this->title('Anime One', 'series', anime: true);
        $a2 = $this->title('Anime Movie', 'movie', anime: true);

        $this->assertSame([$a2->id, $a1->id], $this->newRow('browse.anime'));
    }

    public function test_vertical_page_lists_only_vertical_shorts(): void
    {
        $this->title('A Series', 'series');
        $v1 = $this->title('Short One', 'vertical');
        $v2 = $this->title('Short Two', 'vertical');

        $this->assertSame([$v2->id, $v1->id], $this->newRow('browse.vertical'));
    }

    public function test_home_new_row_keeps_anime_out_like_the_rest_of_home(): void
    {
        $m = $this->title('A Movie', 'movie');
        $this->title('Anime Series', 'series', anime: true);
        $s = $this->title('A Series', 'series');

        $this->assertSame([$s->id, $m->id], $this->newRow('browse'));
    }

    public function test_home_new_row_is_not_flooded_by_vertical_shorts(): void
    {
        $m = $this->title('A Movie', 'movie');
        $this->title('Short One', 'vertical');
        $this->title('Short Two', 'vertical');

        $this->assertSame([$m->id], $this->newRow('browse'));
    }

    public function test_empty_category_shows_no_new_row(): void
    {
        $this->title('A Series', 'series');

        $this->assertNull($this->newRow('browse.movies'));
    }

    public function test_update_manifest_hides_release_notes_but_keeps_the_size(): void
    {
        $this->app->instance(AppRelease::class, new class extends AppRelease
        {
            public function latest(): ?array
            {
                return [
                    'version' => 'v1.7.0+30', 'name' => 'v1.7.0', 'notes' => "แก้การเล่นซีรีส์\n\n- รายละเอียดภายใน",
                    'apk_url' => 'https://example.com/app.apk', 'apk_name' => 'app.apk',
                    'size' => 62240328, 'published_at' => null,
                ];
            }

            // Its APK is on our disk; only then is a release offered (see AppReleaseMirrorTest).
            public function isMirrored(array $rel): bool
            {
                return true;
            }
        });

        $this->getJson('/api/app/version')->assertOk()
            ->assertJsonPath('data.version', '1.7.0')
            ->assertJsonPath('data.notes', '')
            ->assertJsonPath('data.size', 62240328);
    }
}
