<?php

namespace App\Support;

use App\Models\BlockedIp;
use App\Models\SecurityEvent;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Watches for scraping-shaped behaviour on the endpoints that carry our content, records what it
 * sees, and — once switched to enforcing — refuses the clients that keep earning it.
 *
 * WHY BEHAVIOUR AND NOT IDENTITY. Blocking by name only works on clients that tell the truth about
 * who they are, which is precisely the set that was never the problem. Everything we did to rongyok
 * this week — walking its catalogue, hunting its endpoints, pulling its images — came from an
 * ordinary residential address with an ordinary browser User-Agent, and no identity rule anywhere
 * would have caught it. What gives a scraper away is the SHAPE of its traffic: too fast, too
 * ordered, JSON without the page it belongs to, stream tokens minted faster than anyone can watch.
 *
 * SAFETY FIRST, ON PURPOSE. It ships in `observe` mode: every rule runs and every observation is
 * recorded, but nothing is ever refused. A false block costs us a paying viewer who cannot watch,
 * which is worse than the scraping it prevents — so the numbers get read from real traffic before
 * `enforce` is switched on. The mobile app is exempted explicitly, because it legitimately sends no
 * Referer and would otherwise look exactly like a scraper.
 *
 * CHEAP BY CONSTRUCTION. Counting happens in the cache, never the database, so an ordinary request
 * costs a couple of cache increments. Rows are only written when a rule actually trips, which means
 * a normal viewer never appears in the table at all.
 */
class ScrapeGuard
{
    /** Requests to content endpoints per minute, per address, before it looks automated. */
    private const RATE_PER_MIN = 90;

    /** Stream-token mints per minute. Nobody starts 25 films a minute; a harvester does. */
    private const TOKEN_PER_MIN = 25;

    /** Consecutive ascending ids before "walking the catalogue" is the only explanation. */
    private const SEQUENTIAL_RUN = 12;

    /** Score at which a client is blocked (when enforcing). Roughly "tripped a rule repeatedly". */
    private const BLOCK_SCORE = 60;

    /** How long an automatic block lasts. Long enough to be expensive, short enough to be wrong. */
    private const BLOCK_HOURS = 6;

    /** Sliding window the score is accumulated over. */
    private const SCORE_WINDOW_MIN = 30;

    /**
     * Crawlers that identify themselves. Blocking these is free and has no false-positive risk — but
     * it is also the weakest rule here, since anyone can simply not send it. It earns a low score.
     */
    private const BOT_UA = [
        'gptbot', 'chatgpt-user', 'oai-searchbot', 'claudebot', 'claude-web', 'anthropic-ai',
        'perplexitybot', 'ccbot', 'bytespider', 'amazonbot', 'meta-externalagent', 'diffbot',
        'scrapy', 'python-requests', 'go-http-client', 'node-fetch', 'axios/', 'okhttp',
        'curl/', 'wget/', 'libwww-perl', 'httrack', 'semrushbot', 'ahrefsbot', 'dotbot', 'mj12bot',
    ];

    /** Paths worth watching — where our actual content lives. */
    private const WATCHED = ['api/', 'stream/', 'storage/media/'];

    public static function mode(): string
    {
        $m = (string) Setting::get('scrape_guard_mode', 'observe');

        return in_array($m, ['off', 'observe', 'enforce'], true) ? $m : 'observe';
    }

    public static function enforcing(): bool
    {
        return self::mode() === 'enforce';
    }

    /** True when this address is currently refused (and the refusal is counted). */
    public static function isBlocked(string $ip): bool
    {
        if (self::mode() === 'off') {
            return false;
        }

        $block = Cache::remember('guard:block:'.$ip, now()->addMinutes(2), function () use ($ip) {
            $row = BlockedIp::where('ip', $ip)->first();

            return $row && $row->active ? ['id' => $row->id] : null;
        });

        if ($block === null) {
            return false;
        }

        // Count what the block is actually stopping — an entry with thousands of hits is evidence the
        // rule caught something real; one with two is a hint it caught a person.
        BlockedIp::whereKey($block['id'])->increment('hits');

        return true;
    }

