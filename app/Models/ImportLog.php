<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

/**
 * One row per import operation — the admin "ประวัติการนำเข้าหนัง" log. Written by the import entry
 * points via record(); reading is in Admin\ImportLogController.
 */
class ImportLog extends Model
{
    protected $fillable = ['source', 'action', 'user_id', 'imported', 'skipped', 'failed', 'note'];

    protected function casts(): array
    {
        return [
            'imported' => 'integer',
            'skipped' => 'integer',
            'failed' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record an import operation. Best-effort: never let logging break an import (wrapped in try/catch,
     * and skips a no-op run where nothing was imported/skipped/failed). $userId defaults to the logged-in
     * admin (null under the scheduler/CLI).
     */
    public static function record(string $source, string $action, int $imported, int $skipped = 0, int $failed = 0, ?string $note = null, ?int $userId = null): void
    {
        if ($imported === 0 && $skipped === 0 && $failed === 0) {
            return;
        }

        try {
            $userId = $userId ?? auth()->id();

            // Coalesce a chunked interactive run (the admin "import all" loop calls this once per chunk,
            // always with note=null) into ONE growing row per source/session, so the history stays clean.
            if ($note === null) {
                $recent = static::where('source', $source)->where('action', $action)
                    ->where('user_id', $userId)->whereNull('note')
                    ->where('created_at', '>=', now()->subMinutes(20))
                    ->latest('id')->first();
                if ($recent) {
                    $recent->increment('imported', $imported);
                    $recent->increment('skipped', $skipped);
                    $recent->increment('failed', $failed);
                    $recent->touch();

                    return;
                }
            }

            static::create([
                'source' => mb_substr($source, 0, 40),
                'action' => $action,
                'user_id' => $userId,
                'imported' => $imported,
                'skipped' => $skipped,
                'failed' => $failed,
                'note' => $note ? mb_substr($note, 0, 255) : null,
            ]);
        } catch (Throwable $e) {
            // logging must never break the actual import
        }
    }
}
