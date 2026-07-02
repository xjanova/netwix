<?php

namespace Tests\Feature;

use App\Models\Genre;
use App\Models\SourceTitle;
use App\Models\User;
use App\Services\Import\ImportService;
use App\Services\Import\JsonExtract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_balanced_extractor_handles_brackets_inside_thai_strings(): void
    {
        $html = 'foo seriesData = [{"title":"รัก [พิเศษ] {ตอนจบ}","id":1}]; bar';
        $json = JsonExtract::catalogArray($html);
        $this->assertSame('[{"title":"รัก [พิเศษ] {ตอนจบ}","id":1}]', $json);
        $this->assertIsArray(json_decode($json, true));
    }

    public function test_rongyok_sync_and_import(): void
    {
        Http::fake([
            'rongyok.com/category*' => Http::response(
                '<script>var seriesData = ['
                .'{"id":8203,"title":"บ่วงรักth","description":"เรื่องย่อ",'
                .'"poster_url":"images/poster/บ่วงรัก-พากย์ไทย-2025-8203.webp",'
                .'"jpg_url":"https://rongyok.com/images/poster/บ่วงรัก-พากย์ไทย-2025-8203.jpg","view_count":1234}'
                .'];</script>'
            ),
            'rongyok.com/watch/*' => Http::response(
                '<script>var data = {"episodes_count":2,"episodes":[{"id":11,"episode_number":1},{"id":12,"episode_number":2}]};</script>'
            ),
        ]);

        $genre = Genre::create(['name' => 'โรแมนติก', 'slug' => 'romance']);
        $svc = app(ImportService::class);

        $synced = $svc->sync('rongyok', 1);
        $this->assertSame(1, $synced);

        $st = SourceTitle::where('source', 'rongyok')->firstOrFail();
        $this->assertSame('บ่วงรัก', $st->clean_title);   // trailing "th" not present; poster gives clean title
        $this->assertSame('thai_dub', $st->dub_type);
        $this->assertSame(2025, $st->year);
        $this->assertSame(1234, $st->view_count);

        $content = $svc->import($st, ['type' => 'vertical', 'genres' => [$genre->id], 'publish' => true]);

        $this->assertDatabaseHas('contents', [
            'source' => 'rongyok', 'source_key' => '8203', 'type' => 'vertical', 'is_published' => true,
        ]);
        $this->assertSame(2, $content->episodes()->count());
        $this->assertSame('rongyok', $content->episodes()->first()->source);
        $this->assertSame('1', $content->episodes()->orderBy('number')->first()->source_ref);
        $this->assertTrue($content->genres->contains($genre->id));
        $this->assertSame($content->id, $st->fresh()->content_id);

        // Re-import is idempotent — no duplicate content or episodes.
        $svc->import($st->fresh(), ['type' => 'vertical']);
        $this->assertSame(1, \App\Models\Content::where('source_key', '8203')->count());
        $this->assertSame(2, $content->episodes()->count());
    }

    public function test_import_panel_requires_admin(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->actingAs($user)->get(route('admin.import.index'))->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('admin.import.index'))->assertStatus(200);
    }
}
