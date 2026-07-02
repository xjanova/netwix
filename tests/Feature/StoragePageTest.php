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

        $this->actingAs($admin)->get(route('admin.storage.index'))
            ->assertStatus(200)
            ->assertSee('จัดเก็บสื่อ', false);
    }

    public function test_dashboard_shows_storage_widget(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertStatus(200)
            ->assertSee('พื้นที่จัดเก็บสื่อ', false);
    }
}
