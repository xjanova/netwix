<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\PosterBackfill;
use App\Support\SafeUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Fetches a remote poster through OUR server so the admin sees what the poster really is.
 *
 * Sources answer a hotlinked image request differently depending on who is asking: rongyok returns a
 * green "rongyok.com ดูฟรีเต็มๆ" advert to a browser coming from another site, while the same URL
 * fetched from this server returns the genuine artwork — byte-identical across every Referer we
 * tried (measured 2026-08-19). The admin pages that show un-imported titles were therefore filling
 * with adverts, which makes the catalogue impossible to review: you cannot judge a cover you are not
 * being shown.
 *
 * Routing those images through here fixes the display and stops handing a rival free impressions
 * inside our own admin. It is admin-only and passes every URL through [SafeUrl] first, because the
 * address arrives from the page and a source could otherwise point it at our own network.
 */
class ImageProxyController extends Controller
{
    /** Remote images are cached briefly — an admin scrolling a grid must not re-fetch every thumbnail. */
    private const CACHE_MINUTES = 30;

    private const MAX_BYTES = 8_000_000;

    public function show(Request $request, PosterBackfill $backfill): Response
    {
        $url = trim((string) $request->query('url', ''));
        if ($url === '' || SafeUrl::problem($url) !== null) {
            return response('', 400);
        }

        $key = 'admin:imgproxy:'.md5($url);
        $payload = Cache::get($key);

        if ($payload === null) {
            // Reuse the downloader the cover pipeline uses: it already handles the hosts that refuse
            // a blank Referer, and it verifies the bytes really are an image.
            $bytes = $backfill->fetchImage($url);
            if ($bytes === null || strlen($bytes) > self::MAX_BYTES) {
                return response('', 404);
            }
            $payload = ['b' => base64_encode($bytes), 't' => self::sniff($bytes)];
            Cache::put($key, $payload, now()->addMinutes(self::CACHE_MINUTES));
        }

        return response(base64_decode($payload['b']), 200, [
            'Content-Type' => $payload['t'],
            // Short private cache: the admin is judging what the source serves TODAY, so a long cache
            // would hide a change we specifically want to notice.
            'Cache-Control' => 'private, max-age=600',
        ]);
    }

    private static function sniff(string $bytes): string
    {
        return match (true) {
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, "\x89PNG") => 'image/png',
            str_starts_with($bytes, 'GIF8') => 'image/gif',
            substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default => 'application/octet-stream',
        };
    }
}
