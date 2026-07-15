<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ProfileSelectionController;
use App\Models\Profile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Profiles + kids mode for the mobile app — the API twin of
 * ProfileSelectionController, holding to the same rules (max 5, at least 1,
 * the same colour palette).
 *
 * The app previously had no concept of profiles at all: it used a single
 * read-only defaultProfile(), so kids mode simply did not exist on mobile even
 * though the web has hidden adult titles from kids profiles all along.
 *
 * "Selecting" a profile writes it to the TOKEN (the web uses the session). That
 * is deliberate: the gate must not depend on the client remembering to say it's
 * a kid.
 */
class ProfileController extends Controller
{
    /** GET /api/app/profiles — the account's profiles + which one is active. */
    public function index(Request $request): JsonResponse
    {
        $token = $request->attributes->get('app_token');
        $active = $request->attributes->get('profile');

        return $this->ok([
            'items' => $request->user()->profiles()->orderBy('id')->get()
                ->map(fn ($p) => $this->payload($p))->all(),
            'active_id' => $active?->id,
            'max' => 5,
            'palette' => ProfileSelectionController::PALETTE,
            'can_create' => $request->user()->profiles()->count() < 5,
            'token_bound' => $token?->profile_id !== null,
        ]);
    }

    /** POST /api/app/profiles — create one. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'avatar_color' => ['nullable', 'string', 'max:32'],
            'is_kids' => ['sometimes', 'boolean'],
        ]);

        if ($request->user()->profiles()->count() >= 5) {
            return $this->fail('max_profiles', 'สร้างโปรไฟล์ได้สูงสุด 5 โปรไฟล์');
        }

        $palette = ProfileSelectionController::PALETTE;
        $profile = $request->user()->profiles()->create([
            'name' => $data['name'],
            'avatar_color' => $data['avatar_color'] ?? $palette[array_rand($palette)],
            'is_kids' => $request->boolean('is_kids'),
        ]);

        return $this->ok(['profile' => $this->payload($profile)]);
    }

    /** POST /api/app/profiles/{profile}/select — bind it to this device's token. */
    public function select(Request $request, Profile $profile): JsonResponse
    {
        if ($profile->user_id !== $request->user()->id) {
            return $this->fail('forbidden', 'ไม่พบโปรไฟล์', 403);
        }

        $token = $request->attributes->get('app_token');
        $token?->forceFill(['profile_id' => $profile->id])->save();

        return $this->ok(['profile' => $this->payload($profile)]);
    }

    /** POST /api/app/profiles/{profile} — rename / recolour / toggle kids. */
    public function update(Request $request, Profile $profile): JsonResponse
    {
        if ($profile->user_id !== $request->user()->id) {
            return $this->fail('forbidden', 'ไม่พบโปรไฟล์', 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'avatar_color' => ['nullable', 'string', 'max:32'],
            'is_kids' => ['sometimes', 'boolean'],
        ]);

        $profile->update([
            'name' => $data['name'],
            'avatar_color' => $data['avatar_color'] ?? $profile->avatar_color,
            'is_kids' => $request->boolean('is_kids'),
        ]);

        return $this->ok(['profile' => $this->payload($profile->refresh())]);
    }

    /** DELETE /api/app/profiles/{profile} — an account must keep at least one. */
    public function destroy(Request $request, Profile $profile): JsonResponse
    {
        if ($profile->user_id !== $request->user()->id) {
            return $this->fail('forbidden', 'ไม่พบโปรไฟล์', 403);
        }
        if ($request->user()->profiles()->count() <= 1) {
            return $this->fail('last_profile', 'ต้องมีอย่างน้อย 1 โปรไฟล์');
        }

        // Devices pointed at it fall back to the default (FK is nullOnDelete).
        $profile->delete();

        return $this->ok(['deleted' => true]);
    }

    private function payload(Profile $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'avatar_color' => $p->avatar_color,
            'avatar_url' => $p->avatar_url,
            'initial' => $p->initial,
            'is_kids' => (bool) $p->is_kids,
        ];
    }

    private function ok(array $data): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data]);
    }

    private function fail(string $code, string $message, int $status = 422): JsonResponse
    {
        return response()->json(['success' => false, 'error' => $code, 'message' => $message], $status);
    }
}
