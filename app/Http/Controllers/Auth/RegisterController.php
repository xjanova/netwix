<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use App\Services\GuestWatch;
use App\Services\Membership;
use App\Support\AdminAlerts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class RegisterController extends Controller
{
    public function show(Request $request): View
    {
        app(GuestWatch::class)->rememberDestination($request);
        if (is_string($request->query('ref')) && preg_match('/\A[A-Za-z0-9]{1,16}\z/', $request->query('ref'))) {
            $request->session()->put('referral.invite', strtoupper($request->query('ref')));
        }

        return view('auth.register', [
            'emailReg' => Setting::flag('email_registration_enabled', true),
        ]);
    }

    public function register(Request $request): RedirectResponse
    {
        // Email/password sign-up can be switched off (social-only) from admin settings.
        if (! Setting::flag('email_registration_enabled', true)) {
            return redirect()->route('register')
                ->withErrors(['email' => 'ขณะนี้เปิดรับสมัครผ่าน Google และ LINE เท่านั้น']);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'ref' => ['nullable', 'string', 'max:16'],
            'accept_terms' => ['accepted'],
        ], [
            'name.required' => 'กรุณากรอกชื่อ',
            'email.required' => 'กรุณากรอกอีเมล',
            'email.unique' => 'อีเมลนี้ถูกใช้งานแล้ว',
            'password.required' => 'กรุณากรอกรหัสผ่าน',
            'password.confirmed' => 'การยืนยันรหัสผ่านไม่ตรงกัน',
            'password.min' => 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร',
            'accept_terms.accepted' => 'กรุณายอมรับข้อตกลงการใช้งานและนโยบายความเป็นส่วนตัวก่อนสมัคร',
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);
        // The checkbox above is the consent event — record when it happened.
        $user->forceFill(['terms_accepted_at' => now()])->save();

        // Give every new account a starter profile.
        $user->profiles()->create([
            'name' => $data['name'],
            'avatar_color' => '#8b2ff0',
        ]);

        // Membership: own referral code + signup bonus, then redeem a friend's code if present.
        $membership = app(Membership::class);
        $membership->ensureCode($user);
        $membership->addCoins($user, (int) $membership->config()['signup_bonus_coins'], 'signup');
        $membership->grantSignupPro($user);   // free Pro window for new members (admin-configured, default 1 month)
        $invite = $data['ref'] ?? $request->session()->get('referral.invite');
        if (filled($invite)) {
            $membership->redeem($user, $invite);
        }
        $request->session()->forget('referral.invite');
        AdminAlerts::noteSignup($user->id, (string) $user->name, 'อีเมล');

        // remember: brand-new members shouldn't be the ones asked to sign in again
        // first (social sign-up already does this).
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('profiles.index'));
    }
}