    /**
     * Inspect one request. Returns true when the caller should refuse it (enforcing + over the line).
     * Never throws: a fault in the guard must not take the site down with it.
     */
    public static function inspect(Request $request): bool
    {
        try {
            if (self::mode() === 'off' || ! self::watched($request)) {
                return false;
            }
            $ip = (string) $request->ip();
            if ($ip === '' || self::exempt($request)) {
                return false;
            }

            foreach (self::rules($request, $ip) as [$reason, $score, $meta]) {
                self::record($request, $ip, $reason, $score, $meta);
            }

            return self::enforcing() && self::isBlocked($ip);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Requests that must never be judged.
     *
     * The mobile app is the important one: it authenticates with its own token and sends no Referer,
     * so the "JSON without a page" rule would flag every single one of its calls. An admin is exempt
     * for the obvious reason that admin work looks automated — bulk imports, sweeps, previews.
     */
    private static function exempt(Request $request): bool
    {
        if ($request->attributes->has('app_token') || $request->bearerToken()) {
            return true;
        }
        $user = $request->user();
        if ($user && method_exists($user, 'isAdmin') && $user->isAdmin()) {
            return true;
        }

        // Our own server calling itself (health checks, the canary) arrives on the loopback.
        return in_array($request->ip(), ['127.0.0.1', '::1'], true);
    }

    private static function watched(Request $request): bool
    {
        $path = ltrim($request->path(), '/');
        foreach (self::WATCHED as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Run every rule and return the ones that tripped, as [reason, score, meta].
     *
     * @return array<int,array{0:string,1:int,2:array}>
     */
    private static function rules(Request $request, string $ip): array
    {
        $out = [];
        $path = ltrim($request->path(), '/');

        // 1. Volume. The cheapest, most reliable signal there is.
        $rate = self::bump('guard:rate:'.$ip, 60);
        if ($rate === self::RATE_PER_MIN || ($rate > self::RATE_PER_MIN && $rate % 50 === 0)) {
            $out[] = ['rate', 12, ['requests_in_minute' => $rate]];
        }

        // 2. Stream tokens. A viewer starts a handful of films an hour; a harvester mints hundreds,
        //    because every token is one downloadable stream.
        if (str_contains($path, '/source') || str_contains($path, 'stream/')) {
            $tokens = self::bump('guard:tok:'.$ip, 60);
            if ($tokens === self::TOKEN_PER_MIN || ($tokens > self::TOKEN_PER_MIN && $tokens % 25 === 0)) {
                $out[] = ['token_abuse', 18, ['tokens_in_minute' => $tokens]];
            }
        }

        // 3. Ordered ids. People browse by interest, which is not monotonic; a crawler counts.
        if (preg_match('~/(\d{2,})(?:/|$|\?)~', '/'.$path, $m)) {
            $run = self::sequentialRun($ip, (int) $m[1]);
            if ($run === self::SEQUENTIAL_RUN) {
                $out[] = ['sequential', 20, ['run_length' => $run, 'last_id' => (int) $m[1]]];
            }
        }

        // 4. A self-identified crawler or a bare HTTP library.
        $ua = strtolower((string) $request->userAgent());
        if ($ua === '' || Str::contains($ua, self::BOT_UA)) {
            $out[] = ['bot_ua', 6, ['ua' => Str::limit((string) $request->userAgent(), 120)]];
        }

        // 5. Catalogue JSON fetched without ever loading a page of ours. One such request is normal
        //    (a deep link, a warm cache); a steady stream of them is a harvester. Counted, not
        //    flagged on sight — and the app is already exempt above.
        if ($request->expectsJson() && ! $request->headers->has('referer')) {
            $bare = self::bump('guard:bare:'.$ip, 300);
            if ($bare === 40) {
                $out[] = ['no_referer', 10, ['json_without_referer' => $bare]];
            }
        }

        return $out;
    }

    /** Length of the current ascending-id run for this address (1 when the chain breaks). */
    private static function sequentialRun(string $ip, int $id): int
    {
        $key = 'guard:seq:'.$ip;
        $state = Cache::get($key);
        $run = ($state && $id > $state['last'] && $id - $state['last'] <= 5) ? $state['run'] + 1 : 1;
        Cache::put($key, ['last' => $id, 'run' => $run], now()->addMinutes(5));

        return $run;
    }

    private static function bump(string $key, int $ttlSeconds): int
    {
        $n = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $n, now()->addSeconds($ttlSeconds));

        return $n;
    }

    /** Write the observation, add to the running score, and block once it is high enough. */
    private static function record(Request $request, string $ip, string $reason, int $score, array $meta): void
    {
        SecurityEvent::create([
            'ip' => $ip,
            'reason' => $reason,
            'score' => $score,
            'method' => $request->method(),
            'path' => Str::limit(ltrim($request->path(), '/'), 180, ''),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            'meta' => $meta,
            'created_at' => now(),
        ]);

        $key = 'guard:score:'.$ip;
        $total = (int) Cache::get($key, 0) + $score;
        Cache::put($key, $total, now()->addMinutes(self::SCORE_WINDOW_MIN));

        if ($total >= self::BLOCK_SCORE) {
            self::block($ip, $reason, $total);
        }
    }

    /** Record a block. In observe mode this is deliberately skipped — nothing is ever refused. */
    private static function block(string $ip, string $reason, int $score): void
    {
        if (! self::enforcing()) {
            return;
        }

        BlockedIp::updateOrCreate(
            ['ip' => $ip],
            ['reason' => $reason, 'score' => $score, 'expires_at' => now()->addHours(self::BLOCK_HOURS), 'manual' => false],
        );
        Cache::forget('guard:block:'.$ip);

        LineNotifier::alert(
            'scrape:'.$ip,
            "🚨 บล็อกผู้ต้องสงสัยดูดข้อมูล\nIP: {$ip}\nสาเหตุ: {$reason} (คะแนน {$score})\nบล็อก ".self::BLOCK_HOURS." ชม. · ดูรายละเอียดที่ /admin/security",
            180,
        );
    }
}
