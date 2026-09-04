<?php

namespace App\Support;

use App\Models\AppToken;
use App\Models\BlockedIp;
use App\Models\IpOffence;
use App\Models\SecurityEvent;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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

    /**
     * Second offence: a month. First is short on purpose because it might be wrong; by the second the
     * client has come back AFTER a ban ran its course and started again, which no viewer does.
     */
    private const REPEAT_HOURS = 720;

    /** Offences from this many banned times onward are permanent. */
    private const PERMANENT_AT = 3;

    /**
     * How long a served ban still counts against you.
     *
     * Without a horizon the ladder only ever climbs, so one bad afternoon last winter would earn a
     * month today and a permanent ban next year — and the first block is the one most likely to have
     * been a false positive in the first place. Ninety days is long enough that no scraper can wait
     * it out cheaply, and short enough that an address reassigned to a different customer starts
     * clean.
     */
    private const OFFENCE_MEMORY_DAYS = 90;

    /** Unauthenticated hits on /admin in 10 minutes before it stops being a mistyped URL. */
    private const ADMIN_PROBE_HITS = 20;

    /** 404s from one address in 5 minutes before it stops being a stale link. */
    private const NOT_FOUND_BURST = 25;

    /** Failed sign-ins from one address in 10 minutes before it stops being a forgotten password. */
    private const AUTH_FAIL_BURST = 8;

    /** Distinct accounts tried from one address before it is a stolen list, not a forgetful person. */
    private const AUTH_SPRAY_ACCOUNTS = 5;

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

    /**
     * THE RULE CATALOGUE — the one place a rule is defined, and the template for adding the next one.
     *
     * Everything downstream reads from here: the score a hit is worth, the Thai wording on the phone
     * and in /admin/security (via SecurityEvent::reason_label), and which of the three kinds of
     * trouble it belongs to. Adding a detection is one line here plus the code that calls record();
     * nothing else needs to learn about it, and nothing can drift out of sync with it.
     *
     * `kind` is what the owner actually needs to decide from, and the three are genuinely different
     * problems with different responses:
     *   attack  — trying to get IN (credentials, injection, our admin panel). Wake up for it.
     *   harvest — trying to take our CONTENT out (stream links, catalogue, images). Our business.
     *   scan    — background noise of the internet. Recorded and blocked, worth no one's attention.
     *
     * Scores are deliberately small except where a single hit is already proof: BLOCK_SCORE is 60, so
     * a 30 needs a second offence and a 12 needs a pattern. Anything scored 60+ blocks on sight and
     * had better be something no viewer of ours can do by accident.
     */
    public const RULES = [
        //  reason        => [Thai label,                          score, kind]
        'rate' => ['ยิงคำขอถี่ผิดปกติ', 12, 'scan'],
        'sequential' => ['ไล่ขอข้อมูลเรียงตามไอดี', 20, 'harvest'],
        'no_referer' => ['ดูดข้อมูลโดยไม่เคยเปิดหน้าเว็บ', 10, 'harvest'],
        'token_abuse' => ['ขอลิงก์ดูหนังรัว', 18, 'harvest'],
        'bot_ua' => ['บอทที่ประกาศตัวเอง', 6, 'scan'],
        'probe' => ['สุ่มยิงหาไฟล์ลับ/ช่องโหว่', 30, 'attack'],
        'payload' => ['แนบโค้ดโจมตีมากับคำขอ', 30, 'attack'],
        'headless' => ['เบราว์เซอร์อัตโนมัติที่ปลอมตัว', 10, 'scan'],
        'hotlink' => ['เอาไฟล์ของเราไปแปะเว็บอื่น', 25, 'harvest'],
        'admin_probe' => ['วนหาหน้าแอดมิน', 15, 'attack'],
        'not_found' => ['ยิงหาหน้าที่ไม่มีอยู่รัว ๆ', 20, 'scan'],
        'auth_fail' => ['ลองรหัสผ่านผิดซ้ำ ๆ', 15, 'attack'],
        'auth_spray' => ['ไล่ลองหลายบัญชี (credential stuffing)', 40, 'attack'],
    ];

    /** How the numbers a rule already measured are read out in the alert. See behaviourReport(). */
    private const META_LABELS = [
        'requests_in_minute' => 'ยิงสูงสุด %s คำขอ/นาที',
        'tokens_in_minute' => 'ขอลิงก์สตรีมสูงสุด %s ครั้ง/นาที',
        'run_length' => 'ไล่ไอดีติดกัน %s ตอน',
        'json_without_referer' => 'ดูด JSON ไม่ผ่านหน้าเว็บ %s ครั้ง',
        'fails_in_window' => 'รหัสผิด %s ครั้ง',
        'accounts_tried' => 'ลองไปแล้ว %s บัญชี',
        'not_found_in_window' => 'ยิงไม่เจอหน้า %s ครั้ง',
        'admin_hits' => 'ลองเปิดหน้าแอดมิน %s ครั้ง',
        'from' => 'มาจาก %s',
    ];

    /**
     * Attack strings that have no innocent reading. Matched against the path and the query VALUES.
     *
     * Kept strict on purpose. The one exception carved out below is our own search box: a curious
     * visitor typing `<script>alert(1)</script>` into ค้นหา is not an attacker, and flagging them
     * would re-create exactly the false-positive problem this system already had once.
     */
    private const PAYLOAD_SIGNATURES = [
        'union select', 'union all select', 'information_schema', "or '1'='1", 'or 1=1--',
        'sleep(', 'benchmark(', 'waitfor delay', 'pg_sleep(',
        '<script', 'onerror=', 'javascript:', 'onload=alert',
        '../../', "\0", 'etc/passwd', '/proc/self/environ',
        'base64_decode(', 'shell_exec(', 'phpinfo(', 'system(', '${jndi:',
    ];

    /** Query keys whose value is free text a visitor typed, so a scary string there proves nothing. */
    private const FREE_TEXT_KEYS = ['q', 's', 'search', 'keyword', 'query', 'body', 'comment', 'message'];

    /** Automation that is trying to look like a person — unlike BOT_UA, which announces itself. */
    private const HEADLESS_UA = [
        'headlesschrome', 'phantomjs', 'puppeteer', 'playwright', 'selenium', 'webdriver',
        'electron/', 'cypress', 'chrome-lighthouse',
    ];

    /** Score this rule is worth, from the catalogue. */
    private static function score(string $reason): int
    {
        return self::RULES[$reason][1] ?? 10;
    }

    /** Which of the three kinds of trouble this rule is. */
    public static function kind(string $reason): string
    {
        return self::RULES[$reason][2] ?? 'scan';
    }

    /** Thai wording for a rule — shared by the LINE alert and the admin table. */
    public static function label(string $reason): string
    {
        return self::RULES[$reason][0] ?? $reason;
    }

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

    /** How long a served ban still counts against a client. @see self::OFFENCE_MEMORY_DAYS */
    public static function offenceMemoryDays(): int
    {
        return self::OFFENCE_MEMORY_DAYS;
    }

    /** How long a SECOND offence lasts. Admin-set for the same reason the first one is. */
    public static function repeatHours(): int
    {
        $h = (int) Setting::get('scrape_block_repeat_hours', self::REPEAT_HOURS);

        return max(1, min(8760, $h ?: self::REPEAT_HOURS));
    }

    /**
     * The sentence for a client's Nth ban: hours, or null for permanent.
     *
     * 1st → the admin default (6h) · 2nd → a month · 3rd and after → permanent.
     *
     * Public because the admin page states the ladder in words, and a page that describes the rule
     * from its own copy of the numbers eventually describes a rule the code no longer follows.
     */
    public static function sentenceHours(int $offence): ?int
    {
        return match (true) {
            $offence >= self::PERMANENT_AT => null,
            $offence === 2 => self::repeatHours(),
            default => self::blockHours(),
        };
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

            // Trying the admin door is judged HERE and not in rules(), because /admin is not a content
            // path so watched() is false and the loop below would never see it. Safe to count only
            // because exempt() above has already let every real admin through: whoever is still being
            // counted is a stranger. Counted, not flagged on sight — /admin/login is a public page and
            // one visit is a mistyped URL.
            $path = ltrim($request->path(), '/');
            if ($path === 'admin' || str_starts_with($path, 'admin/')) {
                $hits = self::bump('guard:admin:'.$ip, 600);
                if ($hits === self::ADMIN_PROBE_HITS) {
                    self::record($request, $ip, 'admin_probe', self::score('admin_probe'), ['admin_hits' => $hits]);
                }
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
            if ($probe !== null) {
                self::record($request, $ip, 'probe', self::score('probe'), ['path' => $probe]);

                return self::enforcing() && self::isBlocked($ip);
            }

            // Same stack, same reason: an injection attempt is usually aimed at a URL we do not
            // route, so it must be judged before the router answers 404.
            $signature = self::payloadSignature($request);
            if ($signature !== null) {
                self::record($request, $ip, 'payload', self::score('payload'), ['signature' => $signature]);

                return self::enforcing() && self::isBlocked($ip);
            }

            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The attack string in this request, or null.
     *
     * Looks at the path and at query VALUES — but never at the ones a visitor types free text into
     * (ค้นหา, comment bodies). Someone pasting `<script>alert(1)</script>` into our search box is a
     * curious visitor, and turning them into a blocked "attacker" would recreate exactly the
     * false-positive problem this system already had once. Real injection against that box is a
     * bound-parameter problem, not something a substring rule should be trusted to catch.
     */
    private static function payloadSignature(Request $request): ?string
    {
        $haystacks = [strtolower(rawurldecode(ltrim($request->path(), '/')))];

        foreach ($request->query() as $key => $value) {
            if (in_array(strtolower((string) $key), self::FREE_TEXT_KEYS, true)) {
                continue;
            }
            foreach (Arr::flatten([$value]) as $one) {
                if (is_scalar($one)) {
                    $haystacks[] = strtolower(rawurldecode((string) $one));
                }
            }
        }

        foreach ($haystacks as $hay) {
            foreach (self::PAYLOAD_SIGNATURES as $signature) {
                if (str_contains($hay, $signature)) {
                    return $signature === "\0" ? 'null byte' : $signature;
                }
            }
        }

        return null;
    }

    /**
     * A 404 just went out to this address. Called on the way OUT of [DetectProbes], because a
     * response status cannot be known on the way in.
     *
     * One 404 is a stale bookmark. Twenty-five in five minutes is someone enumerating — usually ids
     * or filenames — and it is the one signal that catches a scanner whose paths are not on any list
     * because it invented them.
     */
    public static function noteNotFound(Request $request): void
    {
        try {
            if (self::mode() === 'off') {
                return;
            }
            $ip = (string) $request->ip();
            if ($ip === '' || self::isOwnServer($ip)) {
                return;
            }

            // A missing ASSET is our fault, not the visitor's: one browse page carries dozens of
            // posters, and if a handful of them 404 the person scrolling would be charged with
            // enumeration for looking at our own broken images. Only requests that look like someone
            // asking for a PAGE or a record are counted.
            $path = strtolower(ltrim($request->path(), '/'));
            if (Str::startsWith($path, ['storage/', 'assets/', 'build/', 'favicon'])
                || Str::endsWith($path, ['.jpg', '.jpeg', '.png', '.webp', '.gif', '.svg', '.ico', '.css', '.js', '.map', '.woff', '.woff2'])) {
                return;
            }

            $seen = self::bump('guard:404:'.$ip, 300);
            if ($seen === self::NOT_FOUND_BURST) {
                self::record($request, $ip, 'not_found', self::score('not_found'), ['not_found_in_window' => $seen]);
            }
        } catch (\Throwable) {
            // never let bookkeeping break a 404 page
        }
    }

    /**
     * A sign-in just failed. Wired to Illuminate\Auth\Events\Failed in AppServiceProvider.
     *
     * Two different attacks live here and the shape tells them apart: many tries at ONE account is
     * someone guessing a password, while a few tries at MANY accounts is a stolen credential list
     * being replayed against us — rarer, more dangerous, and far easier to be certain about, which
     * is why it scores nearly three times as much.
     *
     * The identifiers themselves are counted in the CACHE and expire in ten minutes; only the count
     * is ever written to security_events. A brute-force log that quietly accumulates other people's
     * email addresses would be a worse leak than the attack it records.
     */
    public static function noteAuthFailure(Request $request, string $identifier): void
    {
        try {
            if (self::mode() === 'off') {
                return;
            }
            $ip = (string) $request->ip();
            if ($ip === '' || self::isOwnServer($ip)) {
                return;
            }

            $fails = self::bump('guard:authfail:'.$ip, 600);
            if ($fails === self::AUTH_FAIL_BURST) {
                self::record($request, $ip, 'auth_fail', self::score('auth_fail'), ['fails_in_window' => $fails]);
            }

            $who = mb_strtolower(trim($identifier));
            if ($who === '') {
                return;
            }
            $key = 'guard:authwho:'.$ip;
            $tried = array_values(array_filter((array) Cache::get($key, []), 'is_string'));
            if (in_array($who, $tried, true)) {
                return;
            }
            $tried[] = $who;
            Cache::put($key, array_slice($tried, -20), now()->addMinutes(10));

            if (count($tried) === self::AUTH_SPRAY_ACCOUNTS) {
                self::record($request, $ip, 'auth_spray', self::score('auth_spray'), ['accounts_tried' => count($tried)]);
            }
        } catch (\Throwable) {
            // a failed login must still be a failed login, whatever happens here
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
            $out[] = ['rate', self::score('rate'), ['requests_in_minute' => $rate]];
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
                $out[] = ['token_abuse', self::score('token_abuse'), ['tokens_in_minute' => $tokens]];
            }
        }

        // 3. Ordered ids. People browse by interest, which is not monotonic; a crawler counts.
        if (preg_match('~/(\d{2,})(?:/|$|\?)~', '/'.$path, $m)) {
            $run = self::sequentialRun($ip, (int) $m[1]);
            if ($run === self::SEQUENTIAL_RUN) {
                $out[] = ['sequential', self::score('sequential'), ['run_length' => $run, 'last_id' => (int) $m[1]]];
            }
        }

        // 4. A self-identified crawler or a bare HTTP library.
        $ua = strtolower((string) $request->userAgent());
        if ($ua === '' || Str::contains($ua, self::BOT_UA)) {
            $out[] = ['bot_ua', self::score('bot_ua'), ['ua' => Str::limit((string) $request->userAgent(), 120)]];
        }

        // 4b. Automation dressed as a browser. Different from the rule above in the only way that
        //     matters: a declared crawler can be allowed or disallowed, while something calling
        //     itself HeadlessChrome on our stream endpoints has chosen to look like a person and
        //     failed. Scored low all the same — QA tools and accessibility audits exist.
        if ($ua !== '' && Str::contains($ua, self::HEADLESS_UA)) {
            $out[] = ['headless', self::score('headless'), ['ua' => Str::limit((string) $request->userAgent(), 120)]];
        }

        // 4c. Our video, someone else's page. A foreign Referer on a stream or API path is the
        //     definition of hotlinking — they are running our player on our bandwidth. An ABSENT
        //     Referer is not this rule (that is rule 5, and it is how the app and direct links
        //     legitimately arrive); only a foreign one counts.
        //
        //     Deliberately NOT storage/media: a person who reaches a poster from Google Images or a
        //     social preview sends that site as the Referer, and blocking them would ban a viewer for
        //     the crime of finding us. Image hotlinking is the .htaccess rule's job — and really
        //     Cloudflare's — while a foreign page pulling /stream is nobody's accident.
        $referer = (string) $request->headers->get('referer', '');
        if ($referer !== '' && Str::startsWith($path, ['stream/', 'api/']) && ! self::ourHost($referer, $request)) {
            $out[] = ['hotlink', self::score('hotlink'), ['from' => Str::limit((string) (parse_url($referer, PHP_URL_HOST) ?: $referer), 80)]];
        }

        // 5. Catalogue JSON fetched without ever loading a page of ours. One such request is normal
        //    (a deep link, a warm cache); a steady stream of them is a harvester. Counted, not
        //    flagged on sight — and the app is already exempt above.
        if ($request->expectsJson() && ! $request->headers->has('referer')) {
            $bare = self::bump('guard:bare:'.$ip, 300);
            if ($bare === 40) {
                $out[] = ['no_referer', self::score('no_referer'), ['json_without_referer' => $bare]];
            }
        }

        return $out;
    }

    /**
     * Does this Referer belong to us?
     *
     * Compares against the host actually being served AND the configured APP_URL host, with a leading
     * `www.` folded away — netwix.online and www.netwix.online are the same site, and treating the
     * alias as a stranger would report our own visitors as hotlinkers.
     */
    private static function ourHost(string $referer, Request $request): bool
    {
        // preg, never ltrim(): ltrim takes a CHARACTER LIST, so ltrim('wowdrama.com', 'www.') eats the
        // leading w's and returns 'odrama.com' — a silent way to call our own visitors hotlinkers.
        $bare = static fn (string $value): string => (string) preg_replace(
            '~^www\.~', '', strtolower(trim((string) (parse_url($value, PHP_URL_HOST) ?: $value)))
        );

        $host = $bare($referer);
        if ($host === '') {
            return true;   // unparseable: not evidence of anything, so never act on it
        }

        return in_array($host, array_filter([$bare($request->getHost()), $bare((string) config('app.url'))]), true);
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

        // Scored per BLOCK KEY, not per raw address — the same thing a block is written against.
        // Keeping them on different keys meant lifting a block did not lift the score behind it: the
        // admin pressed "ปลด", the very next request re-crossed BLOCK_SCORE, and the unblock looked
        // like it had done nothing. (Found by unblocking myself and being refused again seconds later.)
        // It is also the more honest measure: one subscriber's behaviour, not one of the addresses a
        // carrier happens to be rotating them through.
        //
        // Same fixed-window rule as bump(): the score must age out on its own, or SCORE_WINDOW_MIN
        // silently means "until 30 minutes of total silence" and a long viewing session accumulates
        // until it crosses BLOCK_SCORE.
        $total = self::bumpBy('guard:score:'.self::blockKey($ip), $score, self::SCORE_WINDOW_MIN * 60);

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

        // Is a ban already in force for this client? If so nothing new has happened: they are simply
        // still here, still being refused, still tripping rules on the way. Re-sentencing them would
        // be the end of the feature — inspect() records BEFORE it checks the blocklist, so a scanner
        // that ignores its 403 (they all do) re-crosses BLOCK_SCORE every few seconds and would climb
        // 6 hours → a month → permanent inside one burst. Every client would reach permanent on its
        // first visit, which is neither what was asked for nor survivable if the rule was wrong.
        // It also stays SILENT: the alert for this client already went to the owner's phone when the
        // ban began, and "still banned, still trying" is not news. The expiry is left exactly where
        // it was, too — refreshing it on every refused request makes a 6-hour ban last as long as the
        // attacker cares to keep knocking, which is a permanent ban nobody chose and nobody can see.
        $existing = BlockedIp::where('ip', $key)->first();
        if ($existing && $existing->active) {
            // Refresh what they were last caught doing — but never on a block an admin placed by
            // hand, or the row would stop saying "บล็อกด้วยตนเอง · <the admin's reason>" and start
            // reporting whichever rule the client tripped on its way into a wall it was already
            // behind. The admin's reason for blocking is theirs, not ours to overwrite.
            if (! $existing->manual) {
                $existing->update(['reason' => $reason, 'score' => $score]);
            }

            return;
        }

        // No ban in force, and they are back doing it again → this is a fresh offence, and the
        // penalty steps up. "Came back after serving it" is the strongest evidence we have that the
        // first block caught a scraper rather than a viewer: nobody who was blocked by mistake
        // returns and behaves like a harvester a second time.
        $offence = self::countOffence($key, $reason);
        $hours = self::sentenceHours($offence);

        BlockedIp::updateOrCreate(
            ['ip' => $key],
            [
                'reason' => $reason,
                'score' => $score,
                'expires_at' => $hours === null ? null : now()->addHours($hours),
                'manual' => false,
                // `hits` is deliberately NOT reset. It is the count of requests this client has had
                // refused across every ban it has earned, and an address at twelve thousand is
                // telling the admin something an address at two is not.
            ],
        );
        Cache::forget('guard:block:'.$key);
        Cache::forget('guard:block:'.$ip);
        // Reset the score with the block: it has been acted on. Left in place it would re-block the
        // moment this one expires, turning a 6-hour ban into a permanent one nobody chose.
        Cache::forget('guard:score:'.$key);

        // Push it down to Apache too when firewall blocking is on, so a banned client stops costing us
        // PHP at all. sync() self-checks and rolls back, so a bad write cannot take the site with it.
        FirewallBlocklist::sync();

        self::pushAlert($ip, $score, $hours, $offence);
    }

    /**
     * Record that this client has been banned once more, and say which time it is.
     *
     * Deliberately in its own table rather than on `blocked_ips`: that row is deleted when a ban is
     * lifted and forgotten when it expires, so a ladder built on it could never climb past the first
     * rung. See the `ip_offences` migration.
     *
     * Bans older than OFFENCE_MEMORY_DAYS do not count, and the ladder restarts from the bottom —
     * the counter is reset rather than merely ignored, so a client cannot bank offences by going
     * quiet for a season and have them all resurface on the next strike.
     */
    private static function countOffence(string $key, string $reason): int
    {
        $row = IpOffence::firstOrNew(['ip' => $key]);

        $stale = $row->last_at !== null && $row->last_at->lt(now()->subDays(self::OFFENCE_MEMORY_DAYS));

        $row->offences = ($stale || ! $row->exists) ? 1 : $row->offences + 1;
        $row->last_reason = $reason;
        $row->last_at = now();
        if ($stale || ! $row->exists) {
            $row->first_at = now();
        }
        $row->save();

        return $row->offences;
    }

    /**
     * Tell the owner what the client DID — not which rule number fired.
     *
     * "บล็อก IP x · สาเหตุ probe · คะแนน 60" is unreadable on a phone: every alert looks identical,
     * so the one that matters (someone walking our episode endpoints for stream links) reads exactly
     * like the ninety that don't (a bot asking every site on the internet for /wp-login.php). The
     * evidence to tell them apart is already in `security_events` — paths, counts, timing — it was
     * simply never read back. So read it back and say it.
     *
     * TWO CAPS, NOT ONE. A single global cap meant a harvester could be silenced for an hour by a
     * worthless scanner alert that happened to fire first. Noise keeps the old hourly cap; the
     * behaviour we actually built this system to catch gets its own, shorter one.
     */
    private static function pushAlert(string $ip, int $score, ?int $hours, int $offence): void
    {
        $report = self::behaviourReport($ip);

        // A repeat offender is urgent whatever they were doing. The kind of rule they tripped decides
        // how alarming ONE visit is; coming back after serving a ban is its own signal, and a client
        // being handed a month or a permanent ban is a decision the owner should hear about even when
        // the rule behind it is the boring one.
        $urgent = $report['kind'] !== 'scan' || $offence >= 2;

        if (! Cache::add($urgent ? 'guard:alertcap:urgent' : 'guard:alertcap:noise', 1, now()->addMinutes($urgent ? 15 : 60))) {
            return;
        }

        $head = match ($report['kind']) {
            'attack' => '🚨 มีคนพยายามเจาะระบบ',
            'harvest' => '🚨 มีคนไล่ดูดลิงก์/ข้อมูลหนังของเรา',
            default => '🛡️ บล็อกบอทที่มาสแกนหาช่องโหว่',
        };

        // Say the sentence AND why it is that long. "แบนถาวร" with no explanation reads like a bug
        // when the same address was banned for six hours last week; "ครั้งที่ 3" is the whole story.
        $sentence = $hours === null ? 'แบนถาวร' : ($hours >= 24 ? 'แบน '.intdiv($hours, 24).' วัน' : "แบน {$hours} ชม.");
        $repeat = $offence >= 2 ? " (ทำผิดครั้งที่ {$offence} — เคยโดนแบนแล้วกลับมาทำอีก)" : '';

        $body = $head."\nIP: ".$ip."\n"
            .($report['text'] !== '' ? $report['text'] : 'คะแนนรวม: '.$score)
            ."\n{$sentence}{$repeat} · ดูทั้งหมดที่ /admin/security";

        LineNotifier::alert('scrape:'.$ip, $body, $urgent ? 60 : 180);
    }

    /**
     * Read back, from the evidence we already store, what this address spent the last half hour doing.
     *
     * The window is SCORE_WINDOW_MIN because that is the window the score behind this block was
     * accumulated over: the alert then describes the burst that was acted on, rather than everything
     * the address has ever done. Matched on the exact address, not the /64 — a text LIKE over a
     * prefix is unreliable across IPv6's compressed forms, and the concrete address is the honest
     * thing to show as evidence anyway.
     *
     * @return array{kind:string,text:string}
     */
    private static function behaviourReport(string $ip): array
    {
        $rows = SecurityEvent::query()
            ->where('ip', $ip)
            ->where('created_at', '>=', now()->subMinutes(self::SCORE_WINDOW_MIN))
            ->orderByDesc('id')
            ->limit(60)
            ->get(['reason', 'path', 'user_agent', 'meta', 'created_at']);

        if ($rows->isEmpty()) {
            return ['kind' => 'scan', 'text' => ''];
        }

        // Rule labels come from the model, so the phone and the admin table never drift apart.
        $lines = ['พฤติกรรม: '.$rows->unique('reason')->map(fn ($e) => $e->reason_label)->implode(' + ')];

        // Ordered by id DESC, so first row = newest. Avoids comparing Carbons to find the span.
        $seconds = $rows->last()->created_at->diffInSeconds($rows->first()->created_at);
        $lines[] = 'จำนวน: '.($rows->count() >= 60 ? '60+' : $rows->count()).' ครั้ง ใน '.max(1, (int) ceil($seconds / 60)).' นาที';

        // Collapse ids before counting. A harvester's paths are all DIFFERENT — ten episode ids in
        // thirteen minutes — so counting them raw produces ten lines of "×1" and buries the shape.
        // "/api/episode/{id}/source ×10" is the same evidence in one legible line. (2+ digits, so a
        // real path segment like /v2/ is not mangled into a placeholder.)
        $paths = $rows->pluck('path')->filter()
            ->map(fn ($path) => preg_replace('~\d{2,}~', '{id}', (string) $path))
            ->countBy()->sortDesc();
        if ($paths->isNotEmpty()) {
            $lines[] = 'ขออะไรบ้าง:';
            foreach ($paths->take(4) as $path => $count) {
                $lines[] = '• /'.Str::limit((string) $path, 44).($count > 1 ? ' ×'.$count : '');
            }
            if ($paths->count() > 4) {
                $lines[] = '• (และพาธแบบอื่นอีก '.($paths->count() - 4).' แบบ)';
            }
        }

        // The measured numbers each rule already recorded — "ยิงสูงสุด 350 คำขอ/นาที" is the whole
        // story in four words, and it was being thrown away with the rest of the meta. PEAK per
        // field, not every value: `rate` re-fires every 50 requests, so one burst leaves rows for
        // 100/150/200/250/300/350 and listing them all would push the real number off the screen.
        $peak = [];
        foreach ($rows as $event) {
            foreach ((array) $event->meta as $field => $value) {
                if (isset(self::META_LABELS[$field]) && is_numeric($value)) {
                    $peak[$field] = max($peak[$field] ?? 0, (int) $value);
                }
            }
        }
        if ($peak !== []) {
            $lines[] = 'ตัวเลข: '.collect($peak)
                ->map(fn ($value, $field) => sprintf(self::META_LABELS[$field], $value))
                ->implode(' · ');
        }

        $ua = trim((string) $rows->first()->user_agent);
        $lines[] = 'UA: '.($ua === '' ? '(ไม่ส่ง User-Agent มาเลย)' : Str::limit($ua, 64));

        // Which of the three kinds this burst is, worst first — every rule declares its own in the
        // catalogue. The PATH is consulted as well, because the real stream-link harvester we caught
        // on 28 Aug tripped only `bot_ua`: by rule code alone it was background noise, and by what it
        // reached for it was someone emptying our library.
        $kinds = $rows->map(fn ($e) => self::kind((string) $e->reason))->all();
        if ($rows->contains(fn ($e) => Str::startsWith((string) $e->path, self::WATCHED))) {
            $kinds[] = 'harvest';
        }

        return [
            'kind' => in_array('attack', $kinds, true) ? 'attack' : (in_array('harvest', $kinds, true) ? 'harvest' : 'scan'),
            'text' => implode("\n", $lines),
        ];
    }
}
