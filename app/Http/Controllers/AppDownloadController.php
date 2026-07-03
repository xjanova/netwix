<?php

namespace App\Http\Controllers;

use App\Services\AppRelease;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AppDownloadController extends Controller
{
    /**
     * Serve the latest APK from our own domain — the customer never touches
     * GitHub. The binary is mirrored to (private) local storage once per version
     * and then streamed on every download, so we hit GitHub at most once/release.
     */
    public function apk(AppRelease $release): BinaryFileResponse
    {
        $rel = $release->latest();
        abort_unless($rel && $rel['apk_url'] !== '', 404);

        $version = preg_replace('/[^A-Za-z0-9._-]/', '', $rel['version'] ?: 'latest');
        $rel_path = "app/netwix-{$version}.apk";
        $disk = Storage::disk('local');

        if (! $disk->exists($rel_path)) {
            $tmp = tempnam(sys_get_temp_dir(), 'nxapk');
            try {
                // browser_download_url is public + redirects to GitHub's CDN; fetch without
                // our GitHub auth header so the CDN redirect isn't rejected.
                $resp = Http::withHeaders(['User-Agent' => 'NetWix-App-Download'])
                    ->timeout(300)->sink($tmp)->get($rel['apk_url']);

                abort_unless($resp->ok() && (int) (@filesize($tmp) ?: 0) > 100_000, 502);
                $disk->putFileAs('app', new File($tmp), "netwix-{$version}.apk");
            } finally {
                @unlink($tmp);
            }
        }

        return response()->download($disk->path($rel_path), "NetWix-{$version}.apk", [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]);
    }
}
