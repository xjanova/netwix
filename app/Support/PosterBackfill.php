<?php

namespace App\Support;

use App\Models\Content;
use App\Models\SourceTitle;
use App\Services\Import\Contracts\ProvidesPoster;
use App\Services\Import\RemoteSeries;
use App\Services\Import\SourceRegistry;
use Illuminate\Support\Facades\Http;

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
            if ($path !== null) {
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
            if ($path !== null) {
                return $path;
            }
        }

        return $this->recover($content);
    }

    /**
     * Persist a recovered cover. The backdrop follows it whenever it was the SAME image — import seeds
     * both fields from one source URL (23,702 of 23,761 titles), so a poster that just turned out to be
     * dead means the backdrop behind the hero and the title modal is the identical dead URL. Healing
     * only the poster would leave those rendering a bare gradient.
     */
    public function apply(Content $content, string $path): void
    {
        $old = (string) $content->poster_path;
        $updates = ['poster_path' => $path];
        if (blank($content->backdrop_path) || (string) $content->backdrop_path === $old) {
            $updates['backdrop_path'] = $path;
        }
        $content->forceFill($updates)->save();
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
