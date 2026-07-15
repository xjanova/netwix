<?php

namespace App\Support;

use App\Http\Controllers\StreamController;
use App\Models\Episode;
use App\Models\MarketingClip;
use App\Services\Import\SourceRegistry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Cuts a short marketing clip out of a title/episode with ffmpeg — server-side, so it
 * works for every source (incl. cross-origin HLS the browser can't touch).
 *
 * Two hard constraints on the prod box (see brain: "NetWix server — ffmpeg …"):
 *   1. php-fpm has proc_open/exec DISABLED, so this MUST run from a CLI queue worker.
 *   2. the static ffmpeg SEGFAULTS on any https input, so we NEVER hand it a URL — we
 *      download the media locally first and run ffmpeg on the local file.
 *
 * To avoid pulling a whole movie just to cut 30s, HLS sources download only the handful
 * of .ts segments that cover [start, start+duration]; we concat their raw bytes (MPEG-TS
 * concatenates cleanly) and seek the small offset into the first segment. Output is a
 * FB-ready mp4 (vertical/square/landscape, +faststart) at media/clips/{id}.mp4, plus a
 * webp poster still.
 */
class ClipMaker
{
    /** Never pull more than this from a direct (non-HLS) mp4 source for a SHORT clip. */
    private const MP4_MAX_BYTES = 350 * 1024 * 1024;

    /** Source-download ceiling for a FULL-EPISODE cut (mp4 or summed HLS segments) — guards disk. */
    private const FULL_SRC_MAX_BYTES = 3 * 1024 * 1024 * 1024;

    /** Encoded output ceiling — Facebook's hosted file_url upload tops out around 1GB. */
    private const OUT_MAX_BYTES = 980 * 1024 * 1024;

    /** Silent stereo audio source, used to give the outro card (or a silent clip) an audio leg. */
    private const SILENCE = 'anullsrc=r=44100:cl=stereo';

    public function __construct(private SourceRegistry $registry) {}

    /**
     * Produce + store the clip. Returns a status code:
     *   ok | no_source | download_failed | too_large | ffmpeg_failed | error
     * On ok the model is updated (status=ready, file_path, poster_path, file_size).
     */
    public function make(MarketingClip $clip): string
    {
        @ini_set('memory_limit', '1024M');   // a minute of 720p segments + encode headroom

        // Load the episode with ALL its columns (source/source_ref/video_url) — the job's
        // eager-load is column-limited for the label, which isn't enough to resolve a stream.
        $episode = $clip->episode_id
            ? Episode::with('content:id,source_key,outro_seconds')->find($clip->episode_id)
            : Episode::with('content:id,source_key,outro_seconds')->where('content_id', $clip->content_id)->orderBy('sort')->first();
        if (! $episode) {
            return $this->fail($clip, 'no_source');
        }

        $url = $this->playableUrl($episode);
        if (! $url) {
            return $this->fail($clip, 'no_source');
        }

        $start = max(0, (int) $clip->start);
        // duration <= 0 is the "ทั้งตอน" sentinel: no -t cut, every HLS segment, whole file.
        $full = (int) $clip->duration <= 0;
        $dur = $full ? 0 : max(5, min(600, (int) $clip->duration));

        // "ending" clips take the LAST $dur seconds. The window is resolved from the real
        // media (playlist sum / probe), and stops short of the end credits when the title has
        // them marked — so the clip ends on the cliffhanger instead of a credits roll, which
        // is the entire point of teasing the end of an episode.
        $fromEnd = ! $full && $clip->start_mode === 'ending';
        $trimEnd = $fromEnd ? max(0, (int) ($episode->content?->outro_seconds ?? 0)) : 0;

        // ---- 1. get the source window onto local disk --------------------------
        [$srcPath, $seekOffset] = $this->fetchWindow($url, $start, $dur, $full, $fromEnd, $trimEnd);
        if ($srcPath === null) {
            // transient CDN hiccup on a burst — re-resolve a fresh link + retry once
            usleep(700_000);
            $retry = $this->playableUrl($episode);
            [$srcPath, $seekOffset] = $retry ? $this->fetchWindow($retry, $start, $dur, $full, $fromEnd, $trimEnd) : [null, 0];
            if ($srcPath === null) {
                return $this->fail($clip, 'download_failed');
            }
        }

        $out = $srcPath.'.mp4';
        try {
            // ---- 2. cut + re-encode to a FB-ready file -------------------------
            if (! $this->encode($srcPath, $out, $seekOffset, $dur, (string) $clip->aspect, $full)) {
                return $this->fail($clip, 'ffmpeg_failed');
            }
            $size = is_file($out) ? (int) filesize($out) : 0;
            if ($size < 2000) {
                return $this->fail($clip, 'ffmpeg_failed');
            }
            if ($size > self::OUT_MAX_BYTES) {
                return $this->fail($clip, 'too_large');   // FB can't fetch a hosted file this big
            }

            // Stream the file into storage — a full episode is far too big to slurp into RAM.
            $path = 'media/clips/'.$clip->id.'.mp4';
            $put = Storage::disk('public')->putFileAs('media/clips', new \Illuminate\Http\File($out), $clip->id.'.mp4');
            if ($put === false) {
                return $this->fail($clip, 'error');
            }

            // ---- 3. a poster still (local mp4 input — always safe for ffmpeg) --
            $poster = $this->poster($out, $clip->id);

            $clip->update([
                'status' => 'ready',
                'error' => null,
                'file_path' => $path,
                'poster_path' => $poster,
                'file_size' => $size,
            ]);

            return 'ok';
        } catch (Throwable $e) {
            report($e);

            return $this->fail($clip, 'error');
        } finally {
            @unlink($srcPath);
            @unlink($out);
        }
    }

    // ---- source window ------------------------------------------------------

    /**
     * Download the media covering [start, start+dur] to a local temp file.
     * Returns [localPath, seekOffsetSeconds] — seekOffset is how far into that local
     * file the clip actually starts (0 for a whole-file mp4; the fraction of the first
     * segment for HLS). Returns [null, 0] on failure.
     *
     * @return array{0: ?string, 1: float}
     */
    private function fetchWindow(string $url, int $start, int $dur, bool $full = false, bool $fromEnd = false, int $trimEnd = 0): array
    {
        try {
            if (str_contains($url, '.m3u8')) {
                return $this->fetchHlsWindow($url, $start, $dur, $full, $fromEnd, $trimEnd);
            }

            // Direct mp4/file: no cheap way to time-seek a remote file, and ffmpeg
            // can't take the https URL — so pull the whole thing (bounded) and let
            // ffmpeg seek locally. Fine for our own mirrored files; guarded for size.
            $head = Http::timeout(20)->withOptions(['stream' => true])->get($url);
            $len = (int) $head->header('Content-Length');
            if ($len > ($full ? self::FULL_SRC_MAX_BYTES : self::MP4_MAX_BYTES)) {
                return [null, 0.0];  // caller maps a null to download_failed; logged size guard
            }
            $tmp = $this->tmp();
            // sink() streams straight to disk — the file never sits in PHP memory.
            $resp = Http::timeout($full ? 900 : 180)->sink($tmp)->get($url);
            if (! $resp->successful() || ! is_file($tmp) || filesize($tmp) < 2000) {
                @unlink($tmp);

                return [null, 0.0];
            }

            // Whole file on disk → its real length is only knowable by probing it.
            if ($fromEnd) {
                $total = $this->probe($tmp)['duration'];
                $start = $total > 0 ? max(0, (int) round($total - $trimEnd - $dur)) : 0;
            }

            return [$tmp, (float) $start];   // whole file downloaded → seek the real start
        } catch (Throwable $e) {
            report($e);

            return [null, 0.0];
        }
    }

    /**
     * HLS: parse the media playlist, pick the segment run covering the window, download
     * + strip + concat just those segments. Returns [localTsPath, offsetIntoFirstSeg].
     *
     * @return array{0: ?string, 1: float}
     */
    private function fetchHlsWindow(string $m3u8Url, int $start, int $dur, bool $full = false, bool $fromEnd = false, int $trimEnd = 0): array
    {
        $segments = $this->mediaSegments($m3u8Url);
        if (empty($segments)) {
            return [null, 0.0];
        }

        // The playlist knows the exact length (sum of #EXTINF) — no probing needed, and it is
        // authoritative where contents.duration_minutes is rounded or missing. A short episode
        // (total <= dur) collapses to start=0, i.e. the whole episode, which is what we want.
        if ($fromEnd) {
            $total = array_sum(array_column($segments, 'dur'));
            $start = max(0, (int) round($total - $trimEnd - $dur));
        }

        if ($full) {
            // Whole episode: every segment, from the top.
            $picked = array_column($segments, 'url');
            $firstOffset = 0.0;
        } else {
            // Walk cumulative time to find the run of segments overlapping [start, start+dur].
            $tail = 1.5;                        // grab a hair extra so -t never runs short
            $need = $start + $dur + $tail;
            $cursor = 0.0;
            $firstOffset = 0.0;
            $picked = [];
            foreach ($segments as $seg) {
                $segStart = $cursor;
                $segEnd = $cursor + $seg['dur'];
                $cursor = $segEnd;

                if ($segEnd <= $start) {
                    continue;                   // entirely before the window
                }
                if (empty($picked)) {
                    $firstOffset = max(0.0, $start - $segStart);   // where the clip starts inside seg #0
                }
                $picked[] = $seg['url'];
                if ($segStart >= $need) {
                    break;                      // covered the whole window
                }
            }
            if (empty($picked)) {
                // start past the end of the video — fall back to the first segments
                $picked = array_slice(array_column($segments, 'url'), 0, 3);
                $firstOffset = 0.0;
            }
        }

        $tmp = $this->tmp();
        $fh = @fopen($tmp, 'wb');
        if (! $fh) {
            return [null, 0.0];
        }
        $got = 0;
        $written = 0;
        foreach ($picked as $segUrl) {
            $data = $this->fetchSegment($segUrl);
            if ($data !== null) {
                fwrite($fh, $data);
                $written += strlen($data);
                $got++;
            }
            if ($written > self::FULL_SRC_MAX_BYTES) {
                fclose($fh);
                @unlink($tmp);

                return [null, 0.0];             // source bigger than the disk guard allows
            }
        }
        fclose($fh);

        if ($got === 0 || filesize($tmp) < 2000) {
            @unlink($tmp);

            return [null, 0.0];
        }

        return [$tmp, $firstOffset];
    }

    /**
     * Fetch + parse an HLS playlist into [{url, dur}]. Follows one level of a master
     * playlist (variant list) down to the media playlist.
     *
     * @return array<int, array{url: string, dur: float}>
     */
    private function mediaSegments(string $m3u8Url, int $depth = 0): array
    {
        try {
            $body = Http::timeout(25)->get($m3u8Url)->body();
        } catch (Throwable $e) {
            return [];
        }
        if ($body === '' || ! str_contains($body, '#EXT')) {
            return [];
        }

        // Master playlist → recurse into the first variant.
        if (str_contains($body, '#EXT-X-STREAM-INF') && $depth < 2) {
            foreach (preg_split('/\r?\n/', $body) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                return $this->mediaSegments($this->absolute($line, $m3u8Url), $depth + 1);
            }

            return [];
        }

        $segments = [];
        $dur = 0.0;
        foreach (preg_split('/\r?\n/', $body) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '#EXTINF:')) {
                $dur = (float) trim(substr($line, 8), " ,");
                continue;
            }
            if (str_starts_with($line, '#')) {
                continue;
            }
            $segments[] = ['url' => $this->absolute($line, $m3u8Url), 'dur' => $dur > 0 ? $dur : 6.0];
            $dur = 0.0;
        }

        return $segments;
    }

    /** Download one segment, stripping any fake-image header disguise (to first 0x47). */
    private function fetchSegment(string $url): ?string
    {
        try {
            $resp = Http::timeout(60)->get($url);
            if (! $resp->successful()) {
                return null;
            }
            $data = $resp->body();
            if (strlen($data) < 200) {
                return null;
            }
            // Some CDNs (and our own anti-hotlink proxy) prepend a fake image header;
            // the real MPEG-TS starts at the first sync byte 0x47.
            $pos = strpos($data, "\x47");
            if ($pos !== false && $pos > 0 && $pos < 512) {
                $data = substr($data, $pos);
            }

            return $data;
        } catch (Throwable $e) {
            return null;
        }
    }

    // ---- encode -------------------------------------------------------------

    /** Cut [offset, offset+dur] from the local source and re-encode FB-ready. */
    private function encode(string $src, string $out, float $offset, int $dur, string $aspect, bool $full = false): bool
    {
        @unlink($out);
        $bin = config('services.ffmpeg.bin', '/home/admin/bin/ffmpeg');
        $probe = $this->probe($src);

        // Everything downstream needs the EXACT output size: the outro card is concatenated to
        // the clip, and concat refuses streams whose dimensions differ. Cropped modes are fixed;
        // a full episode keeps its source shape, so its size comes from the probe.
        [$w, $h] = $this->outputSize($aspect, $full, $probe);

        // Vertical (9:16) output from a LANDSCAPE source is filled with a blurred, zoomed copy of
        // the frame top+bottom instead of cropping the movie — the whole scene stays visible
        // (owner: "เสริมภาพให้เต็มบนล่าง ไม่ใช่การครอปหนัง"). A source that is already vertical
        // (rongyok) fills 9:16 on its own, so it skips the blur (pure waste there).
        $blurPad = $aspect === '9:16' && ! $full && $this->isLandscape($probe);
        $clipGraph = $this->clipVideoGraph('[0:v:0]', $blurPad, $full, $w, $h);   // → [cv]

        $outro = app(ClipOutro::class);
        $card = ($w > 0 && $h > 0) ? $outro->card($full ? '16:9' : $aspect) : null;

        $args = [
            $bin, '-y', '-nostdin', '-threads', '4',
            '-ss', number_format($offset, 3, '.', ''),
        ];
        // -t BEFORE -i makes it an INPUT limit (this clip only). It must not be an output
        // option here: with the outro concatenated on the end, an output -t would chop the
        // outro back off again.
        if (! $full) {
            array_push($args, '-t', (string) $dur);
        }
        array_push($args, '-i', $src);

        $codec = [
            '-c:v', 'libx264', '-preset', $full ? 'superfast' : 'veryfast', '-crf', '23', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '128k', '-ar', '44100',
            '-movflags', '+faststart',
            '-avoid_negative_ts', 'make_zero',
            $out,
        ];

        array_push($args, ...$this->filterArgs($clipGraph, $card, $outro->seconds(), $dur, $w, $h, $full, $probe), ...$codec);

        try {
            // Long cuts legitimately encode for a long time (still nice/ionice'd + 4 threads, so
            // they only ever use idle CPU — see the 2026-07-06 incident note). This budget MUST
            // scale with the clip: a flat 240s silently killed every 5-minute cut mid-encode and
            // reported it as ffmpeg_failed. The job timeout on each lane is the real backstop.
            $res = Process::timeout($this->encodeBudget($dur, $full))->run(Ffmpeg::cmd($args));
        } catch (Throwable $e) {
            Log::warning('clip encode timed out', ['out' => basename($out), 'error' => $e->getMessage()]);

            return false;
        }

        if (is_file($out) && filesize($out) >= 2000) {
            return true;
        }

        // Log WHY. ffmpeg's complaint only lives on stderr, and silently dropping it turned
        // every failure into a bare "ffmpeg_failed" with nothing to debug.
        Log::warning('clip encode failed', [
            'out' => basename($out),
            'exit' => $res->exitCode(),
            'stderr' => mb_substr(trim($res->errorOutput() ?: $res->output()), -800),
        ]);

        return false;
    }

    /**
     * Seconds to allow the encode. Roughly 12x realtime — an idle-priority libx264 veryfast pass
     * on this shared box runs a few times faster than realtime, and the margin covers the box
     * being busy. Capped just under the clips-heavy job timeout (5400s) so the queue, not the
     * process, owns the final say.
     */
    private function encodeBudget(int $dur, bool $full): int
    {
        return $full ? 5100 : min(5100, max(240, $dur * 12 + 60));
    }

    /** True when the source frame is wider than the 9:16 target (i.e. needs top/bottom fill). */
    private function isLandscape(array $probe): bool
    {
        // Unknown dimensions → assume it needs the pad (safe: a vertical source under blurred-pad
        // still looks right, just costs a little more; a landscape source without it would crop).
        if ($probe['width'] < 2 || $probe['height'] < 2) {
            return true;
        }

        return ($probe['width'] / $probe['height']) > (9 / 16) + 0.02;
    }

    /**
     * The sub-graph that turns the source video [in] into the final WxH clip frame [cv],
     * ready to feed the encoder or the outro concat. Every branch ends in the SAME normalisation
     * (fps/format/setsar) so concat never rejects a mismatched stream.
     *
     *  - blurPad (9:16 from a landscape source): a blurred, zoomed copy fills the frame while the
     *    whole scene sits letterboxed on top — no cropping. Uses split/overlay, which is why the
     *    whole encode runs through filter_complex now, not a linear -vf.
     *  - full episode: keep the source shape, just conformed to WxH.
     *  - otherwise: cover-and-crop to fill (square / 16:9 / already-vertical 9:16).
     */
    private function clipVideoGraph(string $in, bool $blurPad, bool $full, int $w, int $h): string
    {
        $post = ',fps=30,format=yuv420p,setsar=1[cv]';

        if ($full) {
            return "{$in}scale={$w}:{$h}{$post}";
        }
        if ($blurPad) {
            return "{$in}split=2[bgpre][fgpre];"
                ."[bgpre]scale={$w}:{$h}:force_original_aspect_ratio=increase,crop={$w}:{$h},gblur=sigma=18[bgblur];"
                ."[fgpre]scale={$w}:{$h}:force_original_aspect_ratio=decrease[fgfit];"
                ."[bgblur][fgfit]overlay=(W-w)/2:(H-h)/2{$post}";
        }

        return "{$in}scale={$w}:{$h}:force_original_aspect_ratio=increase,crop={$w}:{$h}{$post}";
    }

    /**
     * Assemble the `-filter_complex` (+ any extra inputs + `-map`s) for the encode. Always uses
     * filter_complex — the blurred-pad graph and the outro concat both need labelled pads.
     *
     * When an outro card is present it is concatenated onto the end IN THE SAME PASS (a second
     * pass would re-encode the whole clip and can outrun the worker). The card is scaled with
     * plain numbers, NOT scale2ref: scale2ref walks both inputs in lockstep, so a 4-second card
     * starves the reference passthrough after ~100 frames and the graph deadlocks
     * ("buffers queued in out_0_0"). concat also demands identical audio params, and a silent
     * source has no audio stream at all — so both legs are normalised and a missing clip track
     * is replaced with matching silence.
     *
     * @param  array{duration: float, hasAudio: bool, width: int, height: int}  $probe
     * @return array<int, string>
     */
    private function filterArgs(string $clipGraph, ?string $card, int $outroSec, int $dur, int $w, int $h, bool $full, array $probe): array
    {
        $norm = 'aresample=44100,aformat=sample_fmts=fltp:channel_layouts=stereo';
        $inputs = [];

        // The clip's own audio leg — real track, or silence matched to the clip's length.
        $clipAudio = '[0:a:0]';
        if (! $probe['hasAudio']) {
            $silence = $full ? max(1, (int) ceil($probe['duration'])) : $dur;
            $inputs = array_merge($inputs, ['-f', 'lavfi', '-t', (string) $silence, '-i', self::SILENCE]);
            $clipAudio = '['.(count($inputs) / 4).':a]';   // this lavfi input's index (each is 4 argv)
        }

        if ($card === null) {
            // No branding card: just the clip video + its (real or silent) audio.
            $graph = $clipGraph.";{$clipAudio}{$norm}[a]";

            return array_merge($inputs, ['-filter_complex', $graph, '-map', '[cv]', '-map', '[a]']);
        }

        // Card present → append it. Card inputs come AFTER the source (input 0), so index them
        // by counting: source(0) then the card image + card silence, then any clip-silence lavfi.
        $cardV = 1;
        $cardA = 2;
        $inputs = array_merge([
            '-loop', '1', '-t', (string) $outroSec, '-i', $card,          // input 1 = card image
            '-f', 'lavfi', '-t', (string) $outroSec, '-i', self::SILENCE, // input 2 = card audio
        ], $inputs);
        if (! $probe['hasAudio']) {
            $clipAudio = '[3:a]';   // the clip-silence lavfi is now input 3 (after card image+audio)
        }

        $graph = $clipGraph.';'
            ."[{$cardV}:v]scale={$w}:{$h},fps=30,format=yuv420p,setsar=1[ov];"
            ."{$clipAudio}{$norm}[ca];"
            ."[{$cardA}:a]{$norm}[oa];"
            .'[cv][ca][ov][oa]concat=n=2:v=1:a=1[v][a]';

        return array_merge($inputs, ['-filter_complex', $graph, '-map', '[v]', '-map', '[a]']);
    }

    /**
     * Exact pixel size of the encode's output. Cropped modes are fixed by their aspect; a full
     * episode keeps its source shape, bounded to 1280 wide and never upscaled — [0,0] when the
     * source dimensions couldn't be read, which tells the caller to skip the outro.
     *
     * @param  array{duration: float, hasAudio: bool, width: int, height: int}  $probe
     * @return array{0: int, 1: int}
     */
    private function outputSize(string $aspect, bool $full, array $probe): array
    {
        if (! $full) {
            return match ($aspect) {
                '1:1' => [1080, 1080],
                '16:9' => [1280, 720],
                default => [720, 1280],   // 9:16 vertical (Reels/TikTok)
            };
        }
        if ($probe['width'] < 2 || $probe['height'] < 2) {
            return [0, 0];
        }

        $w = min(1280, $probe['width']);
        $h = (int) (round($probe['height'] * $w / $probe['width'] / 2) * 2);   // even, like ffmpeg's -2

        return [$w, max(2, $h)];
    }

    /**
     * Read a local file's duration + whether it carries audio. There is NO ffprobe on this box
     * (only the static ffmpeg), so this parses `ffmpeg -i`, which prints the stream summary to
     * stderr and exits non-zero because no output file was given — that is expected, not an error.
     *
     * @return array{duration: float, hasAudio: bool, width: int, height: int}
     */
    private function probe(string $file): array
    {
        try {
            $res = Process::timeout(60)->run(Ffmpeg::cmd([Ffmpeg::bin(), '-hide_banner', '-i', $file]));
            $text = $res->errorOutput().$res->output();
        } catch (Throwable $e) {
            return ['duration' => 0.0, 'hasAudio' => true, 'width' => 0, 'height' => 0];
        }

        $duration = 0.0;
        if (preg_match('/Duration:\s*(\d+):(\d\d):(\d\d(?:\.\d+)?)/', $text, $m)) {
            $duration = ((int) $m[1] * 3600) + ((int) $m[2] * 60) + (float) $m[3];
        }

        // e.g. "Stream #0:0[0x100]: Video: h264 (High) ..., yuv420p(tv, bt709), 1920x1080 [SAR 1:1 ...]"
        $width = $height = 0;
        if (preg_match('/Video:.*?[\s,](\d{2,5})x(\d{2,5})[\s,\[]/', $text, $m)) {
            $width = (int) $m[1];
            $height = (int) $m[2];
        }

        return [
            'duration' => $duration,
            'hasAudio' => (bool) preg_match('/Stream #\d+:\d+.*: Audio:/', $text),
            'width' => $width,
            'height' => $height,
        ];
    }

    /** Grab a still from the finished LOCAL mp4 for the admin thumbnail. */
    private function poster(string $localMp4, int $clipId): ?string
    {
        $jpg = $localMp4.'.poster.jpg';
        $bin = config('services.ffmpeg.bin', '/home/admin/bin/ffmpeg');
        try {
            Process::timeout(30)->run(Ffmpeg::cmd([
                $bin, '-y', '-nostdin', '-ss', '1', '-i', $localMp4,
                '-frames:v', '1', '-q:v', '4', $jpg,
            ]));
            $data = @file_get_contents($jpg);
            if ($data !== false && strlen($data) > 400) {
                return ImageStore::putWebp($data, 'media/clips', 'poster-'.$clipId, 640);
            }
        } catch (Throwable $e) {
            // poster is a nicety — never fail the clip over it
        } finally {
            @unlink($jpg);
        }

        return null;
    }

    // ---- helpers ------------------------------------------------------------

    private function fail(MarketingClip $clip, string $status): string
    {
        $clip->update(['status' => 'failed', 'error' => $status]);

        return $status;
    }

    private function tmp(): string
    {
        return sys_get_temp_dir().'/nwclip_'.bin2hex(random_bytes(6));
    }

    /** Resolve a relative playlist/segment line against its playlist URL. */
    private function absolute(string $ref, string $baseUrl): string
    {
        if (preg_match('~^https?://~i', $ref)) {
            return $ref;
        }
        $p = parse_url($baseUrl);
        $scheme = $p['scheme'] ?? 'https';
        $host = $p['host'] ?? '';
        $port = isset($p['port']) ? ':'.$p['port'] : '';
        if (str_starts_with($ref, '/')) {
            return "{$scheme}://{$host}{$port}{$ref}";
        }
        $dir = preg_replace('~/[^/]*$~', '/', $p['path'] ?? '/');

        return "{$scheme}://{$host}{$port}{$dir}{$ref}";
    }

    /**
     * A directly-fetchable URL for an episode's video. Mirrors
     * EpisodeThumbnailer::playableUrl (kept local so the proven cover path stays
     * untouched): prefer a stored video_url, else resolve the upstream source link.
     */
    private function playableUrl(\App\Models\Episode $episode): ?string
    {
        if ($episode->video_url) {
            return $episode->video_url;
        }
        if (in_array($episode->source, ['wowdrama', 'anime108'], true)) {
            // These sources only play through our own rewriting proxy, and that route rejects
            // any request without a minted token (403) — which is exactly why clips for them
            // used to die on download_failed. We are server-side and hold app.key, so mint one
            // the same way the player's resolver does. ~80k episodes ride on this.
            return route('stream.manifest', ['episode' => $episode, 't' => StreamController::token($episode)]);
        }
        $source = $this->registry->get((string) $episode->source);
        $seriesKey = $episode->content?->source_key;
        if (! $source || ! $seriesKey || ! $episode->source_ref) {
            return null;
        }

        return $source->resolveByRef((string) $seriesKey, (string) $episode->source_ref)?->url;
    }
}
