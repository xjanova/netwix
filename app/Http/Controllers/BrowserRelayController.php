<?php

namespace App\Http\Controllers;

use App\Support\BrowserRelay;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BrowserRelayController extends Controller
{
    public function claim()
    {
        return response()->json(['job' => BrowserRelay::claim(), 'poll_after' => 5])->header('Cache-Control', 'no-store');
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
