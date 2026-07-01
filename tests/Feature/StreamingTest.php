<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
