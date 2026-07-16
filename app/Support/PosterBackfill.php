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
            $path = ImageStore::putCover($bytes, 'media/posters', (string) $content->id, $content->poster_path);
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

    /** True if a stored poster URL still loads a real image (used by the --check sweep). */
    public function urlAlive(?string $url): bool
    {
        if (! is_string($url) || $url === '') {
            return false;
        }
        // Local/relative (already-stored) posters are always fine — never re-fetch those.
        if (! str_starts_with($url, 'http') || str_contains($url, '/storage/')) {
            return true;
        }

        return $this->download($url) !== null;
    }

    /** Download an image URL server-side → bytes, or null (non-2xx, not an image, or too small). */
    private function download(string $url): ?string
    {
        try {
            // No Referer — mirrors the browser's referrerpolicy=no-referrer, which bypasses most
            // referer-based hotlink protection (the same reason cards load these images at all).
            $resp = Http::withHeaders(['User-Agent' => self::UA, 'Accept' => 'image/*,*/*'])
                ->connectTimeout(8)->timeout(25)->get($url);
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

    /** Magic-byte sniff for the common image formats (when Content-Type is missing/wrong). */
    private static function looksLikeImage(string $b): bool
    {
        return str_starts_with($b, "\xFF\xD8\xFF")            // JPEG
            || str_starts_with($b, "\x89PNG")                  // PNG
            || str_starts_with($b, 'GIF8')                     // GIF
            || (substr($b, 0, 4) === 'RIFF' && substr($b, 8, 4) === 'WEBP'); // WebP
    }
}
