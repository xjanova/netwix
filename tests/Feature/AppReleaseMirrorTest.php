<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\AppRelease;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A new app release must already be on our disk when the app is told about it.
 *
 * The first /download/apk of a release used to fetch the APK from GitHub inside that customer's
 * request. The app's downloader gives up long before 60 MB arrives, so on 2026-10-04 the owner's
 * own phone — first to tap "อัปเดตเลย" for v1.6.3 — got "ดาวน์โหลดไฟล์ติดตั้งไม่สำเร็จ".
 */
class AppReleaseMirrorTest extends TestCase
{
    use RefreshDatabase;

    private const SIZE = 200_000;

    private const APK = 'app/netwix-v1.6.3.apk';

    private function release(int $served = self::SIZE): void
    {
        Storage::fake('local');
        Setting::write('app_github_repo', 'owner/repo');
        Http::fake([
            'api.github.com/repos/owner/repo/releases/latest' => Http::response([
                'tag_name' => 'v1.6.3',
                'assets' => [[
                    'name' => 'netwix-1.6.3.apk',
                    'browser_download_url' => 'https://github.com/owner/repo/releases/download/v1.6.3/netwix-1.6.3.apk',
                    'size' => self::SIZE,
                ]],
            ]),
            'github.com/owner/repo/releases/download/*' => Http::response(str_repeat('x', $served)),
        ]);
    }

    private function apkFetches(): int
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/releases/download/'))->count();
    }

    public function test_a_release_is_offered_only_once_its_apk_is_on_our_disk(): void
    {
        $this->release();

        // Not mirrored yet: "up to date", and the copy starts once the response is out.
        $this->getJson('/api/app/version')->assertOk()->assertJsonPath('data', null);
        Storage::disk('local')->assertExists(self::APK);

        $this->getJson('/api/app/version')
            ->assertOk()
            ->assertJsonPath('data.version', '1.6.3')
            ->assertJsonPath('data.size', self::SIZE);

        // Served from disk from then on: GitHub was asked for the APK exactly once.
        $this->get('/download/apk?src=update')->assertOk();
        $this->assertSame(1, $this->apkFetches());
    }

    public function test_a_cut_off_download_is_never_kept_or_served(): void
    {
        $this->release(served: self::SIZE - 50_000);

        $this->getJson('/api/app/version')->assertJsonPath('data', null);
        Storage::disk('local')->assertMissing(self::APK);

        $this->get('/download/apk')->assertStatus(502);
        Storage::disk('local')->assertMissing(self::APK);
        $this->getJson('/api/app/version')->assertJsonPath('data', null);

        // Every update check could re-trigger the copy: after a failure it rests instead of
        // pulling another 60 MB per request.
        $this->assertSame(1, $this->apkFetches());

        $this->travel(6)->minutes();
        $this->getJson('/api/app/version');
        $this->assertSame(2, $this->apkFetches());
    }

    public function test_the_website_button_still_works_when_it_is_first(): void
    {
        $this->release();

        $this->get('/download/apk')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.android.package-archive');
        Storage::disk('local')->assertExists(self::APK);
    }

    public function test_only_one_copy_downloads_at_a_time(): void
    {
        $this->release();
        $release = app(AppRelease::class);
        $rel = $release->latest();

        $lock = Cache::lock('app:apk-mirror:'.self::APK, 600);
        $this->assertTrue($lock->get());

        $this->assertFalse($release->mirror($rel));
        $this->assertSame(0, $this->apkFetches());

        $lock->release();
        $this->assertTrue($release->mirror($rel));
        $this->assertSame(1, $this->apkFetches());
    }
}
