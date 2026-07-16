<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The mobile app's notification inbox (GET /api/app/notifications). Broadcasts
 * are authored by admin (Admin\AppNotificationController); the app polls this,
 * tracks its own last-read id locally, and mutes categories client-side.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $limit = min(50, max(1, (int) $request->query('limit', 30)));

        $items = AppNotification::query()->live()->limit($limit)->get();

        return response()->json(['success' => true, 'data' => [
            'items' => $items->map(fn (AppNotification $n) => $n->toAppPayload())->all(),
            // The app compares this against its stored last-seen id for the badge.
            'latest_id' => (int) ($items->first()->id ?? 0),
            'categories' => AppNotification::CATEGORIES,
        ]]);
    }
}
