<?php

namespace App\Support;

use App\Models\Content;
use App\Models\Setting;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Public proxies are admitted only after a catalogue source + MP4 canary succeeds. */
final class RongYokProxyPool
{
    public const LIST_URL = 'https://raw.githubusercontent.com/ProxyScrape/free-proxy-list/main/proxies/protocols/https/data.json';

    private const HEALTHY = 'rongyok:proxy:healthy';

    public static function enabled(): bool
    {
        return Setting::flag('rongyok_free_proxy_auto', (bool) config('services.rongyok.free_proxy_auto', true));
    }

    public static function validProxy(string $value): bool
    {
        if (! preg_match('~^http://([0-9.]+):([0-9]{1,5})$~D', $value, $m)) {
            return false;
        }

        return filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            && (int) $m[2] >= 1 && (int) $m[2] <= 65535;
    }

    private static function badKey(string $proxy): string
    {
        return 'rongyok:proxy:bad:'.hash('sha256', $proxy);
    }

    public static function selected(): string
    {
        if (! self::enabled()) {
            return '';
        }
        foreach ((array) Cache::get(self::HEALTHY, []) as $proxy) {
            if (is_string($proxy) && self::validProxy($proxy) && ! Cache::has(self::badKey($proxy))) {
                return $proxy;
            }
        }

        return '';
    }

    public static function failed(string $proxy): void
    {
        if (self::validProxy($proxy) && in_array($proxy, (array) Cache::get(self::HEALTHY, []), true)) {
            Cache::put(self::badKey($proxy), true, 600);
        }
    }

    /** Background only: at most 8 proxies and 3 catalogue canaries per scan. */
    public static function refresh(): int
    {
        if (! self::enabled()) {
            return 0;
        }
        // Also covers manual checks: scheduler's overlap guard alone cannot protect those.
        $lock = Cache::lock('rongyok:proxy:refresh', 180);
        if (! $lock->get()) {
            return count(self::available());
        }
        try {
            return self::refreshPool();
        } finally {
            $lock->release();
        }
    }

    private static function available(): array
    {
        return array_values(array_filter((array) Cache::get(self::HEALTHY, []), fn ($p) => is_string($p) && self::validProxy($p) && ! Cache::has(self::badKey($p))));
    }

