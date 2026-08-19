<?php

namespace App\Support;

use App\Models\BlockedIp;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pushes the blocklist out to Apache, so a refused client is turned away before PHP is involved.
 *
 * The application middleware ([App\Http\Middleware\DetectScraping]) already refuses blocked addresses,
 * and that is enough to protect the data. What it does not do is protect the SERVER: every refusal
 * still costs a PHP worker on a box that has fallen over from load before. Writing the list into
 * .htaccess moves the refusal to Apache, which answers it for free.
 *
 * ⚠️ THE DANGEROUS PART, AND HOW IT IS MADE SAFE. A malformed .htaccess takes the whole site down —
 * every page, instantly, with a 500. So this never trusts itself:
 *   1. the current file is copied to a timestamped backup first,
 *   2. only the region between our two markers is ever rewritten; everything else is preserved
 *      byte-for-byte, including the Laravel rewrite rules the site cannot live without,
 *   3. every value is validated as a real IP before it can reach the file — no free text,
 *   4. immediately after writing, the site is fetched over HTTP; if it does not answer 2xx/3xx the
 *      backup is restored automatically and the failure is logged.
 * Step 4 is the one that matters: it means a mistake here self-heals in about a second instead of
 * leaving the site dark until someone notices.
 *
 * Disabled by default. With it off, blocking still works — just in PHP.
 */
class FirewallBlocklist
{
    private const BEGIN = '# BEGIN NETWIX BLOCKLIST — managed automatically, do not edit by hand';

    private const END = '# END NETWIX BLOCKLIST';

    /** Never write more than this many rules; an unbounded list would bloat every request Apache serves. */
    private const MAX_RULES = 500;

    public static function enabled(): bool
    {
        return Setting::flag('firewall_blocklist_enabled', false);
    }

    private static function htaccessPath(): string
    {
        return public_path('.htaccess');
    }

    /**
     * Rewrite the managed block from the current database state.
     *
     * @return array{ok:bool,count:int,error:?string}
     */
    public static function sync(): array
    {
        if (! self::enabled()) {
            return ['ok' => true, 'count' => 0, 'error' => null];
        }

        $path = self::htaccessPath();
        if (! is_file($path) || ! is_writable($path)) {
            return ['ok' => false, 'count' => 0, 'error' => 'เขียนไฟล์ .htaccess ไม่ได้ (สิทธิ์ไม่พอ)'];
        }

        $ips = BlockedIp::query()
            ->where(fn ($q) => $q->where('manual', true)->orWhere('expires_at', '>', now()))
            ->orderByDesc('id')
            ->limit(self::MAX_RULES)
            ->pluck('ip')
            ->filter(fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP) !== false)   // never trust the column
            ->unique()
            ->values();

        $original = (string) file_get_contents($path);
        $backup = $path.'.bak-'.now()->format('Ymd-His');
        @copy($path, $backup);

        $written = self::replaceBlock($original, self::renderBlock($ips->all()));
        if (@file_put_contents($path, $written) === false) {
            return ['ok' => false, 'count' => 0, 'error' => 'เขียนไฟล์ไม่สำเร็จ'];
        }

        // The safety net: prove the site still answers, and roll back the moment it does not.
        if (! self::siteHealthy()) {
            @file_put_contents($path, $original);
            Log::error('firewall: .htaccess write broke the site — rolled back', ['backup' => $backup]);

            return ['ok' => false, 'count' => 0, 'error' => 'เขียนแล้วเว็บใช้งานไม่ได้ — คืนค่าเดิมอัตโนมัติแล้ว'];
        }

        self::pruneBackups();

        return ['ok' => true, 'count' => $ips->count(), 'error' => null];
    }

    /** Remove the managed block entirely (used when the feature is switched off). */
    public static function clear(): array
    {
        $path = self::htaccessPath();
        if (! is_file($path) || ! is_writable($path)) {
            return ['ok' => false, 'count' => 0, 'error' => 'เขียนไฟล์ .htaccess ไม่ได้'];
        }
        $original = (string) file_get_contents($path);
        @file_put_contents($path, self::replaceBlock($original, ''));

        if (! self::siteHealthy()) {
            @file_put_contents($path, $original);

            return ['ok' => false, 'count' => 0, 'error' => 'คืนค่าเดิมอัตโนมัติแล้ว'];
        }

        return ['ok' => true, 'count' => 0, 'error' => null];
    }

    /** @param string[] $ips */
    private static function renderBlock(array $ips): string
    {
        if ($ips === []) {
            return '';
        }

        $lines = [self::BEGIN, '<RequireAll>', '    Require all granted'];
        foreach ($ips as $ip) {
            $lines[] = '    Require not ip '.$ip;
        }
        $lines[] = '</RequireAll>';
        $lines[] = self::END;

        return implode("\n", $lines)."\n";
    }

    /**
     * Swap the managed region, leaving every other byte of the file exactly as it was — the Laravel
     * rewrite rules live in this file and must survive untouched.
     */
    private static function replaceBlock(string $content, string $block): string
    {
        $pattern = '~'.preg_quote(self::BEGIN, '~').'.*?'.preg_quote(self::END, '~').'\R?~s';

        if (preg_match($pattern, $content)) {
            return (string) preg_replace($pattern, $block, $content);
        }

        return $block === '' ? $content : rtrim($content)."\n\n".$block;
    }

    /** Does the site still answer? Called immediately after writing, from the server itself. */
    private static function siteHealthy(): bool
    {
        try {
            $resp = Http::withOptions(['verify' => false])
                ->timeout(8)->connectTimeout(4)
                ->get(rtrim((string) config('app.url'), '/').'/up');

            return $resp->status() < 400;
        } catch (\Throwable) {
            return false;   // cannot prove it is healthy → treat as broken and roll back
        }
    }

    /** Keep the five most recent backups; the rest are noise in the web root. */
    private static function pruneBackups(): void
    {
        $files = glob(public_path('.htaccess').'.bak-*') ?: [];
        rsort($files);
        foreach (array_slice($files, 5) as $old) {
            @unlink($old);
        }
    }
}
