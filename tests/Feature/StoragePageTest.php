<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoragePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_storage_page_renders_for_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Asserts the page's own furniture (its title and a column of the per-source table), not a
        // passing phrase: this test failed for nine days because it looked for "จัดเก็บสื่อ", a
        // heading that was renamed the day this page became the download monitor. A rendering test
        // should notice the page breaking, not the copy being reworded.
        $this->actingAs($admin)->get(route('admin.storage.index'))
            ->assertStatus(200)
            ->assertSee('พื้นที่จัดเก็บ', false)
            ->assertSee('ขนาดรวม', false);
    }

    public function test_dashboard_shows_storage_widget(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertStatus(200)
            ->assertSee('พื้นที่จัดเก็บสื่อ', false);
    }
}
