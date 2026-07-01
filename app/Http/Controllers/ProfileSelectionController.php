<?php

namespace App\Http\Controllers;

use App\Models\Profile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileSelectionController extends Controller
{
    /** Palette offered when creating a profile (mirrors the vivid theme tiles). */
    public const PALETTE = [
        '#ff2d55', '#b026ff', '#8b2ff0', '#00b8d4',
        '#46d369', '#f5c518', '#ff8a3d', '#e5484d',
    ];

    public function index(Request $request): View
    {
        $profiles = $request->user()->profiles()->orderBy('id')->get();

        return view('frontend.profiles', compact('profiles'));
    }

    public function select(Request $request, Profile $profile): RedirectResponse
    {
        abort_unless($profile->user_id === $request->user()->id, 403);

        $request->session()->put('profile_id', $profile->id);

        return redirect()->route('browse');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'avatar_color' => ['nullable', 'string', 'max:32'],
            'is_kids' => ['sometimes', 'boolean'],
        ], [
            'name.required' => 'กรุณาตั้งชื่อโปรไฟล์',
        ]);

        abort_if($request->user()->profiles()->count() >= 5, 422, 'สร้างโปรไฟล์ได้สูงสุด 5 โปรไฟล์');

        $request->user()->profiles()->create([
            'name' => $data['name'],
            'avatar_color' => $data['avatar_color'] ?? self::PALETTE[array_rand(self::PALETTE)],
            'is_kids' => $request->boolean('is_kids'),
        ]);

        return redirect()->route('profiles.index');
    }

    public function destroy(Request $request, Profile $profile): RedirectResponse
    {
        abort_unless($profile->user_id === $request->user()->id, 403);
        abort_if($request->user()->profiles()->count() <= 1, 422, 'ต้องมีอย่างน้อย 1 โปรไฟล์');

        if ($request->session()->get('profile_id') === $profile->id) {
            $request->session()->forget('profile_id');
        }
        $profile->delete();

        return redirect()->route('profiles.index');
    }
}
