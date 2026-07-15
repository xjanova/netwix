<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Services\AppRelease;
use Illuminate\Http\JsonResponse;

/**
 * Update manifest for the mobile app (GET /api/app/version).
 *
 * The app polls this to decide whether to offer an in-app update. It returns the
 * latest version + release notes + APK size and a download URL that points at
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
            'notes' => $this->cleanNotes((string) ($rel['notes'] ?? '')),
            'size' => (int) ($rel['size'] ?? 0),
            'url' => secure_url('/download/apk'),
        ]]);
    }

    /**
     * The release body is GitHub's, and its auto-generated changelog embeds the
     * repo URL ("**Full Changelog**: https://github.com/owner/repo/compare/..."),
     * which the app shows verbatim in its "What's New" sheet. Strip that line and
     * any github links so a customer never sees where the build actually lives.
     * An emptied body falls back to the app's generic "fixes & improvements".
     */
    private function cleanNotes(string $notes): string
    {
        $notes = (string) preg_replace('/^\s*\*{0,2}Full Changelog\*{0,2}:.*$/mi', '', $notes);
        $notes = (string) preg_replace('#https?://\S*github(usercontent)?\.com/\S*#i', '', $notes);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $notes));
    }
}
