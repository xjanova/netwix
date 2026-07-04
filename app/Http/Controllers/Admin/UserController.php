<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $users = User::query()
            ->when($q !== '', fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%"))
            ->withCount('profiles')
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.users.index', compact('users', 'q'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', 'in:user,admin'],
            'plan' => ['required', 'in:basic,standard,premium'],
        ]);

        // Never let the last admin demote themselves out of access.
        if ($user->isAdmin() && $data['role'] !== 'admin' && User::where('role', 'admin')->count() <= 1) {
            return back()->withErrors(['role' => 'ต้องมีผู้ดูแลระบบอย่างน้อย 1 คน']);
        }

        $membership = app(\App\Services\Membership::class);
        $wasPro = $membership->isPro($user);
        $user->update($data);

        // Newly upgraded to a paid Pro plan → pay affiliate dividend up the chain.
        if (! $wasPro && $membership->isPro($user->refresh())) {
            $membership->distributeProDividend($user);
        }

        return back()->with('status', 'อัปเดตสมาชิกแล้ว');
    }
}
