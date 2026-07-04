<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppDebugLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin viewer for the mobile app's diagnostics (app_debug_logs). Read-only
 * plus a "clear" — the app POSTs to /api/app/debug, this reads them back so
 * on-device sign-in / crash issues are visible without SSH.
 */
class DebugLogController extends Controller
{
    public function index(Request $request): View
    {
        $level = (string) $request->query('level', '');
        $event = trim((string) $request->query('event', ''));

        $q = AppDebugLog::query()->latest('id');
        if (in_array($level, ['info', 'warn', 'error'], true)) {
            $q->where('level', $level);
        }
        if ($event !== '') {
            $q->where('event', 'like', "%{$event}%");
        }

        $logs = $q->paginate(80)->withQueryString();
        $counts = AppDebugLog::selectRaw('level, count(*) as c')->groupBy('level')->pluck('c', 'level');
        $total = AppDebugLog::count();

        return view('admin.debug.index', compact('logs', 'counts', 'total', 'level', 'event'));
    }

    public function clear(Request $request): RedirectResponse
    {
        $level = (string) $request->input('level', '');
        $q = AppDebugLog::query();
        if (in_array($level, ['info', 'warn', 'error'], true)) {
            $q->where('level', $level);
        }
        $q->delete();

        return back()->with('status', 'ล้าง log แล้ว');
    }
}
