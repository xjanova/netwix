<?php

namespace App\Support\Alerts;

use Carbon\CarbonInterface;
use GdImage;
use IntlBreakIterator;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Draws an [Alert] as a PNG card for Telegram: level-coloured icon and badge, the headline, the key
 * numbers as tiles, an optional bar chart and source-status pills, on the site's own dark palette.
 *
 * Everything is drawn at 2x and scaled down once at the end. GD has no anti-aliasing for filled
 * shapes, so this is what makes the corners, pills and icons smooth instead of stair-stepped.
 *
 * Thai goes through [ThaiShaper] and the fonts in resources/fonts — stock GD cannot stack a tone
 * mark over a vowel, and a card that prints ที่ as ที is worse than no card. Emoji are stripped
 * from the card (the font has none; they would draw as boxes) — the caption keeps them.
 *
 * Drawing failures never cost an alert: [self::png] returns null and the channel sends text.
 */
final class AlertCard
{
    public const WIDTH = 1080;

    private const S = 2;

    private const PAD = 56;

    private const TZ = 'Asia/Bangkok';

    private const MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

    /** Per level: the accent colour. Critical is the brand pink, OK is the site's --color-success. */
    private const ACCENT = [
        Alert::CRITICAL => [255, 45, 85],
        Alert::WARNING => [255, 176, 32],
        Alert::INFO => [104, 146, 255],
        Alert::OK => [70, 211, 105],
    ];

    private const CREAM = [244, 241, 248];

    private const BG_TOP = [22, 16, 32];

    private const BG_BOTTOM = [8, 6, 13];

    private const GOOD = [70, 211, 105];

    private const BAD = [255, 71, 87];

    private GdImage $im;

    private int $height = 0;

    private string $regular;

    private string $bold;

    /** @var array<int,callable> drawing steps, collected during layout and run once the height is known */
    private array $ops = [];

    public static function available(): bool
    {
        static $ok = null;

        return $ok ??= function_exists('imagettftext') && function_exists('imagepng')
            && self::isFont(self::fontPath('Regular')) && self::isFont(self::fontPath('Bold'));
    }

