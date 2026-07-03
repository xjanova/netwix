<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\Content;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Comments + star ratings for a title. Reads (list comments, rating summary) are
 * public; writes require a member token (auth.apptoken).
 */
class FeedbackController extends Controller
{
    // ------------------------------------------------------------- comments

    public function comments(Content $content): JsonResponse
    {
        $rows = $content->comments()->with('profile')->latest()->limit(100)->get();

        return $this->ok([
            'items' => $rows->map(fn ($c) => $this->commentPayload($c))->all(),
            'count' => $content->comments()->count(),
        ]);
    }

    public function storeComment(Request $request, Content $content): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:500']]);
        $profile = $request->user()->defaultProfile();

        $comment = $content->comments()->create([
            'profile_id' => $profile->id,
            'body' => trim($data['body']),
        ]);
        $comment->setRelation('profile', $profile);

        return $this->ok(['comment' => $this->commentPayload($comment)]);
    }

    // -------------------------------------------------------------- ratings

    public function ratings(Content $content): JsonResponse
    {
        return $this->ok([
            'avg' => round((float) $content->ratings()->avg('stars'), 1),
            'count' => $content->ratings()->count(),
        ]);
    }

    public function storeRating(Request $request, Content $content): JsonResponse
    {
        $data = $request->validate(['stars' => ['required', 'integer', 'between:1,5']]);
        $profile = $request->user()->defaultProfile();

        $content->ratings()->updateOrCreate(
            ['profile_id' => $profile->id],
            ['stars' => $data['stars']],
        );

        return $this->ok([
            'my_rating' => $data['stars'],
            'avg' => round((float) $content->ratings()->avg('stars'), 1),
            'count' => $content->ratings()->count(),
        ]);
    }

    // --------------------------------------------------------------- shared

    private function commentPayload($comment): array
    {
        return [
            'id' => $comment->id,
            'author' => $comment->profile?->name ?? 'สมาชิก',
            'avatar_color' => $comment->profile?->avatar_color,
            'text' => $comment->body,
            'created_at' => $comment->created_at?->toIso8601String(),
        ];
    }

    private function ok(array $data): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data]);
    }
}
