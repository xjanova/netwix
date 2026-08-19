<?php

namespace App\Support;

/**
 * Refuses a URL that would make the SERVER fetch something on our own network.
 *
 * The admin cover tools hand a URL straight to an outbound HTTP GET, and not every one of those URLs
 * is typed by the admin: the candidates offered by a by-name search are read out of a source site's
 * HTML, which is third-party content we do not control. A compromised or hostile source could list
 * `http://127.0.0.1:9200/…` as a cover image and get it fetched from inside our network with one
 * click. Everything reaching [PosterBackfill::storeFrom] from a request therefore passes here first.
 *
 * Deliberately a static, cheap check rather than a full crawl: the response is only ever kept if GD
 * can decode it as an image, so this guards the request being MADE, not what comes back. The
 * equivalent rules for the public ad marketplace live in [AdScreening], which additionally walks the
 * redirect chain because there the URL comes from an anonymous member rather than an admin.
 */
class SafeUrl
{
    /** Why this URL must not be fetched server-side (Thai, admin-facing), or null if it's fine. */
    public static function problem(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if ($parts === false || empty($parts['host']) || ! isset($parts['scheme'])) {
            return 'รูปแบบลิงก์ไม่ถูกต้อง';
        }
        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return 'ลิงก์ต้องขึ้นต้นด้วย http:// หรือ https://';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'ลิงก์ต้องไม่มีชื่อผู้ใช้/รหัสผ่านฝังอยู่';
        }

        $host = strtolower(trim($parts['host'], '[]'));   // strip the brackets of an IPv6 literal
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return 'ลิงก์ปลายทางอยู่ในเครือข่ายภายใน';
        }

        // An IP literal is checked as-is; a name is resolved, because a public hostname is free to
        // point at 127.0.0.1 and that is the ordinary way this check gets walked around.
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : self::resolve($host);
        if ($ip === null) {
            return 'หาที่อยู่ของโดเมนนี้ไม่เจอ';
        }
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return 'ลิงก์ปลายทางอยู่ในเครือข่ายภายใน';
        }

        return null;
    }

    /** First A/AAAA record for a hostname, or null when it doesn't resolve. */
    private static function resolve(string $host): ?string
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        foreach ($records ?: [] as $r) {
            if (! empty($r['ip'])) {
                return $r['ip'];
            }
            if (! empty($r['ipv6'])) {
                return $r['ipv6'];
            }
        }

        // dns_get_record is disabled on some hosts; gethostbyname echoes the input back on failure.
        $ip = gethostbyname($host);

        return ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)) ? $ip : null;
    }
}
