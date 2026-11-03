<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContentResource;
use App\Models\Content;
use App\Models\Profile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Signed-in member library: my-list, likes, watch progress, and the per-title
 * interaction state the app needs to render the detail screen. All scoped to
 * the token user's default profile. (auth.apptoken)
 */
class LibraryController extends Controller
{
    public function myList(Request $request): JsonResponse
    {
        $items = $this->profile($request)->myList()->published()
            ->with('genres')->withCount('episodes')->withMemberRating()
            ->orderByPivot('created_at', 'desc')->get();

        return $this->ok(['items' => ContentResource::collection($items)]);
    }

    public function toggleList(Request $request, Content $content): JsonResponse
    {
        $res = $this->profile($request)->myList()->toggle($content->id);

        return $this->ok(['in_list' => count($res['attached']) > 0]);
    }

    public function toggleLike(Request $request, Content $content): JsonResponse
    {
        $res = $this->profile($request)->likes()->toggle($content->id);

        return $this->ok([
            'liked' => count($res['attached']) > 0,
            'likes_count' => $content->likedBy()->count(),
        ]);
    }

    /** Continue-watching (most-recent first). */
    public function progress(Request $request): JsonResponse
    {
        $rows = $this->profile($request)->watchProgress()
            ->with(['content' => fn ($q) => $q->with('genres')->withCount('episodes')])
            ->whereHas('content', fn ($q) => $q->published())
            ->orderByDesc('last_watched_at')->limit(20)->get();

        $items = $rows->map(fn ($w) => [
            'content' => new ContentResource($w->content),
            'episode_id' => $w->episode_id,
            'percent' => $w->percent,
            'position_seconds' => $w->position_seconds,
        ]);

        return $this->ok(['items' => $items]);
    }

    public function saveProgress(Request $request): JsonResponse
    {
        $data = $request->validate([
            'content_id' => ['required', 'integer', 'exists:contents,id'],
            'episode_id' => ['nullable', 'integer', 'exists:episodes,id'],
            'position_seconds' => ['required', 'integer', 'min:0'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
        ]);

        $dur = $data['duration_seconds'] ?? 0;
        $percent = $dur > 0 ? min(100, max(0, (int) round($data['position_seconds'] / $dur * 100))) : 0;

        // Per-episode view (same key as web InteractionController → one dedup window across platforms).
        if (! empty($data['episode_id'])) {
            $vkey = 'view:ep:'.$data['episode_id'].':'.sha1((string) $request->ip());
            if (Cache::add($vkey, 1, now()->addHours(6))) {
                DB::table('episodes')->where('id', $data['episode_id'])->increment('views');
            }
        }

        $this->profile($request)->watchProgress()->updateOrCreate(
            ['content_id' => $data['content_id']],
            [
                'episode_id' => $data['episode_id'] ?? null,
                'percent' => $percent,
                'position_seconds' => $data['position_seconds'],
                'last_watched_at' => now(),
            ],
        );

        return $this->ok(['ok' => true]);
    }

    /**
     * Per-title state for the detail screen, Shorts and the player rail. Counts are public; a member
     * token adds their own liked / in-list / rating flags (a guest gets false / null).
     */
    public function contentState(Request $request, Content $content): JsonResponse
    {
        $profile = $request->user()?->defaultProfile();

        return $this->ok([
            'liked' => $profile ? $profile->likes()->where('content_id', $content->id)->exists() : false,
            'in_list' => $profile ? $profile->myList()->where('content_id', $content->id)->exists() : false,
            'my_rating' => $profile ? $content->ratings()->where('profile_id', $profile->id)->value('stars') : null,
            'likes_count' => $content->likedBy()->count(),
            'rating_avg' => round((float) $content->ratings()->avg('stars'), 1),
            'rating_count' => $content->ratings()->count(),
            'comments_count' => $content->comments()->count(),
        ]);
    }

    private function profile(Request $request): Profile
    {
        return $request->user()->defaultProfile();
    }

    private function ok(array $data): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data]);
    }
}
