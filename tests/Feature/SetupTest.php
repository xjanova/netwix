<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_page_is_available_when_no_admin_exists(): void
    {
        $this->get('/setup')->assertStatus(200)->assertSee('ตั้งค่าผู้ดูแลหลัก', false);
    }

    public function test_setup_creates_the_primary_admin_and_logs_in(): void
    {
        $response = $this->post('/setup', [
            'name' => 'ผู้ดูแลหลัก',
            'email' => 'owner@netwix.online',
            'password' => 'SuperSecret123',
            'password_confirmation' => 'SuperSecret123',
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'owner@netwix.online', 'role' => 'admin']);
        $this->assertDatabaseHas('profiles', ['name' => 'ผู้ดูแลหลัก']);
    }

    public function test_setup_rejects_weak_password(): void
    {
        $this->post('/setup', [
            'name' => 'ผู้ดูแล',
            'email' => 'owner@netwix.online',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'owner@netwix.online']);
    }

    public function test_setup_is_locked_once_an_admin_exists(): void
    {
        User::factory()->create(['role' => 'admin']);

        $this->get('/setup')->assertRedirect(route('login'));

        $this->post('/setup', [
            'name' => 'ผู้บุกรุก',
            'email' => 'attacker@example.com',
            'password' => 'SuperSecret123',
            'password_confirmation' => 'SuperSecret123',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseMissing('users', ['email' => 'attacker@example.com']);
    }

    public function test_landing_page_shows_trending_content(): void
    {
        $this->get('/')->assertStatus(200)->assertSee('เริ่มกันเลย', false);
    }
}
