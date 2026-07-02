<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\Episode;
use App\Support\MediaUsage;
use Illuminate\View\View;

class StorageController extends Controller
{
    public function index(): View
    {
        $summary = MediaUsage::summary();

        // Per-title breakdown for titles that have any mirrored episode.
        $titles = Content::query()
            ->whereHas('episodes', fn ($q) => $q->whereNotNull('mirrored_at'))
            ->withCount([
                'episodes as mirrored_count' => fn ($q) => $q->whereNotNull('mirrored_at'),
                'episodes as total_episodes',
            ])
            ->withSum(['episodes as media_bytes' => fn ($q) => $q->whereNotNull('mirrored_at')], 'file_size')
            ->orderByDesc('media_bytes')
            ->paginate(20);

        // Planning projection: if every imported (mirrorable) episode were mirrored at the current
        // average size, how big would it get?
        $mirrorableTotal = Episode::whereNotNull('source')
            ->whereHas('content', fn ($q) => $q->where('source', 'rongyok'))
            ->count();
        $projectedBytes = $summary['avg'] * $mirrorableTotal;

        return view('admin.storage.index', [
            'summary' => $summary,
            'titles' => $titles,
            'mirrorableTotal' => $mirrorableTotal,
            'projectedBytes' => $projectedBytes,
            'pendingCount' => Episode::whereNull('mirrored_at')->whereNotNull('source')
                ->whereHas('content', fn ($q) => $q->where('source', 'rongyok'))->count(),
        ]);
    }
}
