<?php

namespace App\Http\Controllers;

use App\Models\Mission;
use App\Services\MissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Member missions ("ภารกิจ") — list, start a watch attempt, heartbeat to earn. Anti-cheat in MissionService. */
class MissionController extends Controller
{
    public function __construct(private MissionService $missions) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $missions = Mission::active()->get()->map(fn (Mission $m) => [
            'model' => $m,
            'status' => $this->missions->statusFor($user, $m),
        ]);

        return view('frontend.missions', ['missions' => $missions]);
    }

    public function start(Request $request, Mission $mission): JsonResponse
    {
        abort_unless($mission->is_active, 404);

        return response()->json($this->missions->start($request->user(), $mission));
    }

    public function beat(Request $request, Mission $mission): JsonResponse
    {
        abort_unless($mission->is_active, 404);
        $data = $request->validate(['token' => ['required', 'string', 'max:40']]);

        return response()->json($this->missions->beat($request->user(), $mission, $data['token']));
    }
}
