<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Support\PosterBackfill;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * On-demand cover healing. A card whose poster fails to load pings this (the browser is the judge of
 * "ปกโหลดไม่ได้" — owner 2026-07-16: "ผู้ใช้เรียกมันจะรู้อยู่แล้วว่าเรียกได้ไหม ซ่อมตอนนั้นเลย ไม่ต้อง
 * กวาด"). We re-fetch the cover from the source and store it locally right then, and hand back the new
 * URL so the card can swap it in live. Whatever can't be recovered keeps showing the branded fallback.
 *
 * Cheap + bounded: a per-title 6h lock dedups a burst of viewers on the same broken card AND stops a
 * title that genuinely has no source poster from being retried on every view; the route is throttled
 * per IP on top. Image re-fetch is a light HTTP GET + WebP encode (no ffmpeg), so running it inline is
 * safe — no queue needed.
 */
class PosterHealController extends Controller
{
    public function heal(Content $content, PosterBackfill $backfill): JsonResponse
    {
        // Already a locally-stored (permanent) cover → just hand it back (a stale client that errored
        // on an old <img> gets the good URL to swap in).
        if (str_starts_with((string) $content->poster_path, 'media/')) {
            return response()->json(['ok' => true, 'url' => $content->poster_url]);
        }

        // One heal attempt per title per cooldown window — set BEFORE the work so concurrent viewers
        // don't each re-scrape the source, and a hopeless title isn't retried on every single view.
        if (! Cache::add('cover:heal:'.$content->id, 1, now()->addHours(6))) {
            return response()->json(['ok' => true, 'url' => null, 'status' => 'cooldown']);
        }

        $path = $backfill->recover($content);
        if ($path === null) {
            return response()->json(['ok' => true, 'url' => null]);   // no source poster → fallback stays
        }

        $updates = ['poster_path' => $path];
        if (blank($content->backdrop_path)) {
            $updates['backdrop_path'] = $path;
        }
        $content->forceFill($updates)->save();

        return response()->json(['ok' => true, 'url' => $content->poster_url]);
    }
}
