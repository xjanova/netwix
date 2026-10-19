<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * First-time Google sign-up was answered with "บัญชีนี้ถูกระงับการใช้งาน": the new row was saved
 * active, but the in-memory model never read the column default back, so is_active was null.
 */
class SocialSignInTest extends TestCase
{
    use RefreshDatabase;

    private function googleReturns(string $id, string $email): void
    {
        $this->providerReturns('google', $id, $email);
    }

    private function providerReturns(string $driver, string $id, ?string $email): void
    {
        $social = (new SocialiteUser)->map([
            'id' => $id, 'name' => 'ผู้ใช้ Google', 'email' => $email, 'avatar' => null,
        ]);
        $provider = Mockery::mock(\Laravel\Socialite\Contracts\Provider::class);
        $provider->shouldReceive('user')->andReturn($social);
        Socialite::shouldReceive('driver')->with($driver)->andReturn($provider);
    }

    public function test_a_first_time_google_sign_up_is_signed_in_not_called_suspended(): void
    {
        $this->googleReturns('g-new-1', 'new@example.com');

        $this->get('/auth/google/callback')
            ->assertRedirect(route('profiles.index'))
            ->assertSessionHasNoErrors();

        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertTrue($user->is_active);
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_suspended_account_still_cannot_come_back_in_by_google(): void
    {
        User::factory()->create([
            'email' => 'off@example.com', 'provider' => 'google', 'provider_id' => 'g-off', 'is_active' => false,
        ]);
        $this->googleReturns('g-off', 'off@example.com');

        $this->get('/auth/google/callback')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    /**
     * The app has no sign-up of its own: it opens /mauth/start in an in-app browser, rides the same
     * Socialite callback, and comes home with a one-time code. LINE may withhold the email.
     */
    public function test_a_first_time_sign_up_from_the_app_comes_home_with_a_token(): void
    {
        foreach (['google' => 'app-new@example.com', 'line' => null] as $driver => $email) {
            $verifier = str_repeat('v', 50);
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

            $this->get('/mauth/start?provider='.$driver.'&cc='.$challenge)
                ->assertRedirect(route('social.redirect', $driver));

            $this->providerReturns($driver, $driver.'-app-1', $email);
            $this->get('/auth/'.$driver.'/callback')
                ->assertRedirect(route('app.auth.issue'))
                ->assertSessionHasNoErrors();

            $deepLink = (string) $this->get(route('app.auth.issue'))->headers->get('Location');
            $this->assertStringStartsWith('netwix://auth?code=', $deepLink);

            $this->postJson('/api/app/auth/exchange', [
                'code' => explode('code=', $deepLink)[1], 'verifier' => $verifier,
            ])->assertOk()->assertJsonPath('success', true)->assertJsonStructure(['data' => ['token', 'user']]);

            $this->assertTrue(User::where('provider', $driver)->where('provider_id', $driver.'-app-1')->firstOrFail()->is_active);
            auth()->logout();
            $this->flushSession();
        }
    }
}
