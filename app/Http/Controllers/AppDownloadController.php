<?php

namespace App\Http\Controllers;

use App\Models\AppDownload;
use App\Services\AppRelease;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AppDownloadController extends Controller
{
    /**
     * Serve the latest APK from our own domain — the customer never touches
     * GitHub. The binary is mirrored to (private) local storage once per version
     * and then streamed on every download, so we hit GitHub at most once/release.
     */
    public function apk(Request $request, AppRelease $release): BinaryFileResponse
    {
        $rel = $release->latest();
        abort_unless($rel && $rel['apk_url'] !== '', 404);

        $version = preg_replace('/[^A-Za-z0-9._-]/', '', $rel['version'] ?: 'latest');
        $rel_path = AppRelease::apkPath($rel);
        $disk = Storage::disk('local');

        // Normally already on disk: the app is only offered a release once it is (see
        // ReleaseController::version). This covers the website's download button, which can be first.
        abort_unless($release->mirror($rel, wait: 120), 502);

        // Count it only once we know we're actually handing over the file.
        AppDownload::record($request, $version);

        // The app's updater draws its progress bar from Content-Length alone, so the file must reach it
        // exactly as stored. no-transform keeps Cloudflare from re-compressing it; Apache's half (gzip +
        // dropping the length PHP sends) is switched off for this path in public/.htaccess.
        return response()->download($disk->path($rel_path), "NetWix-{$version}.apk", [
            'Content-Type' => 'application/vnd.android.package-archive',
            'Cache-Control' => 'public, no-transform',
        ]);
    }
}
