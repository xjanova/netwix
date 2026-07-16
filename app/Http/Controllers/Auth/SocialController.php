<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class SocialController extends Controller
{
    /** Providers we accept. Keep in sync with config/services.php + the login buttons. */
    private const PROVIDERS = ['google', 'line'];

    /** Kick off the OAuth redirect to Google / LINE. */
    public function redirect(string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);

        if ($notReady = $this->unavailable()) {
            return $notReady;
        }

        return Socialite::driver($provider)->redirect();
    }

    /** Handle the provider callback: find-or-create the account, then sign in. */
    public function callback(string $provider, Request $request): RedirectResponse
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);

        if ($notReady = $this->unavailable()) {
            return $notReady;
        }

        try {
            $social = Socialite::driver($provider)->user();
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('login')->withErrors([
                'email' => 'เข้าสู่ระบบผ่าน '.strtoupper($provider).' ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง',
            ]);
        }

        $user = $this->findOrCreate($provider, $social);

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        // Honor a pending intended URL (the mobile auth bridge sets this) so
        // social sign-in can hand control back to /app/auth/issue.
        return redirect()->intended(route('profiles.index'));
    }

    /**
     * Graceful fallback when laravel/socialite isn't installed on the server yet
     * (the buttons are hidden until credentials exist, but guard direct hits too).
     * `::class` is a compile-time string, so this never triggers autoloading.
     */
    private function unavailable(): ?RedirectResponse
    {
        if (class_exists(\Laravel\Socialite\Facades\Socialite::class)) {
            return null;
        }

        return redirect()->route('login')->withErrors([
            'email' => 'ระบบเข้าสู่ระบบผ่านโซเชียลยังไม่พร้อมใช้งาน กรุณาลองอีกครั้งภายหลัง',
        ]);
    }

    /**
     * @param  \Laravel\Socialite\Contracts\User  $social
     */
    private function findOrCreate(string $provider, $social): User
    {
        // 1) Already linked to this provider → straight in.
        $user = User::where('provider', $provider)
            ->where('provider_id', $social->getId())
            ->first();

        if ($user) {
            return $user;
        }

        // 2) LINE may withhold the email; fall back to a stable placeholder so the
        //    unique/not-null constraint holds and repeat logins map to the same row.
        $email = $social->getEmail() ?: $provider.'_'.$social->getId().'@social.netwix';

        // 3) Link an existing email-based account, or create a fresh one.
        $user = User::firstOrNew(['email' => $email]);
        $user->fill([
            'name' => $social->getName() ?: $social->getNickname() ?: 'ผู้ใช้ NetWix',
            'provider' => $provider,
            'provider_id' => $social->getId(),
            'avatar' => $social->getAvatar(),
        ]);

        if (! $user->exists) {
            $user->password = null; // social accounts have no local password
            // Social sign-up pages state that continuing = accepting the terms
            // (register view + app consent checkbox), so record it here.
            $user->terms_accepted_at = now();
        }
        $user->save();

        // Brand-new social account → grant the same signup coin bonus that email
        // sign-ups get (RegisterController). wasRecentlyCreated is only true on insert.
        if ($user->wasRecentlyCreated) {
            $m = app(\App\Services\Membership::class);
            $m->addCoins($user, (int) ($m->config()['signup_bonus_coins'] ?? 0), 'signup');
            $m->grantSignupPro($user);   // same free Pro window as email sign-ups (admin-configured)
        }

        // Give brand-new accounts a starter profile (mirrors RegisterController).
        if ($user->profiles()->count() === 0) {
            $user->profiles()->create([
                'name' => Str::limit($user->name, 20, ''),
                'avatar_color' => '#8b2ff0',
            ]);
        }

        return $user;
    }
}
