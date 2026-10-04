<?php

namespace App\Support;

use App\Models\Episode;
use App\Models\Setting;
use App\Services\Import\RemoteStream;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class RongYokClientResolver
{
    public static function enabled(): bool
    {
        return Setting::flag('rongyok_client_fallback', (bool) config('services.rongyok.client_fallback', true));
    }

    public static function proxyUrl(): string
    {
        $configured = trim((string) Setting::get('rongyok_proxy_url', config('services.rongyok.proxy_url', '')));

        return $configured !== '' ? $configured : RongYokProxyPool::selected();
    }

    /** Only identifiers are handed to clients, never arbitrary destinations or proxy credentials. */
    public static function descriptor(string $key, string $ref): ?array
    {
        if (! self::enabled() || ! preg_match('/^\d{1,20}$/D', $key) || ! preg_match('/^[1-9]\d{0,3}$/D', $ref)) {
            return null;
        }
        $endpoint = (string) Cache::get('rongyok:video_endpoint', 'playseries.php');
        if (! preg_match('/^[a-z0-9_]{4,64}\.php$/iD', $endpoint)) {
            $endpoint = 'playseries.php';
        }

        return ['source' => 'rongyok', 'series_id' => $key, 'episode' => $ref, 'endpoint' => $endpoint];
    }

    public static function forEpisode(Episode $ep): ?array
    {
        if ($ep->source !== 'rongyok' || $ep->content?->source !== 'rongyok') {
            return null;
        }

        return self::descriptor((string) $ep->content->source_key, (string) $ep->source_ref);
    }

    public static function validVideoUrl(string $url): bool
    {
        if (strlen($url) > 2048 || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $p = parse_url($url);
        if (($p['scheme'] ?? '') !== 'https' || ! in_array($p['host'] ?? '', ['cdn.discordapp.com', 'media.discordapp.net'], true)
            || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || (isset($p['port']) && $p['port'] !== 443)
            || ! preg_match('~^/attachments/\d+/\d+/[^/]+\.mp4$~iD', $p['path'] ?? '')) {
            return false;
        }
        parse_str($p['query'] ?? '', $q);

        return isset($q['ex'], $q['is'], $q['hm']) && is_string($q['ex']) && preg_match('/^[a-f0-9]{1,12}$/iD', $q['ex'])
            && is_string($q['is']) && is_string($q['hm']) && $q['is'] !== '' && $q['hm'] !== ''
            && hexdec($q['ex']) > time() + 300 && hexdec($q['ex']) <= time() + 172800;
    }

    private static function cacheKey(string $key, string $ref): string
    {
        return 'rongyok:assisted:'.hash('sha256', $key.':'.$ref);
    }

    public static function cached(string $key, string $ref): ?RemoteStream
    {
        $url = Cache::get(self::cacheKey($key, $ref));

        return is_string($url) && self::validVideoUrl($url) ? new RemoteStream(RemoteStream::KIND_MP4, $url) : null;
    }

    /** Only an authenticated owning admin can reach this via the assist controller. */
    public static function accept(array $descriptor, string $url): bool
    {
        if (! self::validVideoUrl($url)) {
            return false;
        }
        try {
            $r = Http::withHeaders(['Range' => 'bytes=0-1023'])->withOptions(['allow_redirects' => false, 'stream' => true])
                ->connectTimeout(5)->timeout(10)->get($url);
            $body = $r->toPsrResponse()->getBody();
            $prefix = $body->read(1024);
            $body->close();
            if (! in_array($r->status(), [200, 206], true) || ! str_starts_with(strtolower($r->header('Content-Type')), 'video/mp4')
                || substr($prefix, 4, 4) !== 'ftyp') {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $ttl = min(3600, (int) hexdec($q['ex']) - time() - 300);
        Cache::put(self::cacheKey($descriptor['series_id'], $descriptor['episode']), $url, $ttl);

        return true;
    }
}
