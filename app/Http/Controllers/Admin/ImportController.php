<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Genre;
use App\Models\SourceTitle;
use App\Services\Import\ImportService;
use App\Services\Import\SourceRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ImportController extends Controller
{
    public function __construct(
        private SourceRegistry $registry,
        private ImportService $importer,
    ) {}

    public function index(Request $request): View
    {
        $sourceId = $request->query('source', array_key_first($this->registry->all()));
        $q = trim((string) $request->query('q', ''));
        $filter = $request->query('filter', 'all'); // all | new | imported

        $titles = SourceTitle::where('source', $sourceId)
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('clean_title', 'like', "%{$q}%")->orWhere('title', 'like', "%{$q}%")))
            ->when($filter === 'new', fn ($w) => $w->notImported())
            ->when($filter === 'imported', fn ($w) => $w->imported())
            ->orderByDesc('view_count')
            ->orderByDesc('id')
            ->paginate(24)
            ->withQueryString();

        $sources = collect($this->registry->all())->map(fn ($s, $id) => [
            'id' => $id,
            'name' => $s->displayName(),
            'synced' => SourceTitle::where('source', $id)->count(),
            'imported' => SourceTitle::where('source', $id)->imported()->count(),
        ])->values();

        return view('admin.import.index', [
            'sources' => $sources,
            'sourceId' => $sourceId,
            'currentSource' => $this->registry->get($sourceId),
            'titles' => $titles,
            'q' => $q,
            'filter' => $filter,
            'genres' => Genre::orderBy('sort')->get(),
            'agent' => \App\Support\IngestAgent::status(),
        ]);
    }

    public function sync(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'source' => ['required', 'string'],
            'max_pages' => ['nullable', 'integer', 'between:1,60'],
        ]);
        abort_unless($this->registry->has($data['source']), 404);

        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        try {
            $count = $this->importer->sync($data['source'], $data['max_pages'] ?? 30);
        } catch (\Throwable $e) {
            return back()->withErrors(['sync' => 'ซิงค์ไม่สำเร็จ: '.$e->getMessage()]);
        }

        return redirect()
            ->route('admin.import.index', ['source' => $data['source']])
            ->with('status', "ซิงค์แคตตาล็อกจาก {$this->registry->get($data['source'])->displayName()} แล้ว ({$count} เรื่อง)");
    }

    public function import(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'source' => ['required', 'string'],
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
            'type' => ['nullable', 'in:series,movie,vertical'],
            'genres' => ['array'],
            'genres.*' => ['integer', 'exists:genres,id'],
            'primary_genre' => ['nullable', 'integer'],
            'publish' => ['sometimes', 'boolean'],
        ]);
        abort_unless($this->registry->has($data['source']), 404);

        // NetWix resolves signed CDN URLs itself at watch time, so importing no longer depends on
        // the home downloader being connected.
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $opts = [
            'type' => $data['type'] ?? $this->registry->get($data['source'])->defaultContentType(),
            'genres' => $data['genres'] ?? [],
            'primary_genre' => $data['primary_genre'] ?? null,
            'publish' => $request->boolean('publish'),
        ];

        $titles = SourceTitle::where('source', $data['source'])->whereIn('id', $data['ids'])->get();

        $ok = 0;
        $failed = [];
        foreach ($titles as $st) {
            try {
                $this->importer->import($st, $opts);
                $ok++;
            } catch (\Throwable $e) {
                $failed[] = $st->displayTitle();
            }
        }

        $msg = "นำเข้าสำเร็จ {$ok} เรื่อง";
        if ($failed) {
            $msg .= ' · ไม่สำเร็จ '.count($failed).' เรื่อง ('.implode(', ', array_slice($failed, 0, 3)).')';
        }

        return redirect()
            ->route('admin.import.index', ['source' => $data['source']])
            ->with('status', $msg);
    }
}
