<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Episode;
use App\Models\User;
use App\Services\MediaMirror;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ManualEpisodeLinkTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function title(string $slug = 'v1', ?string $source = 'rongyok'): Content
    {
        return Content::create([
            'title' => 'เรื่อง '.$slug, 'slug' => $slug, 'source' => $source, 'source_key' => $source ? '8207' : null,
            'type' => 'vertical', 'is_published' => true, 'maturity' => '15+',
        ]);
    }

    private function episode(Content $c, int $n, array $extra = []): Episode
    {
        return $c->episodes()->create(['number' => $n, 'title' => "ตอนที่ {$n}", 'source' => $c->source, 'source_ref' => (string) $n] + $extra);
    }

    public function test_admin_sets_a_link_and_the_player_gets_it_directly(): void
    {
        Cache::flush();
        $c = $this->title();
        $ep = $this->episode($c, 1, ['mirror_attempts' => Episode::MIRROR_MAX_ATTEMPTS, 'mirror_requested_at' => now()]);
        $url = 'https://cdn.example.com/v1/ep1/index.m3u8';

        $this->actingAs($this->admin())
            ->post(route('admin.contents.episodes.link', [$c, $ep]), ['video_url' => $url])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $ep->refresh();
        $this->assertSame($url, $ep->video_url);
        $this->assertTrue($ep->is_manual_link);
        // A working link clears the "gave up mirroring" state the app shows as unavailable.
        $this->assertFalse($ep->is_unavailable);
        $this->assertNull($ep->mirror_requested_at);

        $user = User::factory()->create();
        $profile = $user->profiles()->create(['name' => 'ดู', 'avatar_color' => '#ff2d55']);
        $this->withSession(['profile_id' => $profile->id])->actingAs($user)
            ->getJson(route('episode.source', $ep))
            ->assertOk()
            ->assertJson(['ready' => true, 'kind' => 'hls', 'url' => $url]);
    }

    public function test_only_https_urls_are_accepted(): void
    {
        $c = $this->title();
        $ep = $this->episode($c, 1);
        $admin = $this->admin();

        foreach (['http://cdn.example.com/a.mp4', 'javascript:alert(1)', 'not a link', ''] as $bad) {
            $this->actingAs($admin)
                ->post(route('admin.contents.episodes.link', [$c, $ep]), ['video_url' => $bad])
                ->assertSessionHasErrors('video_url');
        }

        $this->assertNull($ep->fresh()->video_url);
    }

    public function test_non_admin_cannot_set_a_link(): void
    {
        $c = $this->title();
        $ep = $this->episode($c, 1);

        $this->actingAs(User::factory()->create())
            ->post(route('admin.contents.episodes.link', [$c, $ep]), ['video_url' => 'https://cdn.example.com/a.mp4'])
            ->assertForbidden();

        $this->assertNull($ep->fresh()->video_url);
    }

    public function test_episode_of_another_title_is_404(): void
    {
        $a = $this->title('a');
        $b = $this->title('b', 'wowdrama');
        $ep = $this->episode($b, 1);

        $this->actingAs($this->admin())
            ->post(route('admin.contents.episodes.link', [$a, $ep]), ['video_url' => 'https://cdn.example.com/a.mp4'])
            ->assertNotFound();
    }

    public function test_a_mirrored_episode_must_have_its_file_deleted_first(): void
    {
        $c = $this->title();
        $stored = 'https://netwix.online/storage/media/rongyok/8207/1.mp4';
        $ep = $this->episode($c, 1, ['video_url' => $stored, 'mirrored_at' => now(), 'mirror_trigger' => 'admin']);

        $this->actingAs($this->admin())
            ->post(route('admin.contents.episodes.link', [$c, $ep]), ['video_url' => 'https://cdn.example.com/a.mp4'])
            ->assertSessionHasErrors('video_url');

        $this->assertSame($stored, $ep->fresh()->video_url);

        // …and "ลบลิงก์" never touches a stored file's URL either.
        $this->actingAs($this->admin())
            ->delete(route('admin.contents.episodes.link.clear', [$c, $ep]))
            ->assertSessionHasErrors('video_url');
        $this->assertSame($stored, $ep->fresh()->video_url);
    }

    public function test_clearing_a_link_hands_the_episode_back_to_its_source(): void
    {
        $c = $this->title();
        $ep = $this->episode($c, 1, ['video_url' => 'https://cdn.example.com/a.mp4', 'manual_link_at' => now()]);

        $this->actingAs($this->admin())
            ->delete(route('admin.contents.episodes.link.clear', [$c, $ep]))
            ->assertSessionHasNoErrors();

        $ep->refresh();
        $this->assertNull($ep->video_url);
        $this->assertNull($ep->manual_link_at);
        $this->assertSame('rongyok', $ep->source);
    }

    public function test_bulk_numbered_lines_set_links_and_keep_existing_ones_unless_told(): void
    {
        $c = $this->title();
        $e1 = $this->episode($c, 1);
        $e2 = $this->episode($c, 2, ['video_url' => 'https://old.example.com/2.mp4', 'manual_link_at' => now()]);
        $e3 = $this->episode($c, 3);
        $admin = $this->admin();

        $paste = "1 https://cdn.example.com/1.mp4\nEP2 https://cdn.example.com/2.mp4\r\n\nตอนที่ 3|https://cdn.example.com/3.m3u8\n";
        $this->actingAs($admin)
            ->post(route('admin.contents.episodes.links', $c), ['links' => $paste])
            ->assertSessionHasNoErrors();

        $this->assertSame('https://cdn.example.com/1.mp4', $e1->fresh()->video_url);
        $this->assertSame('https://old.example.com/2.mp4', $e2->fresh()->video_url);   // kept: no overwrite
        $this->assertSame('https://cdn.example.com/3.m3u8', $e3->fresh()->video_url);

        $this->actingAs($admin)
            ->post(route('admin.contents.episodes.links', $c), ['links' => $paste, 'overwrite' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame('https://cdn.example.com/2.mp4', $e2->fresh()->video_url);
    }

    public function test_bulk_bare_links_fill_in_list_order_and_dash_skips(): void
    {
        $c = $this->title();
        $e1 = $this->episode($c, 1);
        $e2 = $this->episode($c, 2);
        $e3 = $this->episode($c, 3);

        $this->actingAs($this->admin())
            ->post(route('admin.contents.episodes.links', $c), ['links' => "https://cdn.example.com/1.mp4\n-\nhttps://cdn.example.com/3.mp4"])
            ->assertSessionHasNoErrors();

        $this->assertSame('https://cdn.example.com/1.mp4', $e1->fresh()->video_url);
        $this->assertNull($e2->fresh()->video_url);
        $this->assertSame('https://cdn.example.com/3.mp4', $e3->fresh()->video_url);
    }

    public function test_one_bad_line_saves_nothing_and_names_the_line(): void
    {
        $c = $this->title();
        $e1 = $this->episode($c, 1);
        $this->episode($c, 2);

        $this->actingAs($this->admin())
            ->post(route('admin.contents.episodes.links', $c), ['links' => "1 https://cdn.example.com/1.mp4\n2 http://insecure.example.com/2.mp4\n9 https://cdn.example.com/9.mp4"])
            ->assertSessionHasErrors('links');

        $errors = session('errors')->get('links');
        $this->assertStringContainsString('บรรทัด 2', $errors[0]);
        $this->assertStringContainsString('ไม่มีตอนที่ 9', $errors[1]);
        $this->assertNull($e1->fresh()->video_url);
    }

    public function test_bulk_rejects_more_links_than_episodes_and_mixed_formats(): void
    {
        $c = $this->title();
        $this->episode($c, 1);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.contents.episodes.links', $c), ['links' => "https://cdn.example.com/1.mp4\nhttps://cdn.example.com/2.mp4"])
            ->assertSessionHasErrors('links');

        $this->actingAs($admin)
            ->post(route('admin.contents.episodes.links', $c), ['links' => "1 https://cdn.example.com/1.mp4\nhttps://cdn.example.com/2.mp4"])
            ->assertSessionHasErrors('links');
    }

    public function test_numbered_lines_refuse_an_ambiguous_number_across_seasons(): void
    {
        $c = Content::create(['title' => 'ซีรีส์', 'slug' => 's1', 'type' => 'series', 'is_published' => true, 'maturity' => '15+']);
        $s1 = $c->seasons()->create(['number' => 1, 'title' => 'ซีซั่น 1']);
        $s2 = $c->seasons()->create(['number' => 2, 'title' => 'ซีซั่น 2']);
        $a = $c->episodes()->create(['season_id' => $s1->id, 'number' => 1, 'title' => 'S1E1']);
        $b = $c->episodes()->create(['season_id' => $s2->id, 'number' => 1, 'title' => 'S2E1']);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.contents.episodes.links', $c), ['links' => '1 https://cdn.example.com/1.mp4'])
            ->assertSessionHasErrors('links');

        // Bare links in list order still work: season 1's episode, then season 2's.
        $this->actingAs($admin)
            ->post(route('admin.contents.episodes.links', $c), ['links' => "https://cdn.example.com/s1e1.mp4\nhttps://cdn.example.com/s2e1.mp4"])
            ->assertSessionHasNoErrors();
        $this->assertSame('https://cdn.example.com/s1e1.mp4', $a->fresh()->video_url);
        $this->assertSame('https://cdn.example.com/s2e1.mp4', $b->fresh()->video_url);
    }

    public function test_edit_page_shows_link_controls_for_each_kind_of_episode(): void
    {
        $c = $this->title();
        $this->episode($c, 1, ['video_url' => 'https://cdn.example.com/manual-1.mp4', 'manual_link_at' => now()]);
        $this->episode($c, 2, ['video_url' => 'https://netwix.online/storage/media/rongyok/8207/2.mp4', 'mirrored_at' => now()]);
        $this->episode($c, 3);

        $this->actingAs($this->admin())
            ->get(route('admin.contents.edit', $c))
            ->assertOk()
            ->assertSee('🔗 ลิงก์แอดมิน')
            ->assertSee('https://cdn.example.com/manual-1.mp4')
            ->assertSee('ลบลิงก์')
            ->assertSee('กด "ลบไฟล์" ก่อน', false)
            ->assertSee('วางลิงก์หลายตอนพร้อมกัน')
            ->assertSee(route('admin.contents.episodes.links', $c));
    }

    public function test_the_mirror_leaves_an_admin_link_alone_without_counting_a_failure(): void
    {
        $c = $this->title();
        $ep = $this->episode($c, 1, ['video_url' => 'https://cdn.example.com/a.mp4', 'manual_link_at' => now()]);

        $r = app(MediaMirror::class)->store($ep);

        $this->assertFalse($r['ok']);
        $ep->refresh();
        $this->assertSame('https://cdn.example.com/a.mp4', $ep->video_url);
        $this->assertSame(0, (int) $ep->mirror_attempts);
    }
}
