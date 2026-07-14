<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\User;
use App\Support\ImageStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $filter = (string) $request->query('filter', ''); // '' | active | inactive | pro

        $users = User::query()
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")))
            ->when($filter === 'active', fn ($w) => $w->where('is_active', true))
            ->when($filter === 'inactive', fn ($w) => $w->where('is_active', false))
            ->when($filter === 'pro', fn ($w) => $w->where(fn ($x) => $x->where('plan', '!=', 'basic')->orWhere('pro_until', '>', now())))
            ->withCount('profiles')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.users.index', compact('users', 'q', 'filter'));
    }

    public function edit(User $user): View
    {
        $user->load('profiles');
        $membership = app(\App\Services\Membership::class);

        return view('admin.users.edit', [
            'user' => $user,
            'isPro' => $membership->isPro($user),
            'freeDays' => $membership->signupProDays(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:500'],
            'role' => ['required', 'in:user,admin'],
            'plan' => ['required', 'in:basic,standard,premium'],
            'is_active' => ['sometimes', 'boolean'],
            'pro_until' => ['nullable', 'date'],
            'coins' => ['nullable', 'integer', 'between:0,100000000'],
            'gold_coins' => ['nullable', 'integer', 'between:0,100000000'],
        ]);

        // Never let the last admin demote or deactivate themselves out of access.
        $lastAdmin = $user->isAdmin() && User::where('role', 'admin')->where('is_active', true)->count() <= 1;
        if ($lastAdmin && ($data['role'] !== 'admin' || ! $request->boolean('is_active'))) {
            return back()->withErrors(['role' => 'ต้องมีผู้ดูแลระบบที่ใช้งานได้อย่างน้อย 1 คน'])->withInput();
        }

        $membership = app(\App\Services\Membership::class);
        $wasPro = $membership->isPro($user);

        $user->update([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'role' => $data['role'],
            'plan' => $data['plan'],
            'is_active' => $request->boolean('is_active'),
            'pro_until' => $data['pro_until'] ?? null,
            'coins' => $data['coins'] ?? $user->coins,
            'gold_coins' => $data['gold_coins'] ?? $user->gold_coins,
        ]);

        // Newly upgraded to Pro (paid plan or a fresh grant) → pay the affiliate dividend up the chain.
        if (! $wasPro && $membership->isPro($user->refresh())) {
            $membership->distributeProDividend($user);
        }

        return back()->with('status', 'บันทึกข้อมูลสมาชิกแล้ว');
    }

    /** Quick active/suspend toggle from the member list. */
    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        $on = $request->boolean('active');
        if (! $on && $user->isAdmin() && User::where('role', 'admin')->where('is_active', true)->count() <= 1) {
            return back()->withErrors(['is_active' => 'ต้องมีผู้ดูแลระบบที่ใช้งานได้อย่างน้อย 1 คน']);
        }

        $user->update(['is_active' => $on]);

        return back()->with('status', $on ? 'เปิดใช้งานบัญชีแล้ว' : 'ระงับบัญชีแล้ว');
    }

    /** Admin uploads / sets an avatar image for one of the member's viewing profiles. */
    public function avatar(Request $request, User $user, Profile $profile): JsonResponse
    {
        abort_unless($profile->user_id === $user->id, 404);
        $request->validate(['image' => ['required', 'string']]);

        $path = ImageStore::putWebp($this->decodeImage($request->input('image')), 'media/avatars', 'p'.$profile->id, 512);
        if ($path === null) {
            return response()->json(['ok' => false, 'error' => 'รูปไม่ถูกต้อง'], 422);
        }

        $profile->update(['avatar_path' => $path]);

        return response()->json(['ok' => true, 'url' => $profile->refresh()->avatar_url]);
    }

    /** Decode a data-URI or raw base64 avatar payload to bytes. */
    private function decodeImage(string $input): string
    {
        if (str_contains($input, ',')) {
            $input = substr($input, strpos($input, ',') + 1);
        }

        return (string) base64_decode($input, true);
    }
}
