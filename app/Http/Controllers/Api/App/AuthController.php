<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\AppToken;
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
 *   1. app opens  GET /mauth/start?provider=…  in an in-app browser
 *   2. user signs in on the web (session) via the normal login / Socialite flow
 *   3. web lands on  GET /mauth/issue  → mints a ONE-TIME code and redirects
 *      to the deep link  netwix://auth?code=…   (no token in the URL)
 *   4. app exchanges the code  POST /api/app/auth/exchange {code}  → bearer token
 *
 * Thereafter the app sends  Authorization: Bearer <token>  (see AuthenticateAppToken).
 */
class AuthController extends Controller
{
    private const CODE_TTL = 120;                 // seconds a login code stays valid
    private const CALLBACK = 'netwix://auth';     // app deep link

    // -------------------------------------------------------------- web bridge

    /** Start the web sign-in, remembering to come back to `issue`. */
    public function start(Request $request): RedirectResponse
    {
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
        Cache::put("app_auth_code:{$code}", $user->id, now()->addSeconds(self::CODE_TTL));

        return redirect()->away(self::CALLBACK.'?code='.$code);
    }

    // ---------------------------------------------------------------- app API

    /** Exchange a one-time code for a bearer token. Public but rate-limited. */
    public function exchange(Request $request): JsonResponse
    {
        $code = (string) $request->input('code', '');
        $userId = $code !== '' ? Cache::pull("app_auth_code:{$code}") : null; // pull = one-time
        $user = $userId ? User::find($userId) : null;

        if (! $user) {
            return response()->json(['success' => false, 'error' => 'invalid_code'], 422);
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
        return response()->json(['success' => true, 'data' => $this->userPayload($request->user())]);
    }

    /** Revoke the presented token. */
    public function logout(Request $request): JsonResponse
    {
        if ($plain = $request->bearerToken()) {
            AppToken::revoke($plain);
        }

        return response()->json(['success' => true, 'data' => null]);
    }

    // ---------------------------------------------------------------- helpers

    private function userPayload(User $user): array
    {
        $profile = $user->defaultProfile();
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
            ],
        ];
    }
}
