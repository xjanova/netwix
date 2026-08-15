<?php

namespace Tests\Unit;

use App\Support\MediaUrl;
use PHPUnit\Framework\TestCase;

/**
 * The rule that kept ~2,000 covers off the mobile app while every web page looked fine: a browser
 * percent-encodes a URL path before it sends it, and nothing else does. See [App\Support\MediaUrl].
 */
class MediaUrlTest extends TestCase
{
    /** A Thai filename must go out encoded — the sources' nginx answers 400 to raw UTF-8 in a path. */
    public function test_a_thai_path_is_percent_encoded(): void
    {
        $out = MediaUrl::encodePath('https://rongyok.com/images/poster/โปรดรัก-2026-8490.jpg');

        $this->assertStringContainsString('%E0%B9%82%E0%B8%9B%E0%B8%A3%E0%B8%94', $out);
        $this->assertStringNotContainsString('โปรดรัก', $out);
        $this->assertStringStartsWith('https://rongyok.com/images/poster/', $out);
    }

    /**
     * Idempotence is the whole safety property: this runs on every render, over a mix of feeds that
     * hand us encoded and raw URLs, so a second pass must not turn %E0 into %25E0.
     */
    public function test_an_already_encoded_path_is_unchanged(): void
    {
        $encoded = 'https://rongyok.com/images/poster/%E0%B9%82%E0%B8%9B%E0%B8%A3%E0%B8%94-2026.jpg';

        $this->assertSame($encoded, MediaUrl::encodePath($encoded));
        $this->assertSame($encoded, MediaUrl::encodePath(MediaUrl::encodePath($encoded)));
    }

    /** A plain ASCII URL must come back byte-identical — it is also a CDN cache key. */
    public function test_an_ascii_url_is_untouched(): void
    {
        $url = 'https://www.24-hdx.com/wp-content/uploads/2022/09/Greenland-2020.jpg';

        $this->assertSame($url, MediaUrl::encodePath($url));
    }

    /** Query strings carry cache-busters and signatures; only the PATH may be rewritten. */
    public function test_the_query_string_survives(): void
    {
        $url = 'https://netwix.online/storage/media/thumbs/187.webp?t=1783194914';

        $this->assertSame($url, MediaUrl::encodePath($url));
    }

    /** Slashes separate segments and must not be encoded away. */
    public function test_path_separators_are_preserved(): void
    {
        $out = MediaUrl::encodePath('https://x.test/a/ข/c.jpg');

        $this->assertSame('https://x.test/a/%E0%B8%82/c.jpg', $out);
    }

    /** A bare host with no path (and junk) must not blow up mid-render. */
    public function test_a_url_without_a_path_is_returned_as_is(): void
    {
        $this->assertSame('https://x.test', MediaUrl::encodePath('https://x.test'));
        $this->assertSame('', MediaUrl::encodePath(''));
    }
}
