<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Reads the latest Android release straight from a GitHub repo's Releases so the
 * admin only has to publish a release with the .apk attached — the download page
 * then auto-updates. Customers download from our own domain (see
 * AppDownloadController), never from GitHub.
 */
class AppRelease
{
    /**
     * @return array{version:string,name:string,notes:string,apk_url:string,apk_name:string,size:int,published_at:?string}|null
     */
    public function latest(): ?array
    {
        $repo = self::repo();
        if (! $repo) {
            return null;
        }

        return Cache::remember("app:release:{$repo}", now()->addMinutes(30), function () use ($repo) {
            $res = $this->client()->get("https://api.github.com/repos/{$repo}/releases/latest");
            if (! $res->ok()) {
                return null;
            }

            $r = $res->json();
            $asset = collect($r['assets'] ?? [])
                ->first(fn ($a) => str_ends_with(strtolower((string) ($a['name'] ?? '')), '.apk'));
            if (! $asset) {
                return null;
            }

            return [
                'version' => (string) ($r['tag_name'] ?? ''),
                'name' => (string) (($r['name'] ?? '') ?: ($r['tag_name'] ?? 'NetWix')),
                'notes' => (string) ($r['body'] ?? ''),
                'apk_url' => (string) ($asset['browser_download_url'] ?? ''),
                'apk_name' => (string) ($asset['name'] ?? 'netwix.apk'),
                'size' => (int) ($asset['size'] ?? 0),
                'published_at' => $r['published_at'] ?? null,
            ];
        });
    }

    /** Where a release's APK is kept on the local disk once mirrored. */
    public static function apkPath(array $rel): string
    {
        $version = preg_replace('/[^A-Za-z0-9._-]/', '', $rel['version'] ?: 'latest');

        return "app/netwix-{$version}.apk";
    }

    /** Whether the release's APK is already on our disk, so /download/apk can start sending at once. */
    public function isMirrored(array $rel): bool
    {
        return Storage::disk('local')->exists(self::apkPath($rel));
    }

    /**
     * Copy the release's APK from GitHub to (private) local storage, once per version, so every download
     * after that streams from our disk and GitHub is hit at most once per release.
     *
     * Locked: every app that checks for updates can trigger this, and two copies of a 60 MB download
     * racing to the same path helps no one. A caller that finds it locked gets false straight away, or
     * waits up to $wait seconds for the other copy to land. After a failed copy it rests for five
     * minutes: a check for updates would otherwise start another 60 MB download every time it is asked.
     */
    public function mirror(array $rel, int $wait = 0): bool
    {
        $path = self::apkPath($rel);
        $disk = Storage::disk('local');
        if ($disk->exists($path) || ($rel['apk_url'] ?? '') === '') {
            return $disk->exists($path);
        }

        $failed = 'app:apk-mirror-failed:'.$path;
        if (Cache::has($failed)) {
            return false;
        }

        $lock = Cache::lock('app:apk-mirror:'.$path, 600);
        try {
            if (! ($wait > 0 ? $lock->block($wait) : $lock->get())) {
                return false;
            }
        } catch (LockTimeoutException) {
            return $disk->exists($path);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'nxapk');
        try {
            if ($disk->exists($path)) {
                return true; // the copy we waited on landed
            }

            // browser_download_url is public + redirects to GitHub's CDN; fetch without our GitHub auth
            // header so the CDN redirect isn't rejected.
            $resp = Http::withHeaders(['User-Agent' => 'NetWix-App-Download'])
                ->timeout(300)->sink($tmp)->get($rel['apk_url']);

            // The exact size GitHub reports, not just "big enough": a cut-off copy would otherwise be
            // served, and fail to install, for as long as that version is current.
            $got = (int) (@filesize($tmp) ?: 0);
            $expected = (int) ($rel['size'] ?? 0);
            if (! $resp->ok() || $got <= 100_000 || ($expected > 0 && $got !== $expected)) {
                Log::warning('app release: APK mirror rejected', [
                    'version' => $rel['version'] ?? '', 'status' => $resp->status(), 'bytes' => $got, 'expected' => $expected,
                ]);
                Cache::put($failed, true, now()->addMinutes(5));

                return false;
            }

            $disk->putFileAs('app', new File($tmp), basename($path));

            return true;
        } catch (\Throwable $e) {
            Log::warning('app release: APK mirror failed', ['version' => $rel['version'] ?? '', 'error' => $e->getMessage()]);
            Cache::put($failed, true, now()->addMinutes(5));

            return false;
        } finally {
            @unlink($tmp);
            $lock->release();
        }
    }

    /** Configured "owner/repo", or null. */
    public static function repo(): ?string
    {
        $repo = trim((string) Setting::get('app_github_repo', ''));

        return $repo !== '' ? $repo : null;
    }

    /** Drop the cached release so a settings change / new release shows immediately. */
    public static function forget(): void
    {
        if ($repo = self::repo()) {
            Cache::forget("app:release:{$repo}");
        }
    }

    /** GitHub API client, authenticated when a token is configured (private repos / rate limit). */
    public function client(): PendingRequest
    {
        $token = Setting::get('app_github_token');

        return Http::withHeaders(array_filter([
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'NetWix-App-Download',
            'X-GitHub-Api-Version' => '2022-11-28',
            'Authorization' => $token ? "Bearer {$token}" : null,
        ]))->timeout(15);
    }
}
