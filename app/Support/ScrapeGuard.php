<?php

namespace App\Support;

use App\Models\AppToken;
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
    /*
     * Both thresholds are set from MEASUREMENT, not intuition: five days of Apache logs (75,928 lines,
     * 16-21 Aug 2026, real client IPs restored by Cloudflare) put a real viewer's worst true
     * clock-minute at 69 requests on the watched surface and 29 stream-token mints. The previous
     * values sat at 90 and 25 — i.e. BELOW what an ordinary binge already does — which is why every
     * one of the first 96 recorded events was a false positive, 69 of them the owner.
     */

    /** Requests to content endpoints per minute, per address. Measured human worst case: 69. */
    private const RATE_PER_MIN = 240;

    /** Stream-token mints per minute — the resolver, not media transport. Measured worst case: 29. */
    private const TOKEN_PER_MIN = 90;

    /** Consecutive ascending ids before "walking the catalogue" is the only explanation. */
    private const SEQUENTIAL_RUN = 12;

    /** Score at which a client is blocked (when enforcing). Roughly "tripped a rule repeatedly". */
    private const BLOCK_SCORE = 60;

    /** Default block length when the admin hasn't set one. Long enough to be expensive, short enough to be wrong. */
    private const BLOCK_HOURS = 6;

    /** Paths nobody browsing a film site ever asks for. Requesting one is not a mistake. */
    private const PROBE_PATHS = [
        '.env', '.git', '.aws', '.ssh', 'wp-admin', 'wp-login', 'wp-content', 'xmlrpc.php',
        'phpinfo', 'phpmyadmin', 'adminer', 'vendor/phpunit', 'config.json', 'credentials',
        'id_rsa', 'shell.php', 'eval-stdin', 'server-status', 'actuator', 'solr/', 'struts',
    ];

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

    /** How many hours an automatic block lasts. Admin-set, because "how long" is a judgement call. */
    public static function blockHours(): int
    {
        $h = (int) Setting::get('scrape_block_hours', self::BLOCK_HOURS);

        return max(1, min(720, $h ?: self::BLOCK_HOURS));
    }

    /**
     * What we actually block — an address for IPv4, the /64 for IPv6.
     *
     * Blocking a single IPv6 address is close to useless AND unfair at the same time. Thai carriers
     * hand a household a whole /64 and rotate the low half constantly: one visitor showed up under
     * five different addresses in five days. A /128 block is evaded by reconnecting, while the same
     * rotation makes the block list fill with dead entries. The /64 is the subscriber, which is the
     * thing we actually mean when we say "this person".
     *
     * IPv4 stays exact: an address can be shared by a whole mobile carrier's customers (CGNAT), so
     * widening it would take out strangers.
     */
    public static function blockKey(string $ip): string
    {
        if (! str_contains($ip, ':')) {
            return $ip;
        }
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }
        // Keep the first 64 bits, zero the rest.
        $prefix = @inet_ntop(substr($packed, 0, 8).pack('x8'));

        return $prefix === false ? $ip : $prefix.'/64';
    }

    /** True when this address is currently refused (and the refusal is counted). */
    public static function isBlocked(string $ip): bool
    {
        if (self::mode() === 'off') {
            return false;
        }

        $key = self::blockKey($ip);
        $block = Cache::remember('guard:block:'.$key, now()->addMinutes(2), function () use ($ip, $key) {
            // Match the /64 entry AND a legacy exact-address one, so blocks written before the prefix
            // rule existed keep working.
            $row = BlockedIp::whereIn('ip', array_unique([$key, $ip]))->first();

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
            if (self::mode() === 'off') {
                return false;
            }
            $ip = (string) $request->ip();
            if ($ip === '' || self::exempt($request)) {
                return false;
            }

            if (! self::watched($request)) {
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
        // A VALIDATED app token. The previous version accepted any non-empty bearerToken() without
        // looking at it, which meant sending `Authorization: Bearer anything` switched the entire
        // guard off — an off switch available to exactly the people it is meant to stop, and denied to
        // the real mobile app, whose media player sends no Authorization header at all.
        if ($request->attributes->has('app_token')) {
            return true;
        }
        $bearer = (string) $request->bearerToken();
        if ($bearer !== '' && self::validAppToken($bearer)) {
            return true;
        }

        $user = $request->user();
        if ($user && method_exists($user, 'isAdmin') && $user->isAdmin()) {
            return true;
        }

        return self::isOwnServer((string) $request->ip());
    }

    /** Is this bearer an app token we actually issued? Cached, so it costs no query on the hot path. */
    private static function validAppToken(string $bearer): bool
    {
        try {
            return (bool) Cache::remember('guard:apptok:'.sha1($bearer), now()->addMinutes(10),
                fn () => AppToken::where('token_hash', hash('sha256', $bearer))->exists());
        } catch (\Throwable) {
            return false;   // never let a lookup failure turn into a block
        }
    }

    /**
     * Our own machine. Loopback alone was not enough: the canary, the storage probe and the admin
     * preview all reach the site by its PUBLIC hostname, so the request leaves the box, goes through
     * Cloudflare and comes back as the server's own public address. That is why our health checks were
     * being logged as bot traffic — and under enforcement the box would have blocked itself, which
     * fails every source probe at once and fires "แหล่งล่ม" alerts for sources that are perfectly fine.
     */
    public static function isOwnServer(string $ip): bool
    {
        if ($ip === '' || in_array($ip, ['127.0.0.1', '::1'], true)) {
            return true;
        }

        $own = array_filter(array_map('trim', explode(',', (string) config('services.guard.server_ips', ''))));

        return in_array($ip, $own, true);
    }

    /**
     * Judge one request for credential scanning. Separate entry point from inspect() because it must
     * run on the GLOBAL stack: a scanner asks for paths we have no route for, and route-group
     * middleware never runs on a 404. See [App\Http\Middleware\DetectProbes].
     *
     * Needs no session and no user — nobody signed in has a reason to ask for a `.env` either.
     */
    public static function inspectProbe(Request $request): bool
    {
        try {
            if (self::mode() === 'off') {
                return false;
            }
            $ip = (string) $request->ip();
            if ($ip === '' || self::isOwnServer($ip)) {
                return false;
            }
            $probe = self::probeReason($request);
            if ($probe === null) {
                return false;
            }

            self::record($request, $ip, 'probe', 30, ['path' => $probe]);

            return self::enforcing() && self::isBlocked($ip);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The fragment of the path that gives away a credential hunt, or null.
     *
     * Deliberately a substring match on a short, specific list: every entry names a file or panel that
     * exists only on OTHER kinds of site, so a viewer of ours has no way to ask for one by accident.
     * That is what makes a single hit enough to act on, where every other rule needs a pattern.
     */
    private static function probeReason(Request $request): ?string
    {
        $path = strtolower(ltrim($request->path(), '/'));
        if ($path === '' || $path === '/') {
            return null;
        }
        foreach (self::PROBE_PATHS as $needle) {
            if (str_contains($path, $needle)) {
                return Str::limit($path, 90, '');
            }
        }

        return null;
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
        //
        //    ONLY the resolver mints. This used to also match 'stream/', which is media TRANSPORT —
        //    every HLS segment (one per ~6s of video), every manifest re-fetch, every Range re-open of
        //    an mp4. Twenty-five segments is three minutes of television, so the rule fired on people
        //    watching. It also made the mobile app unblockable-by-design impossible to exempt: its
        //    ExoPlayer sends no Authorization header at all, and 100% of its traffic is /stream/.
        if (str_contains($path, '/source')) {
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

    /**
     * Count one hit inside a window that actually expires.
     *
     * The window must be set when the key is CREATED and never touched again. The original used
     * Cache::put on every hit, which rewrote the expiry each time — so a "per minute" counter only
     * reset after a full minute of TOTAL SILENCE. A player fetching an HLS segment every six seconds,
     * or a progress heartbeat every ten, kept it alive for the whole session, and the number written
     * into the log as `requests_in_minute` was really a running session total. One address was
     * recorded at "500 requests in a minute" while genuinely making about seven.
     *
     * Cache::add is SET-if-absent WITH the expiry; Cache::increment is a bare INCR that leaves the
     * expiry alone. That combination is the whole fix.
     */
    private static function bump(string $key, int $ttlSeconds): int
    {
        return self::bumpBy($key, 1, $ttlSeconds);
    }

    /** As bump(), but adding an arbitrary amount (used for the running score). */
    private static function bumpBy(string $key, int $amount, int $ttlSeconds): int
    {
        if (Cache::add($key, $amount, now()->addSeconds($ttlSeconds))) {
            return $amount;
        }

        $n = (int) Cache::increment($key, $amount);

        // The key can expire between the add and the increment; INCR would then recreate it with no
        // expiry at all, and the counter would live forever. Re-stamp the window in that one case.
        if ($n <= $amount) {
            Cache::put($key, $n, now()->addSeconds($ttlSeconds));
        }

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

        // Same fixed-window rule as bump(): the score must age out on its own, or SCORE_WINDOW_MIN
        // silently means "until 30 minutes of total silence" and a long viewing session accumulates
        // until it crosses BLOCK_SCORE.
        $total = self::bumpBy('guard:score:'.$ip, $score, self::SCORE_WINDOW_MIN * 60);

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

        // Never auto-block an address a real member is signed in from. A paying viewer is the one
        // person we can be sure is not a harvester, and the cost of getting them wrong — they cannot
        // reach the site, cannot reach support, and we hear about it days later — is far higher than
        // letting an account-holding scraper through, whose account we can revoke instead.
        if (auth()->check()) {
            return;
        }

        $key = self::blockKey($ip);
        $hours = self::blockHours();

        BlockedIp::updateOrCreate(
            ['ip' => $key],
            ['reason' => $reason, 'score' => $score, 'expires_at' => now()->addHours($hours), 'manual' => false],
        );
        Cache::forget('guard:block:'.$key);
        Cache::forget('guard:block:'.$ip);

        // Push it down to Apache too when firewall blocking is on, so a banned client stops costing us
        // PHP at all. sync() self-checks and rolls back, so a bad write cannot take the site with it.
        FirewallBlocklist::sync();

        // Throttled per ADDRESS *and* globally. Per-address alone is not a throttle at all here: Thai
        // mobile carriers hand out a fresh IPv6 per session, so one determined client can present a
        // hundred distinct addresses and buy a hundred separate pushes. The global key is what makes
        // "an outage cannot spam the owner" actually true.
        if (Cache::add('guard:alertcap', 1, now()->addMinutes(60))) {
            LineNotifier::alert(
                'scrape:'.$ip,
                "🚨 บล็อกผู้ต้องสงสัยดูดข้อมูล\nIP: {$ip}\nสาเหตุ: {$reason} (คะแนน {$score})\nบล็อก ".self::BLOCK_HOURS." ชม. · ดูรายละเอียดที่ /admin/security",
                180,
            );
        }
    }
}
