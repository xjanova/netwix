<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Models\Episode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Ingest bridge for the Hive Download desktop app. Because rongyok only serves fresh video URLs
 * to residential IPs, the desktop app (on the user's PC) downloads the MP4 and uploads it here;
 * we store it on our own disk and point the episode at it, so playback no longer depends on the
 * source. Authenticated with a shared token (X-Ingest-Token), not a user session.
 */
class IngestController extends Controller
{
    private function assertToken(Request $request): void
    {
        // Always answer this API in JSON (so validation errors don't redirect).
        $request->headers->set('Accept', 'application/json');

        $expected = (string) config('services.ingest.token');
        $given = (string) $request->header('X-Ingest-Token', '');
        abort_if($expected === '' , 503, 'ingest token not configured');
        abort_unless(hash_equals($expected, $given), 401, 'invalid ingest token');
    }

    /** Worklist: imported episodes that still need their video mirrored. */
    public function pending(Request $request): JsonResponse
    {
        $this->assertToken($request);
        $source = $request->query('source');

        $items = Episode::query()
            ->whereNull('mirrored_at')
            ->whereNotNull('source')
            ->when($source, fn ($q) => $q->where('source', $source))
            ->whereHas('content', fn ($q) => $q->whereNotNull('source_key'))
            ->with('content:id,source,source_key,title')
            // customer-requested episodes first (and most-requested first), then the sequential backlog.
            ->orderByRaw('mirror_requested_at IS NULL')
            ->orderByDesc('mirror_requests')
            ->orderBy('content_id')->orderBy('number')
            ->limit((int) $request->integer('limit', 500))
            ->get()
            ->map(fn (Episode $e) => [
                'episode_id' => $e->id,
                'source' => $e->content->source,
                'source_key' => $e->content->source_key,
                'number' => $e->number,
                'title' => $e->content->title,
                'requested' => (bool) $e->mirror_requested_at,
                'requests' => (int) $e->mirror_requests,
            ]);

        return response()->json(['count' => $items->count(), 'items' => $items]);
    }

    /** Receive one mirrored video file and attach it to its episode. */
    public function store(Request $request): JsonResponse
    {
        $this->assertToken($request);

        $data = $request->validate([
            'source' => ['required', 'in:rongyok,wowdrama'],
            'source_key' => ['required', 'string', 'max:64'],
            'number' => ['required', 'integer', 'between:1,9999'],
            'file' => ['required', 'file', 'mimetypes:video/mp4,application/octet-stream', 'max:716800'], // 700 MB
        ]);

        // Storage-cap guard — protect the shared server disk.
        $usedBytes = (int) Episode::sum('file_size');
        $maxBytes = (float) config('services.ingest.max_gb') * 1_000_000_000;
        abort_if($usedBytes >= $maxBytes, 507, 'media storage cap reached ('.config('services.ingest.max_gb').' GB)');
        $free = @disk_free_space(storage_path());
        abort_if($free !== false && $free < 5_000_000_000, 507, 'server disk nearly full');

        $content = Content::where('source', $data['source'])->where('source_key', $data['source_key'])->first();
        abort_unless($content, 404, 'content not imported yet');
        $episode = $content->episodes()->where('number', $data['number'])->first();
        abort_unless($episode, 404, 'episode not found');

        $dir = "media/{$data['source']}/{$data['source_key']}";
        $name = $data['number'].'.mp4';
        Storage::disk('public')->putFileAs($dir, $request->file('file'), $name);
        $path = "{$dir}/{$name}";

        $episode->update([
            'video_url' => Storage::disk('public')->url($path),
            'mirrored_at' => now(),
            'file_size' => Storage::disk('public')->size($path),
            'mirror_trigger' => $episode->mirror_requested_at ? 'customer' : 'admin',
        ]);

        return response()->json([
            'ok' => true,
            'episode_id' => $episode->id,
            'url' => $episode->video_url,
            'used_gb' => round(((int) Episode::sum('file_size')) / 1e9, 2),
            'cap_gb' => (float) config('services.ingest.max_gb'),
        ]);
    }
}
