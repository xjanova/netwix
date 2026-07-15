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
            // The mobile app's OTA self-update pulls the APK from this SAME route, so an update must
            // NOT inflate the website-download count. Skip when the in-app updater marks the request
            // (?src=update) or when it arrives via Android's DownloadManager — which is what the
            // ota_update package uses, while modern browsers download with their own UA, so it's a safe
            // tell for already-installed builds shipped before the ?src=update marker existed.
            $ua = strtolower((string) $request->userAgent());
            if ($request->query('src') === 'update' || str_contains($ua, 'androiddownloadmanager')) {
                return;
            }

            $key = 'apkdl:'.sha1((string) $request->ip()).':'.$version;
            if (! Cache::add($key, 1, now()->addMinutes(self::DEDUP_MINUTES))) {
                return;
            }

            static::create([
                'version' => $version,
                'is_member' => $request->user() !== null,
                'platform' => str_contains($ua, 'android') ? 'android' : 'other',
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Analytics is never worth failing a download over.
        }
    }
}
