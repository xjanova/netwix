<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\AppToken;
use App\Models\Profile;
use App\Models\User;
use App\Services\Membership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Mobile auth bridge. The app has no OAuth client of its own — it reuses the
 * web's existing email / Google / LINE sign-in:
 *
 *   1. app opens  GET /mauth/start?provider=…&cc=<challenge>  in an in-app browser
 *   2. user signs in on the web (session) via the normal login / Socialite flow
 *   3. web lands on  GET /mauth/issue  → mints a ONE-TIME code and redirects
 *      to the deep link  netwix://auth?code=…   (no token in the URL)
 *   4. app exchanges the code  POST /api/app/auth/exchange {code, verifier}  → bearer token
 *
 * Thereafter the app sends  Authorization: Bearer <token>  (see AuthenticateAppToken).
 *
 * `cc` is PKCE, and it exists because step 3 hands the code to a CUSTOM SCHEME. Any app on the
 * phone may register `netwix://` too, and Android will happily let it take the redirect — the code
 * is one-time, but "one time" is no help when the thief gets there first. So the app now sends the
 * SHA-256 of a secret it keeps in memory, and the exchange only works for whoever can produce that
 * secret. An intercepted code is then worth nothing on its own.
 *
 * Binding is per-code, not global: an already-installed build sends no challenge and keeps working
 * exactly as before (a sideloaded app cannot be force-updated, and a sign-in that suddenly fails on
 * every old install is worse than the risk). A code minted WITH a challenge always demands it, so
 * nothing an attacker does can downgrade a protected sign-in.
 */
class AuthController extends Controller
{
    private const CODE_TTL = 120;                 // seconds a login code stays valid
    private const CALLBACK = 'netwix://auth';     // app deep link

    // -------------------------------------------------------------- web bridge

    /** Start the web sign-in, remembering to come back to `issue`. */
    public function start(Request $request): RedirectResponse
    {
        // Carry the app's PKCE challenge across the login round-trip. Stored in the SESSION, not in
        // the query string it will be redirected through: it has to survive Socialite bouncing the
        // browser to Google/LINE and back, and nothing outside this browser ever needs to read it.
        // Anything that isn't a base64url SHA-256 digest is dropped rather than trusted.
        $challenge = (string) $request->query('cc', '');
        $request->session()->forget('app_auth_cc');
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge)) {
            $request->session()->put('app_auth_cc', $challenge);
        }

        // Already signed in on the web (session) → skip straight to issuing a code.
        if ($request->user()) {
            return redirect()->route('app.auth.issue');
        }

        $request->session()->put('url.intended', route('app.auth.issue'));

        $provider = (string) $request->query('provider', '');
        if (in_array($provider, ['google', 'line'], true)) {
            return redirect()->route('social.redirect', $provider);
        }

        return redirect()->route('login');
    }

    /** After a successful web login: mint a one-time code, deep-link back to the app. */
    public function issue(Request $request): RedirectResponse
    {
        $user = $request->user();
        $code = Str::random(48);
        // pull, not get: a challenge belongs to exactly the sign-in that started with it, and must
        // not be left behind to bind (or fail) an unrelated one later in the same browser session.
        Cache::put("app_auth_code:{$code}", [
            'uid' => $user->id,
            'cc' => $request->session()->pull('app_auth_cc'),
        ], now()->addSeconds(self::CODE_TTL));

        return redirect()->away(self::CALLBACK.'?code='.$code);
    }

    // ---------------------------------------------------------------- app API

    /** Exchange a one-time code for a bearer token. Public but rate-limited. */
    public function exchange(Request $request): JsonResponse
    {
        $code = (string) $request->input('code', '');
        $entry = $code !== '' ? Cache::pull("app_auth_code:{$code}") : null; // pull = one-time

        // Codes minted by the previous release are a bare user id. Read both shapes for the two
        // minutes an in-flight sign-in can straddle a deploy.
        $userId = is_array($entry) ? ($entry['uid'] ?? null) : $entry;
        $challenge = is_array($entry) ? ($entry['cc'] ?? null) : null;
        $user = $userId ? User::find($userId) : null;

        if (! $user) {
            return response()->json(['success' => false, 'error' => 'invalid_code'], 422);
        }

        // The code was bound to a secret at /mauth/start — whoever presents it has to prove they
        // are the app that asked for it, not merely something that caught the deep link.
        if ($challenge !== null && ! self::verifierMatches((string) $request->input('verifier', ''), $challenge)) {
            return response()->json(['success' => false, 'error' => 'invalid_verifier'], 422);
        }

        // The app shows a consent checkbox before opening the sign-in flow, so a
        // completed exchange doubles as the consent event for accounts that
        // pre-date the checkbox (or came in via social sign-up).
        if ($user->terms_accepted_at === null) {
            $user->forceFill(['terms_accepted_at' => now()])->save();
        }

        $token = AppToken::issue($user, (string) $request->input('device', 'mobile'));

        return response()->json([
            'success' => true,
            'data' => ['token' => $token, 'user' => $this->userPayload($user)],
        ]);
    }

    /** Current user + default profile. */
    public function me(Request $request): JsonResponse
    {
        // Report the profile this DEVICE is watching as (bound to its token by
        // AuthenticateAppToken), not just the account default — otherwise the app
        // would show "Kid" as active while /me insisted it was the owner.
        return response()->json([
            'success' => true,
            'data' => $this->userPayload($request->user(), $request->attributes->get('profile')),
        ]);
    }

    /** Revoke the presented token. */
    public function logout(Request $request): JsonResponse
    {
        if ($plain = $request->bearerToken()) {
            AppToken::revoke($plain);
        }

        return response()->json(['success' => true, 'data' => null]);
    }

    /** Which social sign-in providers are configured, so the app hides the rest. */
    public function providers(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => [
            'google' => filled(config('services.google.client_id')),
            'line' => filled(config('services.line.client_id')),
            'email' => true,
        ]]);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * PKCE S256 check. hash_equals because this compares a secret-derived digest: a plain `===`
     * on hashes leaks how many leading bytes matched through how long it took to say no.
     */
    private static function verifierMatches(string $verifier, string $challenge): bool
    {
        if (strlen($verifier) < 43 || strlen($verifier) > 128) {
            return false;
        }
        $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return hash_equals($challenge, $computed);
    }


    private function userPayload(User $user, ?Profile $active = null): array
    {
        // Fresh exchange has no bound profile yet → the account default.
        $profile = $active ?? $user->defaultProfile();
        $membership = app(Membership::class)->state($user);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => str_ends_with((string) $user->email, '@social.netwix') ? null : $user->email,
            'avatar' => $user->avatar,
            'provider' => $user->provider,
            // Flat fields kept for the app's existing reads; `membership` has the full state.
            'plan' => $membership['plan'],
            'is_pro' => $membership['is_pro'],
            'coins' => $membership['coins'],
            'referral_code' => $membership['referral_code'],
            'membership' => $membership,
            'profile' => [
                'id' => $profile->id,
                'name' => $profile->name,
                'avatar_color' => $profile->avatar_color,
                'avatar_url' => $profile->avatar_url,
                'initial' => $profile->initial,
                'is_kids' => (bool) $profile->is_kids,
            ],
        ];
    }
}
