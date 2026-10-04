<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Services\AppRelease;
use Illuminate\Http\JsonResponse;

/**
 * Update manifest for the mobile app (GET /api/app/version).
 *
 * The app polls this to decide whether to offer an in-app update. It returns the
 * latest version + APK size and a download URL that points at
 * OUR domain (/download/apk). The real build origin (GitHub's browser_download_url)
 * is resolved server-side by {@see AppRelease} and is NEVER exposed here, so the
 * app neither contacts nor reveals GitHub — the whole update flow lives on
 * netwix.online. {@see AppDownloadController} mirrors + streams the binary that
 * backs the download URL.
 *
 * Public + cacheable: AppRelease::latest() memoises for 30 min, so this is cheap
 * to poll and works for guests / fresh installs (no auth token yet).
 */
class ReleaseController extends Controller
{
    public function version(AppRelease $release): JsonResponse
    {
        $rel = $release->latest();

        // No release configured / GitHub unreachable → "nothing to report".
        // The app treats a null payload as "you're up to date".
        if (! $rel || ($rel['apk_url'] ?? '') === '') {
            return response()->json(['success' => true, 'data' => null]);
        }

        $tag = (string) ($rel['version'] ?? '');                        // e.g. "v1.4.0"
        $clean = ltrim((string) preg_replace('/[-+].*$/', '', $tag), 'vV'); // "1.4.0"

        return response()->json(['success' => true, 'data' => [
            'version' => $clean,
            'tag' => $tag,
            // Always empty: customers must not see what changed in a release (owner: อย่าให้เห็น
            // รายละเอียดการอัพเดท). The key stays because every shipped build reads it — an empty
            // value makes them fall back to a generic "fixes & improvements" line.
            'notes' => '',
            // Exact APK bytes. The app divides by this for its progress bar, because Cloudflare
            // drops Content-Length on /download/apk and the download plugin can't count without it.
            'size' => (int) ($rel['size'] ?? 0),
            'url' => secure_url('/download/apk'),
        ]]);
    }
}
