<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

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
