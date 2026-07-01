<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Models\Episode;
use App\Models\Profile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InteractionController extends Controller
{
    public function toggleMyList(Request $request, Content $content): JsonResponse
    {
        $result = $this->profile($request)->myList()->toggle($content->id);

        return response()->json([
            'in_list' => count($result['attached']) > 0,
        ]);
    }

    public function toggleLike(Request $request, Content $content): JsonResponse
    {
        $result = $this->profile($request)->likes()->toggle($content->id);

        return response()->json([
            'liked' => count($result['attached']) > 0,
        ]);
    }

    public function progress(Request $request, Content $content): JsonResponse
    {
        $data = $request->validate([
            'percent' => ['required', 'integer', 'between:0,100'],
            'position_seconds' => ['nullable', 'integer', 'min:0'],
            'episode_id' => ['nullable', 'integer', 'exists:episodes,id'],
        ]);

        if (! empty($data['episode_id'])
            && ! Episode::where('id', $data['episode_id'])->where('content_id', $content->id)->exists()) {
            abort(422, 'ตอนไม่ตรงกับเรื่อง');
        }

        $this->profile($request)->watchProgress()->updateOrCreate(
            ['content_id' => $content->id],
            [
                'episode_id' => $data['episode_id'] ?? null,
                'percent' => $data['percent'],
                'position_seconds' => $data['position_seconds'] ?? 0,
                'last_watched_at' => now(),
            ],
        );

        return response()->json(['ok' => true]);
    }

    private function profile(Request $request): Profile
    {
        return $request->attributes->get('profile');
    }
}
