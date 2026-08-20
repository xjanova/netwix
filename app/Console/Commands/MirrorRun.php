<?php

namespace App\Console\Commands;

use App\Models\Episode;
use App\Models\MirrorJob;
use App\Models\Setting;
use App\Services\MediaMirror;
use App\Support\MirrorPlan;
use Illuminate\Console\Command;

/**
 * The only thing that actually downloads. Everything on /admin/storage is a view of the rows this
 * command reads and writes.
 *
 * Deliberately NOT scheduled, and refuses to run unless the master switch is on. The monitor was
 * asked for before the downloading, so the controls have to be real (a button that writes a status no
 * process ever reads is a dead panel) without anything starting on its own. Turning it on is two
 * explicit steps: flip the switch on the page, then have this command running.
 *
 *   php artisan netwix:mirror-run                # every source the admin has started
 *   php artisan netwix:mirror-run rongyok        # just one
 *   php artisan netwix:mirror-run --max-time=280 # fits inside a scheduler minute
 *
 * Stop/pause take effect between episodes, not mid-file: the job row is re-read before each download,
 * so the worst case for "หยุด" is one file in flight, and nothing is left half-written.
 */
class MirrorRun extends Command
{
    protected $signature = 'netwix:mirror-run
        {source? : เจาะจงแหล่งเดียว (เว้นว่าง = ทุกแหล่งที่สั่งเริ่มไว้)}
        {--content= : เจาะจงเรื่องเดียว (id ของ content) — ใช้ตอนทดลอง}
        {--max-time=280 : วินาทีสูงสุดต่อรอบ}
        {--sleep=1500 : พักกี่มิลลิวินาทีระหว่างตอน (กันยิงถี่ใส่ต้นทาง)}';

    protected $description = 'ดาวน์โหลดตอนที่แอดมินสั่งเก็บไว้ในหน้ามอนิเตอร์ (ต้องเปิดสวิตช์ก่อน)';

    public function handle(MediaMirror $mirror): int
    {
        if (! Setting::flag('mirror_enabled', false)) {
            $this->warn('สวิตช์ระบบดูดยังปิดอยู่ — เปิดที่ /admin/storage ก่อน แล้วค่อยรันใหม่');

            return self::SUCCESS;
        }

        $deadline = microtime(true) + (int) $this->option('max-time');
        $sleepUs = max(0, (int) $this->option('sleep')) * 1000;

        $jobs = MirrorJob::query()
            ->whereIn('status', [MirrorJob::STATUS_QUEUED, MirrorJob::STATUS_RUNNING])
            ->when($this->argument('source'), fn ($q, $s) => $q->where('source', $s))
            ->get();

        if ($jobs->isEmpty()) {
            $this->info('ไม่มีแหล่งไหนถูกสั่งให้ดาวน์โหลดอยู่');

            return self::SUCCESS;
        }

        foreach ($jobs as $job) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $this->runOne($job, $mirror, $deadline, $sleepUs, $this->option('content') ? (int) $this->option('content') : null);
        }

        return self::SUCCESS;
    }

    private function runOne(MirrorJob $job, MediaMirror $mirror, float $deadline, int $sleepUs, ?int $contentId = null): void
    {
        $job->update([
            'status' => MirrorJob::STATUS_RUNNING,
            'worker_seen_at' => now(),
            'started_at' => $job->started_at ?? now(),
        ]);
        $this->line("▶ {$job->source}");

        while (microtime(true) < $deadline) {
            // Re-read before every episode: this is what makes หยุด/พัก on the page take effect.
            $job->refresh();
            if (! $job->isWanted()) {
                $this->line("  ⏸ {$job->source} — แอดมินสั่ง {$job->status}");

                return;
            }
            if ($job->episode_limit && $job->done_count >= $job->episode_limit) {
                $job->update(['status' => MirrorJob::STATUS_DONE, 'finished_at' => now()]);
                $this->line("  ✔ {$job->source} — ครบตามจำนวนที่ตั้งไว้ ({$job->episode_limit})");

                return;
            }
            if (! $this->hasDisk()) {
                $job->update([
                    'status' => MirrorJob::STATUS_ERROR,
                    'last_error' => 'ดิสก์เหลือน้อยกว่าที่กันไว้ — หยุดอัตโนมัติ',
                    'finished_at' => now(),
                ]);
                $this->error("  ✖ {$job->source} — ดิสก์ใกล้เต็ม");

                return;
            }

            $episode = $this->nextEpisode($job, $contentId);
            if (! $episode) {
                $job->update(['status' => MirrorJob::STATUS_DONE, 'finished_at' => now()]);
                $this->line("  ✔ {$job->source} — ไม่มีตอนค้างแล้ว");

                return;
            }

            $result = $mirror->store($episode);
            $title = mb_substr((string) ($episode->content?->title ?? ''), 0, 120);

            if ($result['ok']) {
                $job->increment('done_count');
                $job->increment('bytes_done', (int) ($result['bytes'] ?? 0));
                $job->update([
                    'worker_seen_at' => now(),
                    'last_episode_id' => $episode->id,
                    'last_title' => $title.' EP'.$episode->number,
                    'last_error' => null,
                ]);
                $this->line('  ✓ '.$title.' EP'.$episode->number.' — '.number_format(($result['bytes'] ?? 0) / 1e6, 1).' MB');
            } else {
                // The attempt itself is counted inside MediaMirror::store(), so every caller is bounded
                // by the same rule; the job only tracks its own run.
                $job->increment('fail_count');
                $job->update([
                    'worker_seen_at' => now(),
                    'last_episode_id' => $episode->id,
                    'last_title' => $title.' EP'.$episode->number,
                    'last_error' => $result['error'] ?? 'ไม่สำเร็จ',
                ]);
                $this->warn('  ✗ '.$title.' EP'.$episode->number.' — '.($result['error'] ?? ''));
            }

            if ($sleepUs > 0) {
                usleep($sleepUs);
            }
        }

        $job->update(['worker_seen_at' => now()]);
    }

    /**
     * The next episode to fetch for this source, skipping ones that have failed too often.
     *
     * `--content` narrows a run to a single title. That is what makes a first trial possible: one
     * title's worth of bytes, one title's worth of blast radius, against a source whose catalogue is
     * 184,800 episodes.
     */
    private function nextEpisode(MirrorJob $job, ?int $contentId = null): ?Episode
    {
        return Episode::query()
            ->when($contentId, fn ($q) => $q->where('content_id', $contentId))
            ->whereHas('content', fn ($q) => $q->where('source', $job->source)->whereNotNull('source_key'))
            ->whereNotNull('source_ref')
            ->where('source_ref', '<>', '')
            ->when($job->scope !== 'all', fn ($q) => $q->whereNull('mirrored_at'))
            ->where('mirror_attempts', '<', Episode::MIRROR_MAX_ATTEMPTS)
            ->with('content')
            ->orderBy('content_id')
            ->orderBy('number')
            ->first();
    }

    private function hasDisk(): bool
    {
        $free = @disk_free_space(storage_path());

        return $free === false || $free > MirrorPlan::DISK_RESERVE_BYTES;
    }
}
