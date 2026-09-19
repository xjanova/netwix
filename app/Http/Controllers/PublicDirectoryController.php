<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Support\TitleIndex;
use Illuminate\View\View;

/**
 * The A–Z directory: one stable, complete, shallow route to every published title.
 *
 * The hubs sort randomly on a daily seed, so /series?page=47 is a different sixty titles tomorrow and
 * no title ever keeps a referring page. Google said so itself about /title/rongyok-7496 — "referring
 * sitemap: none, referring page: none" — and titles sat at "crawled, currently not indexed". This is
 * the fix for the half that links could fix: ordered by title, the same tomorrow as today, and every
 * link's anchor text is the thing someone would type into Google — the film's name.
 *
 * Three hops from the home page to any of the 19,934: footer → /all → group → title.
 *
 * Intentionally NOT auth-aware, unlike [PublicGenreController]: this is a reference index, the same
 * for a member, a guest and a crawler.
 */
class PublicDirectoryController extends Controller
{
    private const PER_PAGE = 200;

    public function index(): View
    {
        $counts = TitleIndex::counts();

        return view('frontend.public.directory', [
            'groups' => $counts,
            'total' => array_sum($counts),
        ]);
    }

    public function group(string $group): View
    {
        abort_unless(TitleIndex::isValid($group), 404);

        $counts = TitleIndex::counts();
        abort_if(($counts[$group] ?? 0) === 0, 404);

        $items = TitleIndex::scope(Content::publicListing(), $group)
            ->orderBy('title')
            ->orderBy('id')          // titles repeat across sources; keep the order total, or pages drift
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('frontend.public.directory-group', [
            'group' => $group,
            'label' => TitleIndex::label($group),
            'groups' => $counts,
            'items' => $items,
        ]);
    }
}
