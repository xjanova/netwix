<?php

namespace App\Console\Commands;

use App\Models\Episode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Purges the AUTO ep1 preview mirrors (the ones PreviewDownloader / DownloadPreviewJob stamped with
 * mirror_trigger='preview'): deletes each stored file and reverts the episode to on-demand resolving
 * (video_url → null), so it streams like every other episode again. Deliberate admin/customer mirrors
 * (mirror_trigger 'admin'/'customer', made from /admin/storage) are left untouched. Idempotent — safe
 * to re-run; a later `php artisan netwix:previews` can rebuild them if ever wanted.
 *
 *   php artisan netwix:previews-clear             # delete every auto ep1 mirror
 *   php artisan netwix:previews-clear --dry-run   # report only, delete nothing
 *   php artisan netwix:previews-clear --source=rongyok
 */
class ClearPreviews extends Command
{
    protected $signature = 'netwix:previews-clear {--dry-run : report only, delete nothing} {--source= : limit to one source}';

    protected $description = 'Delete auto ep1 preview mirrors and revert those episodes to on-demand streaming';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $disk = Storage::disk('public');

        $eps = Episode::query()
            ->with('content:id,source,source_key')
            ->whereNotNull('mirrored_at')
            ->where('mirror_trigger', 'preview')   // ONLY auto previews — never admin/customer mirrors
            ->when($this->option('source'), fn ($q, $s) => $q->whereHas('content', fn ($w) => $w->where('source', $s)))
            ->get();

        if ($eps->isEmpty()) {
            $this->info('No auto ep1 preview mirrors to clear.');

            return self::SUCCESS;
        }

        $freed = 0;
        $files = 0;
        foreach ($eps as $ep) {
            $freed += (int) $ep->file_size;

            $path = $this->storagePath($ep);
            if ($path && $disk->exists($path)) {
                $files++;
                if (! $dry) {
                    $disk->delete($path);
                }
            }

            if (! $dry) {
                $ep->update([
                    'video_url' => null,
                    'mirrored_at' => null,
                    'file_size' => null,
                    'mirror_trigger' => null,
                    'mirror_attempts' => 0,
                    'mirror_failed_at' => null,
                ]);
            }
        }

        $verb = $dry ? 'WOULD clear' : 'cleared';
        $this->info("{$verb} {$eps->count()} episode(s), {$files} file(s) removed, ".round($freed / 1e6, 1).' MB freed.');

        return self::SUCCESS;
    }

    /** media/{source}/{key}/{n}.mp4 — matches how PreviewDownloader stored it; URL fallback otherwise. */
    private function storagePath(Episode $ep): ?string
    {
        $c = $ep->content;
        if ($c?->source && $c->source_key && $ep->number) {
            return "media/{$c->source}/{$c->source_key}/{$ep->number}.mp4";
        }

        if ($ep->video_url && ($i = strpos($ep->video_url, '/storage/')) !== false) {
            return ltrim(substr($ep->video_url, $i + strlen('/storage/')), '/');
        }

        return null;
    }
}
