<?php

namespace App\Services\Import;

/**
 * rongyok.com renders its data as JavaScript literals embedded in the page
 * (e.g. `seriesData = [ {...} ]`, or an `"episodes":[…]` array in the watch page).
 * This pulls a balanced bracketed literal out of the surrounding script text,
 * respecting string escapes so brackets inside Thai text don't confuse it.
 *
 * Operates on raw bytes: the only structural chars it inspects are ASCII
 * (`" \ [ ] { }`); UTF-8 continuation bytes are all >= 0x80 so never collide.
 * Port of the C# JsonExtract from the Hive Download project.
 */
class JsonExtract
{
    public static function balancedAfter(string $source, string $marker, string $open): ?string
    {
        $close = $open === '[' ? ']' : '}';

        $at = strpos($source, $marker);
        if ($at === false) {
            return null;
        }

        $i = $at + strlen($marker);
        $len = strlen($source);
        while ($i < $len && $source[$i] !== $open) {
            $i++;
        }
        if ($i >= $len) {
            return null;
        }

        $start = $i;
        $depth = 0;
        $inStr = false;
        $esc = false;

        for (; $i < $len; $i++) {
            $ch = $source[$i];
            if ($inStr) {
                if ($esc) {
                    $esc = false;
                } elseif ($ch === '\\') {
                    $esc = true;
                } elseif ($ch === '"') {
                    $inStr = false;
                }
            } else {
                if ($ch === '"') {
                    $inStr = true;
                } elseif ($ch === $open) {
                    $depth++;
                } elseif ($ch === $close) {
                    $depth--;
                    if ($depth === 0) {
                        return substr($source, $start, $i - $start + 1);
                    }
                }
            }
        }

        return null; // unbalanced
    }

    public static function catalogArray(string $html): ?string
    {
        return self::balancedAfter($html, 'seriesData', '[');
    }

    public static function episodesArray(string $html): ?string
    {
        return self::balancedAfter($html, '"episodes"', '[');
    }
}
