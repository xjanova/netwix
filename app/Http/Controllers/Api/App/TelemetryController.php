<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\AppDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Device-statistics sink (POST /api/app/telemetry), reported once per app
 * launch. One row per install, keyed by the random on-device `device_key` —
 * collection is disclosed in the privacy policy and used only for the admin
 * "สถิติแอป" screen. Reached via auth.apptoken.optional so a signed-in launch
 * links the install to the account; guests stay anonymous. Validation caps +
 * the route throttle keep a hostile client from stuffing the table.
 */
class TelemetryController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_key' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{16,64}$/'],
            'platform' => ['nullable', 'string', 'max:16'],
            'os_version' => ['nullable', 'string', 'max:48'],
            'device_model' => ['nullable', 'string', 'max:96'],
            'app_version' => ['nullable', 'string', 'max:24'],
            'locale' => ['nullable', 'string', 'max:12'],
            'screen' => ['nullable', 'string', 'max:24'],
        ]);

        try {
            $device = AppDevice::firstOrNew(['device_key' => $data['device_key']]);
        $device->fill([
            'platform' => $data['platform'] ?? $device->platform,
            'os_version' => $data['os_version'] ?? $device->os_version,
            'device_model' => $data['device_model'] ?? $device->device_model,
            'app_version' => $data['app_version'] ?? $device->app_version,
            'locale' => $data['locale'] ?? $device->locale,
            'screen' => $data['screen'] ?? $device->screen,
        ]);
        $device->user_id = $request->user()?->id ?? $device->user_id;
        $device->first_seen_at ??= now();
        $device->last_seen_at = now();
            $device->launches = $device->launches + 1;
            $device->save();
        } catch (\Throwable $e) {
            // Duplicate-key race on a double first-launch ping, etc. — stats
            // are best-effort and must never surface an error to the app.
        }

        return response()->json(['success' => true, 'data' => ['ok' => true]]);
    }
}
