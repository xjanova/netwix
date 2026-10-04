<?php

namespace App\Http\Controllers;

use App\Models\Episode;
use App\Support\RongYokClientResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Explicit admin pairing: a phone helps only its signed-in owner's requested episode. */
class ResolveAssistController extends Controller
{
    public function create(Request $request, Episode $episode)
    {
        $descriptor = RongYokClientResolver::forEpisode($episode);
        abort_unless($descriptor, 422, 'ตอนนี้ยังไม่รองรับการขอผ่านอุปกรณ์');
        $code = strtoupper(bin2hex(random_bytes(8)));
        $job = ['owner' => $request->user()->id, 'episode_id' => $episode->id, 'descriptor' => $descriptor, 'url' => null, 'expires' => time() + 300];
        Cache::put('resolve-assist:'.$code, $job, 300);

        return response()->json(['code' => $code, 'descriptor' => $descriptor, 'expires_in' => 300,
            'status_url' => route('admin.resolve-assist.status', $code), 'submit_url' => route('admin.resolve-assist.submit', $code)]);
    }

    private function job(Request $request, string $code): array
    {
        abort_unless(preg_match('/^[A-F0-9]{16}$/D', $code), 404);
        $job = Cache::get('resolve-assist:'.$code);
        abort_unless($job && $job['expires'] > time(), 410, 'รหัสหมดอายุ กรุณาขอใหม่');
        abort_unless((int) $job['owner'] === (int) $request->user()->id, 403);

        return $job;
    }

    public function status(Request $request, string $code)
    {
        $job = $this->job($request, $code);

        return response()->json(['success' => true, 'data' => ['ready' => $job['url'] !== null,
            'kind' => 'mp4', 'url' => $job['url'], 'client_resolve' => $job['descriptor']]]);
    }

    public function submit(Request $request, string $code)
    {
        $job = $this->job($request, $code);
        abort_if($job['url'] !== null, 409, 'งานนี้เสร็จแล้ว');
        $data = $request->validate(['video_url' => ['required', 'string', 'max:2048']]);
        abort_unless(RongYokClientResolver::accept($job['descriptor'], $data['video_url']), 422, 'ลิงก์หมดอายุหรือไม่ใช่วิดีโอที่รองรับ');
        $job['url'] = $data['video_url'];
        Cache::put('resolve-assist:'.$code, $job, max(1, $job['expires'] - time()));

        return response()->json(['success' => true, 'data' => ['ready' => true, 'kind' => 'mp4', 'url' => $job['url']]]);
    }
}
