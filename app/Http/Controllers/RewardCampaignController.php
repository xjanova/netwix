<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Models\Episode;
use App\Services\Membership;
use App\Services\RewardCampaigns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Both session-authenticated web and token-authenticated app call this identical contract. */
class RewardCampaignController extends Controller
{
    public function index(Request $request, RewardCampaigns $campaigns): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['items' => $campaigns->state($request->user()), 'timezone' => RewardCampaigns::TIMEZONE]]);
    }

    public function start(Request $request, RewardCampaigns $campaigns): JsonResponse
    {
        $data = $request->validate(['content_id' => ['required', 'integer'], 'episode_id' => ['nullable', 'integer'], 'position' => ['required', 'numeric', 'between:0,604800']]);
        $content = Content::findOrFail($data['content_id']);
        $episode = isset($data['episode_id']) ? Episode::findOrFail($data['episode_id']) : null;

        return response()->json(['success' => true, 'data' => $campaigns->startWatch($request->user(), $content, $episode, (float) $data['position'])]);
    }

    public function beat(Request $request, RewardCampaigns $campaigns): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:48'], 'position' => ['required', 'numeric', 'between:0,604800']]);
        $result = $campaigns->watchBeat($request->user(), $data['token'], (float) $data['position']);

        return response()->json(['success' => $result['ok'], 'data' => $result], $result['ok'] ? 200 : 409);
    }

    public function earn(Request $request, Membership $membership): JsonResponse
    {
        $data = $request->validate(['kind' => ['required', 'in:daily,share']]);
        $result = $membership->earn($request->user(), $data['kind']);

        return response()->json(['success' => $result['ok'], 'error' => $result['error'] ?? null, 'data' => $membership->state($request->user()->refresh())], $result['ok'] ? 200 : 422);
    }
}