    private static function refreshPool(): int
    {
        $candidates = self::available();
        $list = Cache::get('rongyok:proxy:list');
        if (! is_array($list)) {
            try {
                $r = Http::connectTimeout(3)->timeout(8)->withOptions(['allow_redirects' => false])->get(self::LIST_URL);
                $list = $r->ok() ? $r->json() : [];
                if ($r->ok() && is_array($list)) {
                    Cache::put('rongyok:proxy:list', $list, 300);
                }
            } catch (\Throwable) {
                $list = []; // Recheck admitted proxies if the provider is unavailable.
            }
        }
        if (is_array($list)) {
            shuffle($list);
            // Prefer common web ports, which can work with restricted outbound firewalls.
            usort($list, fn ($a, $b) => (in_array($b['port'] ?? 0, [80, 443], true) ? 1 : 0) <=> (in_array($a['port'] ?? 0, [80, 443], true) ? 1 : 0));
            foreach ($list as $item) {
                if (! is_array($item) || ($item['protocol'] ?? '') !== 'http' || ($item['ssl'] ?? false) !== true
                    || (float) ($item['last_checked'] ?? 0) < time() - 1800) {
                    continue;
                }
                $proxy = 'http://'.($item['ip'] ?? '').':'.($item['port'] ?? '');
                if (self::validProxy($proxy) && ! Cache::has(self::badKey($proxy))) {
                    $candidates[] = $proxy;
                }
                if (count(array_unique($candidates)) >= 8) {
                    break;
                }
            }
        }
        $candidates = array_slice(array_values(array_unique($candidates)), 0, 8);
        $endpoint = (string) Cache::get('rongyok:video_endpoint', 'playseries.php');
        if (! preg_match('/^[a-z0-9_]{4,64}\.php$/iD', $endpoint)) {
            $endpoint = 'playseries.php';
        }
        // Choose from actual published catalogue entries, not a permanently hardcoded title.
        $canaries = Content::query()->where('source', 'rongyok')->where('is_published', true)
            ->whereHas('episodes', fn ($q) => $q->where('source', 'rongyok')->where('source_ref', '1'))
            ->orderByDesc('id')->limit(30)->pluck('source_key')->unique()
            ->filter(fn ($key) => is_string($key) && preg_match('/^\d{1,20}$/D', $key))
            ->shuffle()->take(3)->values()->all();
        $healthy = [];
        $verifiedVideos = [];
        $pending = $candidates;
        $definiteFailures = 0;
        $tested = [];
        $canariesChecked = 0;
        foreach ($canaries as $seriesId) {
            if (! $pending) {
                break;
            }
            $canariesChecked++;
            foreach ($pending as $proxy) {
                $tested[$proxy] = true;
            }
            $responses = Http::pool(function (Pool $pool) use ($pending, $endpoint, $seriesId) {
                $requests = [];
                foreach ($pending as $i => $proxy) {
                    $requests[] = $pool->as((string) $i)->withHeaders([
                        'Accept' => 'application/json',
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36',
                        'Referer' => 'https://rongyok.com/watch/?series_id='.$seriesId.'&ep=1',
                    ])->connectTimeout(3)->timeout(7)->withOptions(['proxy' => $proxy, 'allow_redirects' => false,
                        'curl' => [CURLOPT_SSL_ENABLE_ALPN => false]])
                        ->get('https://rongyok.com/watch/'.$endpoint, ['series_id' => $seriesId, 'ep' => '1']);
                }

                return $requests;
            });
            $next = [];
            foreach ($pending as $i => $proxy) {
                $r = $responses[$i] ?? null;
                $data = $r instanceof Response && $r->ok() ? $r->json() : null;
                $url = is_array($data) ? ($data['video_url'] ?? null) : null;
                if (is_array($data) && in_array($data['ok'] ?? null, [true, 'true'], true) && is_string($url)
                    && ($verifiedVideos[$seriesId.':'.$url] ??= RongYokClientResolver::accept(['series_id' => $seriesId, 'episode' => '1'], $url))) {
                    $healthy[] = $proxy;
                } elseif (! ($r instanceof Response) || (! $r->successful() && ! in_array($r->status(), [404, 410], true))) {
                    Cache::put(self::badKey($proxy), true, 600);
                    $definiteFailures++;
                } else {
                    // Missing content, an obsolete endpoint, or a CDN failure proves nothing
                    // about the proxy. Try another title, without putting the proxy on cooldown.
                    $next[] = $proxy;
                }
            }
            $pending = $next;
        }
        // A real playback failure during this check wins over its earlier successful canary.
        $healthy = array_values(array_filter($healthy, fn ($p) => ! Cache::has(self::badKey($p))));
        $inconclusive = ! $healthy && $definiteFailures === 0 && count($candidates) > 0;
        if (! $inconclusive) {
            Cache::put(self::HEALTHY, $healthy, 900);
        }
        // An inconclusive canary must not erase or extend previously verified entries.
        // They keep their original expiry and still honour live-playback failure cooldowns.
        $available = $inconclusive ? count(self::available()) : count($healthy);
        if ($healthy) {
            Cache::put('rongyok:video_endpoint', $endpoint, 3600);
        }
        Cache::put('rongyok:proxy:last_check', ['at' => now()->toIso8601String(), 'tested' => count($tested),
            'healthy' => $available, 'verified' => count($healthy), 'canaries' => $canariesChecked,
            'state' => $inconclusive ? 'inconclusive' : 'checked'], 86400);

        return $available;
    }
}
