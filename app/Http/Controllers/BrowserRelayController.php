<?php

namespace App\Http\Controllers;

use App\Support\BrowserRelay;
use App\Support\PosterRelay;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BrowserRelayController extends Controller
{
    public function claim(Request $request)
    {
        $job = BrowserRelay::claim();
        if (!$job && $request->boolean('posters')) {
            $job = PosterRelay::claim();
        }

        return response()->json(['job' => $job, 'poll_after' => 5])->header('Cache-Control', 'no-store');
    }

    public function posterComplete(Request $request, int $job)
    {
        $data = $request->validate([
            'lease_token' => ['required', 'string', 'size:64'],
            'image' => ['required', 'string', 'max:5400000'],
            'image_url' => ['required', 'string', 'max:2048'],
            'episodes_count' => ['required', 'integer', 'between:1,1000'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        abort_unless(PosterRelay::complete($job, $data['lease_token'], $data['image'], $data['image_url'],
            $data['episodes_count'], $data['description'] ?? null), 422, 'Invalid poster');

        return response()->json(['ok' => true]);
    }

    public function posterFail(Request $request, int $job)
    {
        $data = $request->validate(['lease_token' => ['required', 'string', 'size:64'],
            'error' => ['required', Rule::in(['source_blocked', 'timeout', 'no_video', 'browser_unavailable', 'invalid_url', 'urban_not_connected'])]]);
        PosterRelay::fail($job, $data['lease_token'], $data['error']);

        return response()->json(['ok' => true]);
    }

    public function complete(Request $request, int $job)
    {
        $data = $request->validate([
            'lease_token' => ['required', 'string', 'size:64'],
            'video_url' => ['required', 'string', 'max:2048'],
            'extras' => ['sometimes', 'array', 'max:'.BrowserRelay::MAX_EXTRAS],
            'extras.*.episode' => ['required', 'string', 'regex:/^[1-9]\d{0,3}$/D'],
            'extras.*.video_url' => ['required', 'string', 'max:2048'],
        ]);
        $extras = BrowserRelay::complete($job, $data['lease_token'], $data['video_url'], $data['extras'] ?? []);
        abort_if($extras === false, 422, 'Invalid or unreachable video');

        return response()->json(['ok' => true, 'extras_accepted' => $extras]);
    }

    public function fail(Request $request, int $job)
    {
        $data = $request->validate([
            'lease_token' => ['required', 'string', 'size:64'],
            'error' => ['required', Rule::in(['source_blocked', 'timeout', 'no_video', 'browser_unavailable', 'invalid_url', 'urban_not_connected'])],
        ]);
        BrowserRelay::fail($job, $data['lease_token'], $data['error']);

        return response()->json(['ok' => true]);
    }
}
