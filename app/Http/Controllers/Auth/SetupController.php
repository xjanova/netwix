<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * First-run setup: create the primary admin account.
 * Only reachable while the system has no admin at all — once one exists,
 * every request here bounces to the login page.
 */
class SetupController extends Controller
{
    public function show(): View|RedirectResponse
    {
        if ($this->adminExists()) {
            return redirect()->route('login');
        }

        return view('auth.setup');
    }

    public function store(Request $request): RedirectResponse
    {
        if ($this->adminExists()) {
            return redirect()->route('login');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ], [
            'name.required' => 'กรุณากรอกชื่อผู้ดูแล',
            'email.required' => 'กรุณากรอกอีเมล',
            'email.unique' => 'อีเมลนี้ถูกใช้งานแล้ว',
            'password.required' => 'กรุณากรอกรหัสผ่าน',
            'password.confirmed' => 'การยืนยันรหัสผ่านไม่ตรงกัน',
        ]);

        $admin = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => 'admin',
            'plan' => 'premium',
        ]);

        $admin->profiles()->create([
            'name' => $data['name'],
            'avatar_color' => '#b026ff',
        ]);

        Auth::login($admin);
        $request->session()->regenerate();

        return redirect()->route('admin.dashboard')->with('status', 'ตั้งค่าผู้ดูแลหลักเรียบร้อย ยินดีต้อนรับสู่ NetWix!');
    }

    private function adminExists(): bool
    {
        return User::where('role', 'admin')->exists();
    }
}
