<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `users.plan` defaulted to 'premium', which Membership::isPro() counts as Pro — every signup was
 * permanently Pro (18+, ads off, VIP via pro_unlocks). Owner's call 2026-09-23: new accounts start on
 * 'basic' with the signup promo, and the accounts that got 'premium' only by default keep Pro for one
 * promo window from the day of the change.
 */
class MembershipPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_signup_gets_the_promo_window_not_a_permanent_plan(): void
    {
        $this->post('/register', [
            'name' => 'สมาชิกใหม่', 'email' => 'new@example.test',
            'password' => 'password-123', 'password_confirmation' => 'password-123', 'accept_terms' => '1',
        ])->assertRedirect();

        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertSame('basic', $user->plan);
        $this->assertTrue($user->pro_until->between(now()->addDays(29), now()->addDays(31)));
        $this->assertTrue(app(Membership::class)->isPro($user));

        $this->travel(31)->days();
        $this->assertFalse(app(Membership::class)->isPro($user->fresh()), 'the promo ends; the account is not Pro forever');
    }

    public function test_an_account_created_without_a_plan_is_not_pro(): void
    {
        $user = User::factory()->create();

        $this->assertSame('basic', $user->fresh()->plan);
        $this->assertFalse(app(Membership::class)->isPro($user->fresh()));
    }

    public function test_default_premium_accounts_move_to_one_promo_window(): void
    {
        $member = User::factory()->create(['plan' => 'premium']);
        $bought = User::factory()->create(['plan' => 'premium', 'pro_until' => now()->addDays(90)]);
        $admin = User::factory()->create(['plan' => 'premium', 'role' => 'admin']);
        $basic = User::factory()->create(['plan' => 'basic']);

        (require database_path('migrations/2026_09_23_090100_retire_default_premium_plans.php'))->up();

        $member->refresh();
        $this->assertSame('basic', $member->plan);
        $this->assertTrue($member->pro_until->between(now()->addDays(29), now()->addDays(31)));

        $this->assertSame('basic', $bought->fresh()->plan);
        $this->assertTrue($bought->fresh()->pro_until->greaterThan(now()->addDays(89)), 'a longer paid window is kept');

        $this->assertSame('premium', $admin->fresh()->plan, 'admins keep their plan');
        $this->assertNull($basic->fresh()->pro_until, 'basic members are untouched');
    }
}
