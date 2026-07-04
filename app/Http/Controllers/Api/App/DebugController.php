<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\AppDebugLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sink for mobile-app diagnostics (POST /api/app/debug). Public — it must accept
 * reports from guests and from FAILED sign-ins, which is the whole point. Kept
 * safe by: request validation, size caps, a tight rate limit (route middleware),
 * and opportunistic pruning. The app is responsible for never sending secrets
 * (tokens, passwords, one-time codes); this endpoint stores whatever it gets as
 * plain data, so treat the contents as untrusted when reviewing.
 */
class DebugController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'level' => ['nullable', 'in:info,warn,error'],
            'event' => ['required', 'string', 'max:80'],
            'message' => ['nullable', 'string', 'max:2000'],
            'context' => ['nullable', 'array'],
            'user_id' => ['nullable', 'integer'],
            'app_version' => ['nullable', 'string', 'max:24'],
            'platform' => ['nullable', 'string', 'max:16'],
        ]);

        // Cap the JSON context so a client can't stuff the table.
        $context = $data['context'] ?? null;
        if ($context !== null && strlen((string) json_encode($context)) > 4000) {
            $context = ['_truncated' => true];
        }

        AppDebugLog::create([
            'level' => $data['level'] ?? 'info',
            'event' => $data['event'],
            'message' => $data['message'] ?? null,
            'context' => $context,
            'user_id' => $data['user_id'] ?? null,
            'app_version' => $data['app_version'] ?? null,
            'platform' => $data['platform'] ?? null,
            'ip' => $request->ip(),
            'created_at' => now(),
        ]);

        // Opportunistic retention (~1% of writes): drop anything older than 14d.
        if (random_int(1, 100) === 1) {
            AppDebugLog::where('created_at', '<', now()->subDays(14))->delete();
        }

        return response()->json(['success' => true, 'data' => ['ok' => true]]);
    }
}
