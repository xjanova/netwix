<?php

namespace App\Support;

use App\Models\Content;
use App\Models\SourceTitle;
use App\Services\Import\Contracts\ProvidesPoster;
use App\Services\Import\RemoteSeries;
use App\Services\Import\SourceRegistry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Heals a title whose cover is missing or whose hotlinked poster has gone dead: re-fetches a fresh
 * poster URL from the source and DOWNLOADS it into our own storage (WebP), so the recovered cover is
 * permanent and can't break again. Whatever can't be recovered keeps its (dead) value and is covered
 * visually by the branded fallback the card renders. Owner rule 2026-07-16: "ไม่พบปก → วิ่งไปอิมพอต
 * ใหม่; ถ้าไม่มีจริงๆ ใช้ปก fallback เรา".
 */
class PosterBackfill
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

    /**
     * Encode target for a cover, picked by measuring rather than by feel (2026-08-15, on real 24-hdx
     * covers). A browse card is 146-182 px wide (content-card.blade), so 600 on the long side of a
     * 2:3 poster — 400 px wide — still paints it at 2.2x on the widest card.
     *
     * Dimension is the lever here, not quality: our sources mostly serve 500x750, and re-encoding
     * those at q80 vs q68 only moved 53 KB to 44 KB, because squeezing an already-compressed JPEG
     * harder mostly buys generation loss. Capping the long side instead measured
     *   native 53 KB · max600 37 KB · max540 30 KB · max480 26 KB
     * so 600 takes ~30% off while staying above every card's pixel budget. Quality is nudged UP to
     * 82 to spend some of that back on sharpness, which is what the smaller box is protecting.
     *
     * The one place a cover is painted big is the title-modal hero (16:9 crop, ~900 px wide). That
     * already upscales — the sources themselves are only 500 px wide — so 400 px changes a decorative
     * band behind a gradient by a fifth, and the locked-pro/vip backdrops using it are blur-2xl.
     */
    public const COVER_MAX_DIM = 600;

    public const COVER_QUALITY = 82;

    public function __construct(private SourceRegistry $registry) {}

    /**
     * Try to recover a poster for a content and store it locally. Returns the new stored relative path
     * (e.g. "media/posters/12-ab12cd34.webp") on success, or null if nothing playable was found.
     */
    public function recover(Content $content): ?string
    {
        $st = SourceTitle::where('source', $content->source)
            ->where('source_key', $content->source_key)->first();

        // Candidate URLs, cheapest first: whatever the source title already holds, then a fresh scrape.
        $candidates = [];
        if ($st && filled($st->poster_url)) {
            $candidates[] = $st->poster_url;
        }

        $source = $content->source ? $this->registry->get($content->source) : null;
        if ($source instanceof ProvidesPoster) {
            try {
                $fresh = $source->fetchPoster(new RemoteSeries(
                    source: (string) $content->source,
                    sourceKey: (string) $content->source_key,
                    title: (string) $content->title,
                    cleanTitle: (string) $content->title,
                    extra: is_array($st?->extra) ? $st->extra : [],
                ));
                if (filled($fresh)) {
                    $candidates[] = $fresh;
                }
            } catch (\Throwable) {
                // best-effort — a failed scrape just means we fall back to the branded cover
            }
        }

        foreach (array_values(array_unique($candidates)) as $url) {
            $bytes = $this->download($url);
            if ($bytes === null) {
                continue;
            }
            $path = ImageStore::putCover($bytes, 'media/posters', (string) $content->id, $content->poster_path,
                self::COVER_MAX_DIM, self::COVER_QUALITY);
            if (($path = $this->rejectIfPlaceholder($path, $content)) !== null) {
                // Remember the working remote URL on the source title too (cheap next-time recovery).
                if ($st && $url !== $st->poster_url) {
                    $st->forceFill(['poster_url' => $url])->save();
                }

                return $path;
            }
        }

        return null;
    }

    /**
     * Pull a title's CURRENT, still-working hotlinked cover down into our own storage.
     *
     * This is the bulk cache path, and it is deliberately not [self::recover]: recover() exists for a
     * cover that is missing or dead and pays for a fresh source scrape to find a replacement URL.
     * Here the stored URL loads fine and there is nothing to find — we only want to stop serving it
     * from someone else's server. Measured 2026-08-15, that is worth doing even when the hotlink
     * works: 24-hdx (our largest source) answers its covers with `Cache-Control: no-store`, so a
     * browser is forbidden to keep them and re-downloads ~9.7 MB of covers on EVERY page view, and
     * the originals are full-size WordPress uploads averaging 217 KB for a card that renders at 200 px.
     * Stored locally they become ~25 KB WebP behind our own CDN, cached like any other static asset.
     *
     * Falls through to the full recover() when the stored URL turns out not to load after all, so a
     * localize sweep also heals whatever it finds broken on the way.
     */
    public function localize(Content $content): ?string
    {
        $url = (string) $content->poster_path;
        if ($url === '' || ! str_starts_with($url, 'http')) {
            return null;   // already ours — nothing to pull down
        }

        if (($bytes = $this->download($url)) !== null) {
            $path = ImageStore::putCover($bytes, 'media/posters', (string) $content->id, $url,
                self::COVER_MAX_DIM, self::COVER_QUALITY);
            if (($path = $this->rejectIfPlaceholder($path, $content)) !== null) {
                return $path;
            }
        }

        return $this->recover($content);
    }

    /**
     * Download a cover from an explicit list of URLs, best first, and store it locally.
     *
     * The entry point for a cover an ADMIN chose rather than one we derived: a candidate picked out of
     * a by-name search ([PosterSearch]) or a URL pasted by hand. Same storage path as everything else
     * — WebP at [self::COVER_MAX_DIM], unique filename, old file cleaned up — so an admin-supplied
     * cover is indistinguishable from a healed one afterwards, and the same two-attempt download
     * handles the hosts that refuse a blank Referer.
     *
     * @param  string[]  $urls  tried in order until one yields a real image
     */
    public function storeFrom(Content $content, array $urls): ?string
    {
        foreach ($urls as $url) {
            if (! is_string($url) || ! str_starts_with($url, 'http')) {
                continue;
            }
            if (($bytes = $this->download($url)) === null) {
                continue;
            }
            $path = ImageStore::putCover($bytes, 'media/posters', (string) $content->id, $content->poster_path,
                self::COVER_MAX_DIM, self::COVER_QUALITY);
            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Persist a recovered cover. The backdrop follows it whenever it was the SAME image — import seeds
     * both fields from one source URL (23,702 of 23,761 titles), so a poster that just turned out to be
     * dead means the backdrop behind the hero and the title modal is the identical dead URL. Healing
     * only the poster would leave those rendering a bare gradient.
     *
     * Also clears `cover_missing_at`: the title now HAS a cover, so it must drop out of the admin's
     * missing-covers queue no matter which route put one there.
     */
    public function apply(Content $content, string $path): void
    {
        $old = (string) $content->poster_path;
        $updates = [
            'poster_path' => $path,
            'poster_hash' => self::hashOf($path),
            'cover_missing_at' => null,
        ];
        if (blank($content->backdrop_path) || (string) $content->backdrop_path === $old) {
            $updates['backdrop_path'] = $path;
        }
        $content->forceFill($updates)->save();
    }

    /**
     * Reject a cover that is the SAME PICTURE we already stored for a different title.
     *
     * Sources have begun answering a hotlinked poster request with a house advert instead of the
     * artwork — rongyok serves a green "rongyok.com ดูฟรีเต็มๆ" banner (2026-08-19). One banner reused
     * across every title is exactly what a duplicate hash detects, and unlike a colour or text rule it
     * needs no idea of what the next placeholder will look like: two different films never produce
     * byte-identical covers, so a collision is always a placeholder of some kind.
     *
     * Returns the number of OTHER titles already wearing this picture — 0 means keep it.
     */
    public static function duplicateTitles(string $path, Content $content): int
    {
        $hash = self::hashOf($path);
        if ($hash === null) {
            return 0;
        }

        return Content::withoutGlobalScopes()
            ->where('poster_hash', $hash)
            ->whereKeyNot($content->getKey())
            ->count();
    }

    /**
     * Throw away a just-stored cover that turns out to be a placeholder, and return null so the caller
     * treats it as "nothing found" — which leaves the branded fallback showing and puts the title in
     * the admin's missing-covers queue, both better than wearing a rival's advert.
     *
     * Applied to the AUTOMATIC paths only ([self::recover], [self::localize]). A cover an admin picked
     * or uploaded by hand goes through [self::storeFrom] and is left alone: they looked at it, and they
     * are allowed to give two titles the same picture on purpose.
     */
    private function rejectIfPlaceholder(?string $path, Content $content): ?string
    {
        if ($path === null) {
            return null;
        }
        $others = self::duplicateTitles($path, $content);
        if ($others === 0) {
            return $path;
        }

        Log::warning('cover rejected: same image already used by other titles', [
            'content_id' => $content->id,
            'source' => $content->source,
            'other_titles' => $others,
            'hash' => self::hashOf($path),
        ]);
        try {
            Storage::disk('public')->delete($path);
        } catch (\Throwable) {
            // a leftover file is untidy, not harmful — never fail the heal over it
        }

        return null;
    }

    /**
     * Download an image URL and hand back the raw bytes — the same two-attempt fetch the cover
     * pipeline uses (bare, then with the host's own Referer), exposed for callers that need the
     * bytes rather than a stored file, such as the admin image proxy.
     */
    public function fetchImage(string $url): ?string
    {
        return $this->download($url);
    }

    /** md5 of a stored image's bytes, or null when the file can't be read. */
    public static function hashOf(?string $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }
        try {
            $disk = Storage::disk('public');

            return $disk->exists($path) ? md5((string) $disk->get($path)) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * True if a stored poster URL still loads a real image THE WAY A BROWSER LOADS IT — no Referer,
     * because the cards render with referrerpolicy=no-referrer. Deliberately does NOT use download()'s
     * same-origin-Referer retry: a cover that only answers to its own site's Referer is dead to our
     * visitors even though we can still fetch it, and that's exactly the case worth healing.
     */
    public function urlAlive(?string $url): bool
    {
        if (! is_string($url) || $url === '') {
            return false;
        }
        // Local/relative (already-stored) posters are always fine — never re-fetch those.
        if (! str_starts_with($url, 'http') || str_contains($url, '/storage/')) {
            return true;
        }

        return $this->fetch($url) !== null;
    }

    /**
     * Download an image URL server-side → bytes, or null. Two attempts: first bare (mirrors the
     * browser), then with the image host's own URL as Referer. Some sources (anifume.com behind
     * Cloudflare) do the INVERSE of ordinary hotlink protection — they 403 an empty Referer and only
     * serve their own site — so the bare attempt that a browser makes can never succeed there. Pulling
     * the bytes down once, server-side, and storing them locally is what permanently fixes those.
     */
    private function download(string $url): ?string
    {
        if (($bytes = $this->fetch($url)) !== null) {
            return $bytes;
        }
        $origin = self::originOf($url);

        return $origin !== null ? $this->fetch($url, $origin) : null;
    }

    /** One HTTP attempt → image bytes, or null (non-2xx, not an image, or too small). */
    private function fetch(string $url, ?string $referer = null): ?string
    {
        $headers = ['User-Agent' => self::UA, 'Accept' => 'image/*,*/*'];
        if ($referer !== null) {
            $headers['Referer'] = $referer;
        }
        try {
            $resp = Http::withHeaders($headers)->connectTimeout(8)->timeout(25)->get($url);
        } catch (\Throwable) {
            return null;
        }
        if (! $resp->ok()) {
            return null;
        }
        $body = $resp->body();
        $ct = strtolower((string) $resp->header('Content-Type'));
        // Guard: a hotlink-blocked host often answers 200 with an HTML "denied" page — require an image.
        if (strlen($body) < 500 || (! str_starts_with($ct, 'image/') && ! self::looksLikeImage($body))) {
            return null;
        }

        return $body;
    }

    /** "https://host/" for a URL — used as the same-origin Referer on the retry. */
    private static function originOf(string $url): ?string
    {
        $p = parse_url($url);

        return isset($p['host']) ? ($p['scheme'] ?? 'https').'://'.$p['host'].'/' : null;
    }

    /** Magic-byte sniff for the common image formats (when Content-Type is missing/wrong). */
    private static function looksLikeImage(string $b): bool
    {
        return str_starts_with($b, "\xFF\xD8\xFF")            // JPEG
            || str_starts_with($b, "\x89PNG")                  // PNG
            || str_starts_with($b, 'GIF8')                     // GIF
            || (substr($b, 0, 4) === 'RIFF' && substr($b, 8, 4) === 'WEBP'); // WebP
    }
}
