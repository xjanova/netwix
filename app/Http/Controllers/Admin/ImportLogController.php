<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ImportLog;
use App\Services\Import\SourceRegistry;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Read-only import history ("ประวัติการนำเข้าหนัง") — see App\Models\ImportLog::record for writers. */
class ImportLogController extends Controller
{
    public function index(Request $request, SourceRegistry $registry): View
    {
        $source = (string) $request->query('source', '');
        $action = (string) $request->query('action', '');

        $logs = ImportLog::query()
            ->with('user:id,name')
            ->when($source !== '', fn ($w) => $w->where('source', $source))
            ->when(in_array($action, ['manual', 'scheduled', 'backfill'], true), fn ($w) => $w->where('action', $action))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        // Totals for the header cards (all-time, unfiltered).
        $totals = [
            'imported' => (int) ImportLog::sum('imported'),
            'runs' => (int) ImportLog::count(),
            'today' => (int) ImportLog::whereDate('created_at', now()->toDateString())->sum('imported'),
        ];

        $sources = collect($registry->all())->map(fn ($s, $id) => ['id' => $id, 'name' => $s->displayName()])->values();

        return view('admin.import-logs.index', compact('logs', 'sources', 'source', 'action', 'totals'));
    }
}
