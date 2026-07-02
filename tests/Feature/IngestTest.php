<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Genre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ingest.token' => 'test-token-123', 'services.ingest.max_gb' => 55]);
        Storage::fake('public');
    }

    private function importedEpisode(): Content
    {
        $c = Content::create([
            'title' => 'เรื่องนำเข้า', 'slug' => 'imported-1', 'source' => 'rongyok', 'source_key' => '8207',
            'type' => 'vertical', 'is_published' => true, 'maturity' => '15+',
        ]);
        $c->episodes()->create(['number' => 1, 'title' => 'ตอนที่ 1', 'source' => 'rongyok', 'source_ref' => '1']);

        return $c;
    }

    public function test_ingest_rejects_missing_or_wrong_token(): void
    {
        $this->importedEpisode();
        $file = UploadedFile::fake()->create('1.mp4', 100, 'video/mp4');

        $this->post('/api/ingest/episode', ['source' => 'rongyok', 'source_key' => '8207', 'number' => 1, 'file' => $file])
            ->assertStatus(401);

        $this->withHeaders(['X-Ingest-Token' => 'wrong'])
            ->post('/api/ingest/episode', ['source' => 'rongyok', 'source_key' => '8207', 'number' => 1, 'file' => $file])
            ->assertStatus(401);
    }

    public function test_ingest_stores_file_and_marks_mirrored(): void
    {
        $content = $this->importedEpisode();
        $file = UploadedFile::fake()->create('1.mp4', 200, 'video/mp4');

        $this->withHeaders(['X-Ingest-Token' => 'test-token-123'])
            ->post('/api/ingest/episode', ['source' => 'rongyok', 'source_key' => '8207', 'number' => 1, 'file' => $file])
            ->assertOk()
            ->assertJson(['ok' => true]);

        Storage::disk('public')->assertExists('media/rongyok/8207/1.mp4');
        $ep = $content->episodes()->first();
        $this->assertNotNull($ep->mirrored_at);
        $this->assertTrue($ep->is_mirrored);
        $this->assertStringContainsString('/storage/media/rongyok/8207/1.mp4', $ep->video_url);
    }

    public function test_failed_reports_quarantine_and_drop_out_of_pending(): void
    {
        $content = $this->importedEpisode();
        $ep = $content->episodes()->first();
        $h = ['X-Ingest-Token' => 'test-token-123'];

        // First failure backs it off (recent) — pending should skip it immediately.
        $this->withHeaders($h)->postJson("/api/ingest/episode/{$ep->id}/failed")
            ->assertOk()->assertJson(['attempts' => 1, 'given_up' => false]);
        $this->assertSame(0, $this->withHeaders($h)->getJson('/api/ingest/pending?source=rongyok')->json('count'));

        // After MIRROR_MAX_ATTEMPTS it is given up for good.
        for ($i = 2; $i <= 5; $i++) {
            $this->withHeaders($h)->postJson("/api/ingest/episode/{$ep->id}/failed");
        }
        $this->assertSame(5, $ep->fresh()->mirror_attempts);
        $this->assertTrue($ep->fresh()->is_unavailable);
    }

    public function test_pending_lists_unmirrored_imported_episodes(): void
    {
        $this->importedEpisode();

        $res = $this->withHeaders(['X-Ingest-Token' => 'test-token-123'])
            ->getJson('/api/ingest/pending?source=rongyok')
            ->assertOk()
            ->json();

        $this->assertSame(1, $res['count']);
        $this->assertSame('8207', $res['items'][0]['source_key']);
    }
}
