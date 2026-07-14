<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * One APK download from our own domain. Insert-only (no updated_at), and PDPA-safe —
 * we keep the version, a coarse platform bucket and whether the visitor was signed in,
 * never an IP or a user id (the IP is only hashed into a short-lived dedup key).
 */
class AppDownload extends Model
{
    public const UPDATED_AT = null;

    /** A repeat download from the same visitor+version inside this window isn't re-counted. */
    private const DEDUP_MINUTES = 60;

    protected $fillable = ['version', 'is_member', 'platform', 'created_at'];

    protected $casts = [
        'is_member' => 'boolean',
        'created_at' => 'datetime',
    ];

    /**
     * Count one download — best-effort, so a logging failure can never cost the customer
     * their APK. Deduped per visitor+version for an hour, which also collapses the extra
     * range requests a download manager fires for a single download (mirrors the 6h view
     * dedup in WatchController).
     */
    public static function record(Request $request, string $version): void
    {
        try {
            $key = 'apkdl:'.sha1((string) $request->ip()).':'.$version;
            if (! Cache::add($key, 1, now()->addMinutes(self::DEDUP_MINUTES))) {
                return;
            }

            static::create([
                'version' => $version,
                'is_member' => $request->user() !== null,
                'platform' => str_contains(strtolower((string) $request->userAgent()), 'android') ? 'android' : 'other',
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Analytics is never worth failing a download over.
        }
    }
}