    /** PNG bytes, or null when this server cannot draw (no GD/FreeType, or the font is missing). */
    public static function png(Alert $alert, ?CarbonInterface $at = null): ?string
    {
        if (! self::available()) {
            return null;
        }
        try {
            return (new self)->render($alert, $at ?? now());
        } catch (Throwable $e) {
            Log::warning('alert-card: render failed', ['key' => $alert->key, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private static function fontPath(string $style): string
    {
        return resource_path("fonts/NotoSansThai-{$style}.ttf");
    }

    /**
     * A font file that exists is not necessarily a font: two sibling projects shipped "Sarabun.ttf"
     * files that were GitHub 404 pages, and every Thai string they drew silently vanished.
     */
    private static function isFont(string $path): bool
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $magic = fread($fh, 4);
        fclose($fh);

        return in_array($magic, ["\x00\x01\x00\x00", 'true', 'OTTO'], true);
    }

    private function __construct()
    {
        $this->regular = self::fontPath('Regular');
        $this->bold = self::fontPath('Bold');
    }

    // ----------------------------------------------------------------------------------- layout

    private function render(Alert $a, CarbonInterface $at): string
    {
        $accent = self::ACCENT[$a->level] ?? self::ACCENT[Alert::INFO];
        $w = self::WIDTH;
        $inner = $w - 2 * self::PAD;
        $y = 0;

        // Header: wordmark left, Thai date right, hairline under it.
        $this->ops[] = fn () => $this->header($at);
        $y += 104;

        // Hero: icon, level badge, headline.
        $iconSize = 92;
        $textX = self::PAD + $iconSize + 28;
        $titleLines = $this->wrap($a->title, $this->bold, 44, $w - self::PAD - $textX, 3);
        $heroTop = $y + 36;
        $this->ops[] = function () use ($a, $accent, $iconSize, $heroTop, $textX, $titleLines) {
            $this->icon($a->level, self::PAD, $heroTop, $iconSize, $accent);
            $this->badge($a->levelLabel(), $textX, $heroTop, $accent);
            $base = $heroTop + 58;
            foreach ($titleLines as $i => $line) {
                $this->text($line, $this->bold, 44, $textX, $base + 44 + $i * 62, self::CREAM);
            }
        };
        $y = $heroTop + max($iconSize, 58 + count($titleLines) * 62) + 8;

        // Body paragraph(s).
        $body = trim(str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $a->body));
        if ($body !== '') {
            $lines = $this->wrap($body, $this->regular, 28, $inner, 9);
            $top = $y + 22;
            $this->ops[] = function () use ($lines, $top) {
                $yy = $top;
                foreach ($lines as $line) {
                    if ($line === '') {
                        $yy += 18;

                        continue;
                    }
                    $this->text($line, $this->regular, 28, self::PAD, $yy + 31, self::CREAM, 0.74);
                    $yy += 44;
                }
            };
            foreach ($lines as $line) {
                $top += $line === '' ? 18 : 44;
            }
            $y = $top;
        }

        // Fact tiles.
        $facts = array_slice($a->facts, 0, 4, true);
        if ($facts !== []) {
            $top = $y + 30;
            $this->ops[] = fn () => $this->tiles($facts, $top, $accent);
            $y = $top + 122;
        }

        // Bar chart.
        $bars = array_slice(array_filter($a->bars, fn ($v) => $v > 0), 0, 6, true);
        if ($bars !== []) {
            arsort($bars);
            $top = $y + 34;
            $this->ops[] = fn () => $this->bars($bars, $a->barsLabel, $top, $accent);
            $y = $top + 40 + count($bars) * 50;
        }

        // Status pills.
        if ($a->chips !== []) {
            $top = $y + 34;
            $rows = $this->pillRows($a->chips, $inner);
            $this->ops[] = fn () => $this->pills($rows, $top);
            $y = $top + 40 + count($rows) * 58;
        }

        // Footer.
        $footTop = $y + 34;
        $this->ops[] = fn () => $this->footer($a, $footTop);
        $this->height = $footTop + 76;

        return $this->paint($accent);
    }

    private function paint(array $accent): string
    {
        $s = self::S;
        $this->im = imagecreatetruecolor(self::WIDTH * $s, $this->height * $s);
        imagealphablending($this->im, true);
        $this->background($accent);

        foreach ($this->ops as $op) {
            $op();
        }

        $out = imagecreatetruecolor(self::WIDTH, $this->height);
        imagecopyresampled($out, $this->im, 0, 0, 0, 0, self::WIDTH, $this->height, self::WIDTH * $s, $this->height * $s);

        // imagedestroy() is a no-op since PHP 8 and the queued closures hold $this, so the ~20 MB
        // 2x canvas would live until the cycle collector happened by. Drop both references now.
        $this->ops = [];
        unset($this->im);

        ob_start();
        imagepng($out, null, 6);

        return (string) ob_get_clean();
    }

    // ----------------------------------------------------------------------------------- pieces

    private function background(array $accent): void
    {
        $s = self::S;
        $w = self::WIDTH * $s;
        $h = $this->height * $s;

        for ($y = 0; $y < $h; $y++) {
            imageline($this->im, 0, $y, $w - 1, $y, $this->solid($this->bgAt($y / $s)));
        }

        // A soft glow of the level colour from the top-right corner: 40 discs at 1/127 opacity,
        // stacked so the centre builds to about a quarter strength. Drawn on a small layer and
        // scaled up — full-size discs at 2x would be ~100M pixel blends for a background.
        $k = 8;
        $gw = (int) ceil($w / $k);
        $gh = (int) ceil($h / $k);
        $glow = imagecreatetruecolor($gw, $gh);
        imagealphablending($glow, false);
        imagefilledrectangle($glow, 0, 0, $gw, $gh, imagecolorallocatealpha($glow, $accent[0], $accent[1], $accent[2], 127));
        imagealphablending($glow, true);
        $cx = (int) ($gw * 0.86);
        $cy = (int) (70 * $s / $k);
        $r = 560 * $s / $k;
        for ($i = 0; $i < 40; $i++) {
            $d = (int) round($r * (1 - $i / 40)) * 2;
            imagefilledellipse($glow, $cx, $cy, $d, $d, imagecolorallocatealpha($glow, $accent[0], $accent[1], $accent[2], 126));
        }
        imagecopyresampled($this->im, $glow, 0, 0, 0, 0, $w, $h, $gw, $gh);
        imagedestroy($glow);

        // Brand hairline across the top: NetWix pink into purple.
        for ($x = 0; $x < $w; $x++) {
            $t = $x / max(1, $w - 1);
            $c = $this->mix([255, 45, 85], [176, 38, 255], $t);
            imageline($this->im, $x, 0, $x, 6 * $s - 1, $this->solid($c));
        }
    }

    private function header(CarbonInterface $at): void
    {
        $logo = public_path('assets/netwix-wordmark.png');
        $drawn = false;
        if (is_file($logo) && ($src = @imagecreatefrompng($logo)) !== false) {
            $h = 50;
            $w = (int) round(imagesx($src) * $h / imagesy($src));
            imagecopyresampled($this->im, $src, $this->px(self::PAD - 6), $this->px(30), 0, 0, $this->px($w), $this->px($h), imagesx($src), imagesy($src));
            imagedestroy($src);
            $drawn = true;
        }
        if (! $drawn) {
            $this->text('NETWIX', $this->bold, 34, self::PAD, 70, [255, 45, 85]);
        }

        $t = $at->copy()->setTimezone(self::TZ);
        $stamp = $t->day.' '.self::MONTHS[$t->month - 1].' '.($t->year + 543).' · '.$t->format('H:i').' น.';
        $this->text($stamp, $this->regular, 24, self::WIDTH - self::PAD - $this->width($stamp, $this->regular, 24), 64, self::CREAM, 0.55);

        $this->rect(self::PAD, 103, self::WIDTH - self::PAD, 104, $this->surface(103, 0.09));
    }

    /** The level icon: a tinted disc with a drawn glyph — no emoji, so nothing can come out as a box. */
    private function icon(string $level, float $x, float $y, float $size, array $accent): void
    {
        $cx = $x + $size / 2;
        $cy = $y + $size / 2;
        // Ring = a disc in the ring colour with the tinted face drawn over it.
        $this->disc($cx, $cy, $size / 2, $this->solid($this->mix($this->bgAt($cy), $accent, 0.55)));
        $this->disc($cx, $cy, $size / 2 - 2.5, $this->solid($this->mix($this->bgAt($cy), $accent, 0.16)));
        $ink = $this->solid($accent);

        switch ($level) {
            case Alert::CRITICAL:
                $this->stroke($cx, $cy - 22, $cx, $cy + 6, 9, $ink);
                $this->disc($cx, $cy + 22, 5.5, $ink);
                break;
            case Alert::WARNING:
                $pts = [[$cx, $cy - 26], [$cx + 28, $cy + 22], [$cx - 28, $cy + 22]];
                foreach ([[0, 1], [1, 2], [2, 0]] as [$p, $q]) {
                    $this->stroke($pts[$p][0], $pts[$p][1], $pts[$q][0], $pts[$q][1], 6, $ink);
                }
                $this->stroke($cx, $cy - 9, $cx, $cy + 5, 6, $ink);
                $this->disc($cx, $cy + 14, 3.8, $ink);
                break;
            case Alert::INFO:
                // Shield with a check: "handled", which is what an INFO alert is.
                $shield = [[$cx, $cy - 28], [$cx + 24, $cy - 18], [$cx + 22, $cy + 6], [$cx, $cy + 28], [$cx - 22, $cy + 6], [$cx - 24, $cy - 18]];
                foreach ($shield as $i => $p) {
                    $q = $shield[($i + 1) % count($shield)];
                    $this->stroke($p[0], $p[1], $q[0], $q[1], 5.5, $ink);
                }
                $this->stroke($cx - 10, $cy, $cx - 2, $cy + 9, 5.5, $ink);
                $this->stroke($cx - 2, $cy + 9, $cx + 12, $cy - 8, 5.5, $ink);
                break;
            default:
                $this->stroke($cx - 18, $cy + 1, $cx - 5, $cy + 15, 8, $ink);
                $this->stroke($cx - 5, $cy + 15, $cx + 20, $cy - 13, 8, $ink);
        }
    }

    private function badge(string $label, float $x, float $y, array $accent): void
    {
        $w = $this->width($label, $this->bold, 21) + 32;
        $this->roundRect($x, $y, $w, 38, 19, $this->solid($this->mix($this->bgAt($y + 19), $accent, 0.2)));
        $this->text($label, $this->bold, 21, $x + 16, $y + 27, $accent);
    }

    /** @param array<string,string|int> $facts */
    private function tiles(array $facts, float $top, array $accent): void
    {
        $n = count($facts);
        $gap = 16;
        $inner = self::WIDTH - 2 * self::PAD;
        $w = $n === 1 ? min(460, $inner) : ($inner - ($n - 1) * $gap) / $n;
        $h = 122;
        $x = self::PAD;
        $i = 0;
        foreach ($facts as $label => $value) {
            $this->roundRect($x, $top, $w, $h, 20, $this->surface($top + $h / 2, 0.14));
            $this->roundRect($x + 1, $top + 1, $w - 2, $h - 2, 19, $this->surface($top + $h / 2, 0.05));
            $this->text($this->fit((string) $label, $this->regular, 21, $w - 44), $this->regular, 21, $x + 22, $top + 40, self::CREAM, 0.55);

            [$size, $text] = $this->shrink((string) $value, $this->bold, 38, 24, $w - 44);
            $this->text($text, $this->bold, $size, $x + 22, $top + 94, $i === 0 ? $accent : self::CREAM);
            $x += $w + $gap;
            $i++;
        }
    }

    /** @param array<string,int> $bars */
    private function bars(array $bars, string $heading, float $top, array $accent): void
    {
        $this->text($heading, $this->bold, 21, self::PAD, $top + 22, self::CREAM, 0.5);
        $max = max($bars) ?: 1;
        // Wide enough for the longest label (a title, in the daily report), within limits.
        $longest = max(array_map(fn ($l) => $this->width((string) $l, $this->regular, 25), array_keys($bars)));
        $labelW = min(440, max(170, $longest + 28));
        $valueW = 120;
        $trackX = self::PAD + $labelW;
        $trackW = self::WIDTH - self::PAD - $valueW - $trackX - 16;
        $y = $top + 40;
        foreach ($bars as $label => $value) {
            $this->text($this->fit((string) $label, $this->regular, 25, $labelW - 20), $this->regular, 25, self::PAD, $y + 32, self::CREAM, 0.85);
            $this->roundRect($trackX, $y + 16, $trackW, 16, 8, $this->surface($y + 24, 0.07));
            $fill = max(16, $trackW * $value / $max);
            $this->gradientBar($trackX, $y + 16, $fill, 16, $accent);
            $num = number_format($value);
            $this->text($num, $this->bold, 25, self::WIDTH - self::PAD - $this->width($num, $this->bold, 25), $y + 32, self::CREAM);
            $y += 50;
        }
    }

    /**
     * Flow the status pills into rows that fit the width.
     *
     * @param  array<string,bool>  $chips
     * @return array<int,array<int,array{0:string,1:bool,2:float}>>
     */
    private function pillRows(array $chips, float $maxW): array
    {
        $rows = [[]];
        $x = 0;
        foreach ($chips as $label => $ok) {
            $label = $this->fit((string) $label, $this->regular, 23, 260);
            $w = $this->width($label, $this->regular, 23) + 62;
            if ($x > 0 && $x + $w > $maxW) {
                $rows[] = [];
                $x = 0;
            }
            $rows[count($rows) - 1][] = [$label, (bool) $ok, $w];
            $x += $w + 12;
        }

        return array_slice($rows, 0, 3);
    }

    private function pills(array $rows, float $top): void
    {
        $this->text('สถานะแหล่งหนัง', $this->bold, 21, self::PAD, $top + 22, self::CREAM, 0.5);
        $y = $top + 40;
        foreach ($rows as $row) {
            $x = self::PAD;
            foreach ($row as [$label, $ok, $w]) {
                $tint = $ok ? self::GOOD : self::BAD;
                $this->roundRect($x, $y, $w, 46, 23, $this->solid($this->mix($this->bgAt($y + 23), $tint, $ok ? 0.1 : 0.2)));
                $this->disc($x + 25, $y + 23, 7, $this->solid($tint));
                $this->text($label, $this->regular, 23, $x + 42, $y + 31, self::CREAM, $ok ? 0.85 : 1.0);
                $x += $w + 12;
            }
            $y += 58;
        }
    }

    private function footer(Alert $a, float $top): void
    {
        $this->rect(self::PAD, $top, self::WIDTH - self::PAD, $top + 1, $this->surface($top, 0.09));
        $where = 'netwix.online';
        if ($a->url && ($host = parse_url($a->url, PHP_URL_HOST))) {
            $where = $host.rtrim((string) parse_url($a->url, PHP_URL_PATH), '/');
        }
        $this->text($this->fit($where, $this->regular, 21, 620), $this->regular, 21, self::PAD, $top + 48, self::CREAM, 0.45);
        $tag = $this->fit('#'.$a->key, $this->regular, 21, 330);
        $this->text($tag, $this->regular, 21, self::WIDTH - self::PAD - $this->width($tag, $this->regular, 21), $top + 48, self::CREAM, 0.3);
    }

    // ----------------------------------------------------------------------------------- text

    /**
     * Wrap into lines that fit $maxW, on real Thai word boundaries (ICU's dictionary breaker —
     * Thai has no spaces between words). Blank lines between paragraphs are kept as ''. The last
     * line gets an ellipsis when the text runs past $maxLines.
     *
     * @return array<int,string>
     */
    private function wrap(string $text, string $font, float $size, float $maxW, int $maxLines): array
    {
        $lines = [];

        foreach (explode("\n", ThaiShaper::fontSafe($text)) as $para) {
            $para = rtrim($para);
            if ($para === '') {
                if ($lines !== [] && end($lines) !== '') {
                    $lines[] = '';
                }

                continue;
            }
            $line = '';
            foreach ($this->tokens($para) as $tok) {
                if ($line !== '' && $this->width($line.$tok, $font, $size) > $maxW) {
                    $lines[] = rtrim($line);
                    $line = ltrim($tok);
                } else {
                    $line .= $tok;
                }
                // Anything still wider than a whole line (a long path, a URL) breaks by grapheme.
                while ($this->width($line, $font, $size) > $maxW) {
                    $head = $this->fit($line, $font, $size, $maxW, '');
                    if ($head === '') {
                        break;
                    }
                    $lines[] = $head;
                    $line = ltrim(substr($line, strlen($head)));
                }
                if (count($lines) > $maxLines) {
                    break 2;    // no point laying out text that will not be drawn
                }
            }
            if ($line !== '') {
                $lines[] = rtrim($line);
            }
        }

        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            while (count($lines) > 1 && end($lines) === '') {
                array_pop($lines);
            }
            $last = (string) array_pop($lines);
            $room = $maxW - $this->width(' …', $font, $size);
            $lines[] = rtrim($this->fit($last, $font, $size, $room, '')).' …';
        }

        return $lines;
    }

    /** @return array<int,string> */
    private function tokens(string $para): array
    {
        if (class_exists(IntlBreakIterator::class)) {
            $it = IntlBreakIterator::createWordInstance('th');
            $it->setText($para);
            $parts = iterator_to_array($it->getPartsIterator(), false);
            if ($parts !== []) {
                return $parts;
            }
        }

        return preg_split('/(\s+)/u', $para, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$para];
    }

    /** Trim $text by grapheme until it (plus $ellipsis) fits $maxW. */
    private function fit(string $text, string $font, float $size, float $maxW, string $ellipsis = '…'): string
    {
        $text = ThaiShaper::fontSafe($text);
        if ($this->width($text, $font, $size) <= $maxW) {
            return $text;
        }
        $g = preg_split('/(\X)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        while ($g !== []) {
            array_pop($g);
            $try = rtrim(implode('', $g)).$ellipsis;
            if ($this->width($try, $font, $size) <= $maxW) {
                return $try;
            }
        }

        return $ellipsis;
    }

    /** Largest size in [$min, $max] at which $text fits, else the min size with an ellipsis. */
    private function shrink(string $text, string $font, float $max, float $min, float $maxW): array
    {
        $text = ThaiShaper::fontSafe($text);
        for ($size = $max; $size >= $min; $size -= 2) {
            if ($this->width($text, $font, $size) <= $maxW) {
                return [$size, $text];
            }
        }

        return [$min, $this->fit($text, $font, $min, $maxW)];
    }

    /** Ink width in output pixels, measured at the drawing scale for precision. */
    private function width(string $text, string $font, float $size): float
    {
        if ($text === '') {
            return 0.0;
        }
        $box = imagettfbbox($this->pt($size), 0, $font, ThaiShaper::shape($text));

        return $box === false ? 0.0 : (max($box[2], $box[4]) - min($box[0], $box[6])) / self::S;
    }

    private function text(string $text, string $font, float $size, float $x, float $baseline, array $rgb, float $opacity = 1.0): void
    {
        $text = ThaiShaper::shape(ThaiShaper::fontSafe($text));
        if ($text === '') {
            return;
        }
        $color = imagecolorallocatealpha($this->im, $rgb[0], $rgb[1], $rgb[2], (int) round(127 * (1 - $opacity)));
        imagettftext($this->im, $this->pt($size), 0, $this->px($x), $this->px($baseline), $color, $font, $text);
    }

    /** GD sizes text in points at 96 dpi; the layout is in pixels. */
    private function pt(float $px): float
    {
        return $px * self::S * 0.75;
    }

    // ----------------------------------------------------------------------------------- shapes

    private function px(float $v): int
    {
        return (int) round($v * self::S);
    }

    private function rect(float $x1, float $y1, float $x2, float $y2, int $color): void
    {
        imagefilledrectangle($this->im, $this->px($x1), $this->px($y1), $this->px($x2) - 1, $this->px($y2) - 1, $color);
    }

    /**
     * Rounded rectangle in an OPAQUE colour. Semi-transparent fills are avoided on purpose: the
     * rectangles and corner discs overlap, and GD would blend the overlap twice.
     */
    private function roundRect(float $x, float $y, float $w, float $h, float $r, int $color): void
    {
        $r = min($r, $w / 2, $h / 2);
        $this->rect($x + $r, $y, $x + $w - $r, $y + $h, $color);
        $this->rect($x, $y + $r, $x + $w, $y + $h - $r, $color);
        foreach ([[$x + $r, $y + $r], [$x + $w - $r, $y + $r], [$x + $r, $y + $h - $r], [$x + $w - $r, $y + $h - $r]] as [$cx, $cy]) {
            $this->disc($cx, $cy, $r, $color);
        }
    }

    private function gradientBar(float $x, float $y, float $w, float $h, array $accent): void
    {
        $light = $this->mix($accent, [255, 255, 255], 0.35);
        $steps = max(1, (int) ($w * self::S));
        $r = $h / 2;
        // Paint the fill column by column; the rounded ends are two discs in the end colours.
        for ($i = 0; $i < $steps; $i++) {
            $t = $i / $steps;
            $col = $this->solid($this->mix($accent, $light, $t));
            $px = (int) round($x * self::S) + $i;
            $inset = $this->capInset($i, $steps, $r * self::S);
            imageline($this->im, $px, $this->px($y) + $inset, $px, $this->px($y + $h) - 1 - $inset, $col);
        }
    }

    /** How far a column near either end of a rounded bar sits inside the cap's circle. */
    private function capInset(int $i, int $steps, float $r): int
    {
        $d = min($i, $steps - 1 - $i);
        if ($d >= $r) {
            return 0;
        }
        $dx = $r - $d - 0.5;

        return (int) round($r - sqrt(max(0.0, $r * $r - $dx * $dx)));
    }

    private function disc(float $cx, float $cy, float $r, int $color): void
    {
        $d = $this->px(2 * $r);
        imagefilledellipse($this->im, $this->px($cx), $this->px($cy), $d, $d, $color);
    }

    /** A thick line with round caps: a quad for the body plus a disc at each end. */
    private function stroke(float $x1, float $y1, float $x2, float $y2, float $w, int $color): void
    {
        $len = hypot($x2 - $x1, $y2 - $y1) ?: 1;
        $nx = -($y2 - $y1) / $len * $w / 2;
        $ny = ($x2 - $x1) / $len * $w / 2;
        imagefilledpolygon($this->im, [
            $this->px($x1 + $nx), $this->px($y1 + $ny),
            $this->px($x2 + $nx), $this->px($y2 + $ny),
            $this->px($x2 - $nx), $this->px($y2 - $ny),
            $this->px($x1 - $nx), $this->px($y1 - $ny),
        ], $color);
        $this->disc($x1, $y1, $w / 2, $color);
        $this->disc($x2, $y2, $w / 2, $color);
    }

    // ----------------------------------------------------------------------------------- colour

    private function solid(array $rgb): int
    {
        return imagecolorallocate($this->im, (int) $rgb[0], (int) $rgb[1], (int) $rgb[2]);
    }

    /** White at $opacity over the background at output-y $y, as one opaque colour. */
    private function surface(float $y, float $opacity): int
    {
        return $this->solid($this->mix($this->bgAt($y), [255, 255, 255], $opacity));
    }

    private function bgAt(float $y): array
    {
        return $this->mix(self::BG_TOP, self::BG_BOTTOM, $this->height > 0 ? min(1, max(0, $y / $this->height)) : 0);
    }

    private function mix(array $a, array $b, float $t): array
    {
        return [
            (int) round($a[0] + ($b[0] - $a[0]) * $t),
            (int) round($a[1] + ($b[1] - $a[1]) * $t),
            (int) round($a[2] + ($b[2] - $a[2]) * $t),
        ];
    }
}
