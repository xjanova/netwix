<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\Mission;
use App\Models\MissionAttempt;
use App\Services\Membership;
use App\Services\MissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Missions API for the mobile app — the same watch-to-earn missions as the web
 * /missions page, driven by the same MissionService anti-cheat (start token +
 * wall-clock-validated heartbeats). The app sends a beat every ~15s ONLY while
 * the video is playing and the app is in the foreground.
 */
class MissionController extends Controller
{
    public function __construct(private MissionService $missions, private Membership $m) {}

    /** Auth: active missions + this member's status/progress for each. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $watched = MissionAttempt::where('user_id', $user->id)->pluck('watched_seconds', 'mission_id');

        $items = Mission::active()->get()->map(fn (Mission $mis) => [
            'id' => $mis->id,
            'title' => $mis->title,
            'description' => $mis->description,
            'video_source' => $mis->video_source,      // youtube | url
            'video_ref' => $mis->playRef(),            // YT id / direct stream URL
            'poster' => $mis->poster,
            'required_seconds' => (int) $mis->required_seconds,
            'reward_kind' => $mis->reward_kind,        // silver | gold
            'reward_amount' => (int) $mis->reward_amount,
            'reward_label' => $mis->rewardLabel(),
            'repeat' => $mis->repeat,                  // once | daily
            'status' => $this->missions->statusFor($user, $mis),
            'watched' => (int) ($watched[$mis->id] ?? 0),
        ])->values();

        return response()->json(['success' => true, 'data' => ['items' => $items]]);
    }

    /** Auth: begin/restart a watch attempt → the token that binds the heartbeats. */
    public function start(Request $request, Mission $mission): JsonResponse
    {
        abort_unless($mission->is_active, 404);
        $res = $this->missions->start($request->user(), $mission);

        return response()->json([
            'success' => $res['ok'],
            'error' => $res['error'] ?? null,
            'data' => $res,
        ], $res['ok'] ? 200 : 422);
    }

    /** Auth: heartbeat. On completion the reward + fresh membership state come back. */
    public function beat(Request $request, Mission $mission): JsonResponse
    {
        abort_unless($mission->is_active, 404);
        $data = $request->validate(['token' => ['required', 'string', 'max:40']]);

        $res = $this->missions->beat($request->user(), $mission, $data['token']);

        // A completed mission just changed a balance — hand back the fresh membership
        // state so the app updates coins everywhere without a second round-trip.
        if (($res['done'] ?? false) === true) {
            $res['membership'] = $this->m->state($request->user()->refresh());
        }

        return response()->json([
            'success' => (bool) ($res['ok'] ?? false),
            'error' => $res['error'] ?? null,
            'data' => $res,
        ], ($res['ok'] ?? false) ? 200 : 422);
    }
}
