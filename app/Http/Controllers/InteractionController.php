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

    /** Post a comment on a title (members only). */
    public function comment(Request $request, Content $content): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:500']]);
        $profile = $this->profile($request);

        $comment = $content->comments()->create([
            'profile_id' => $profile->id,
            'body' => trim($data['body']),
        ]);

        return response()->json([
            'comment' => [
                'author' => $profile->name,
                'avatar_color' => $profile->avatar_color,
                'initial' => $profile->initial,
                'text' => $comment->body,
                'ago' => $comment->created_at->diffForHumans(),
            ],
            'count' => $content->comments()->count(),
        ]);
    }

    /** Rate a title 1-5 stars (one per profile; updates on re-rate). */
    public function rate(Request $request, Content $content): JsonResponse
    {
        $data = $request->validate(['stars' => ['required', 'integer', 'between:1,5']]);

        $content->ratings()->updateOrCreate(
            ['profile_id' => $this->profile($request)->id],
            ['stars' => $data['stars']],
        );

        return response()->json([
            'my_rating' => $data['stars'],
            'avg' => round((float) $content->ratings()->avg('stars'), 1),
            'count' => $content->ratings()->count(),
        ]);
    }

    private function profile(Request $request): Profile
    {
        return $request->attributes->get('profile');
    }
}
