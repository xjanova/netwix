<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The app's sign-in code comes home over a CUSTOM SCHEME (netwix://auth?code=…), and any app on the
 * phone may register that scheme. One-time use does not save a code the wrong app reaches first —
 * so the code is bound to a secret only the real app holds. These pin both halves: a bound code is
 * useless without the secret, and an old install that never sends one still signs in.
 */
class AppAuthPkceTest extends TestCase
{
    use RefreshDatabase;

    private const VERIFIER = 'a-verifier-long-enough-to-be-a-real-pkce-secret-000000';

    private static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function issueCode(User $user, ?string $challenge): string
    {
        $this->actingAs($user)
            ->get('/mauth/start'.($challenge !== null ? '?cc='.$challenge : ''))
            ->assertRedirect(route('app.auth.issue'));

        $redirect = $this->actingAs($user)->get(route('app.auth.issue'))->headers->get('Location');

        return (string) parse_url((string) $redirect, PHP_URL_QUERY) === ''
            ? ''
            : (string) (explode('code=', (string) $redirect)[1] ?? '');
    }

    public function test_a_bound_code_is_worthless_without_the_secret(): void
    {
        $code = $this->issueCode(User::factory()->create(), self::challenge(self::VERIFIER));
        $this->assertNotSame('', $code);

        // The thief has the code — everything a scheme-hijacking app could get — and nothing else.
        $this->postJson('/api/app/auth/exchange', ['code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_verifier');
    }

    public function test_a_wrong_secret_is_refused(): void
    {
        $code = $this->issueCode(User::factory()->create(), self::challenge(self::VERIFIER));

        $this->postJson('/api/app/auth/exchange', [
            'code' => $code,
            'verifier' => str_repeat('b', 60),
        ])->assertStatus(422)->assertJsonPath('error', 'invalid_verifier');
    }

    public function test_the_real_app_signs_in(): void
    {
        $code = $this->issueCode(User::factory()->create(), self::challenge(self::VERIFIER));

        $this->postJson('/api/app/auth/exchange', ['code' => $code, 'verifier' => self::VERIFIER])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['token', 'user']]);
    }

    /**
     * A sideloaded app cannot be force-updated. An install that predates PKCE sends no challenge and
     * must keep signing in — binding is per-code, so this cannot be used to downgrade a code that
     * WAS bound (see the first test, which never sends a verifier either).
     */
    public function test_an_install_that_predates_pkce_still_signs_in(): void
    {
        $code = $this->issueCode(User::factory()->create(), null);

        $this->postJson('/api/app/auth/exchange', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    /** A code minted by the previous release is a bare user id in the cache. */
    public function test_a_code_from_the_previous_release_is_still_redeemable(): void
    {
        $user = User::factory()->create();
        Cache::put('app_auth_code:legacy-code', $user->id, now()->addSeconds(120));

        $this->postJson('/api/app/auth/exchange', ['code' => 'legacy-code'])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);
    }

    /** A challenge that isn't a base64url digest is dropped, not stored and not echoed. */
    public function test_a_malformed_challenge_is_ignored(): void
    {
        $code = $this->issueCode(User::factory()->create(), '../../etc/passwd');

        $this->postJson('/api/app/auth/exchange', ['code' => $code])->assertOk();
    }
}
