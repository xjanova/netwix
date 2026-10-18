<?php

namespace Tests\Feature;

use App\Services\AppRelease;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * /download/apk hands the APK over byte-for-byte with its size up front: the app's updater (ota_update)
 * can only show progress from Content-Length, and nothing between us and the phone may re-encode it.
 * The Apache half of that guarantee lives in public/.htaccess and is checked by the curl in the commit.
 */
class AppDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_apk_is_served_untransformed_with_its_content_length(): void
    {
        Storage::fake('local');
        $apk = str_repeat('PK', 4096);   // stand-in bytes — an APK is a zip
        Storage::disk('local')->put('app/netwix-v1.7.0.apk', $apk);

        $this->app->instance(AppRelease::class, new class extends AppRelease
        {
            public function latest(): ?array
            {
                return [
                    'version' => 'v1.7.0', 'name' => 'v1.7.0', 'notes' => '',
                    'apk_url' => 'https://example.com/app.apk', 'apk_name' => 'app.apk',
                    'size' => 8192, 'published_at' => null,
                ];
            }
        });

        $res = $this->get('/download/apk?src=update')->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.android.package-archive')
            ->assertHeader('Content-Length', (string) strlen($apk))
            ->assertHeaderMissing('Content-Encoding');

        $this->assertStringContainsString('no-transform', (string) $res->headers->get('Cache-Control'));
    }
}
