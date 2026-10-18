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

        // Offer a build only once its APK is on our disk. The first /download/apk of a new release used
        // to fetch the ~60 MB file from GitHub inside that customer's request — far longer than the
        // app's downloader waits for a first byte — so whoever tapped "อัปเดตเลย" first got
        // "ดาวน์โหลดไฟล์ติดตั้งไม่สำเร็จ" (v1.6.3, 2026-10-04). Mirror it once this response is sent and
        // report "up to date" meanwhile; the next check offers it.
        if (! $release->isMirrored($rel)) {
            app()->terminating(fn () => $release->mirror($rel));

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
            // Exact APK bytes. v1.6.2+ divide by this for their progress bar instead of trusting
            // Content-Length, which Apache used to strip from /download/apk (see public/.htaccess).
            'size' => (int) ($rel['size'] ?? 0),
            'url' => secure_url('/download/apk'),
        ]]);
    }
}
