<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\SourceTitle;
use App\Services\Import\SourceRegistry;
use App\Support\PlaybackHealth;
use App\Support\ReplacementFinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "หยุดเผยแพร่ (ปัญหา)" — titles auto-unpublished because too many distinct viewers couldn't play
 * them (see [App\Support\PlaybackHealth]). The admin reviews each: try it in the admin player, look for
 * the same title on another site and wire it in ([ReplacementFinder]), re-publish (link came back /
 * was a blip), or delete.
 */
class SuspendedController extends Controller
{
    public function index(SourceRegistry $registry): View
    {
        $items = Content::suspended()
            ->with(['genres', 'mirrors'])
            ->with(['episodes' => fn ($q) => $q->select('id', 'content_id', 'number')->orderBy('number')])
            ->orderByDesc('suspended_at')
            ->paginate(30);

        return view('admin.suspended.index', [
            'items' => $items,
            'siteNames' => collect($registry->all())->map(fn ($s) => $s->displayName())->all(),
        ]);
    }

    public function republish(Content $content): RedirectResponse
    {
        PlaybackHealth::republish($content);

        return back()->with('status', 'เผยแพร่ “'.$content->title.'” อีกครั้งแล้ว (มีช่วงผ่อนผัน 12 ชม. ก่อนจะถูกหยุดซ้ำ)');
    }

    /** The same title on other sites (and fresh uploads on its own), from every synced catalogue. */
    public function candidates(Content $content, ReplacementFinder $finder): JsonResponse
    {
        return response()->json(['results' => $finder->candidates($content)]);
    }

    /**
     * Use one candidate as the title's link. Verifies the first episode plays first; a failed check
     * asks the admin to confirm (a site can be slow right now and still be the right copy).
     */
    public function attach(Content $content, Request $request, ReplacementFinder $finder, SourceRegistry $registry): JsonResponse
    {
        $data = $request->validate([
            'source_title' => ['required', 'integer'],
            'force' => ['sometimes', 'boolean'],
            'merge' => ['sometimes', 'boolean'],
        ]);
        $st = SourceTitle::findOrFail($data['source_title']);

        // That copy is already live here under its own entry: send this one's viewers there instead
        // of listing the title twice.
        if ($request->boolean('merge')) {
            $live = Content::withoutGlobalScopes()
                ->where('source', $st->source)->where('source_key', $st->source_key)
                ->whereKeyNot($content->id)->where('is_published', true)->first();
            if (! $live) {
                return response()->json(['ok' => false, 'message' => 'ไม่พบเรื่องนี้ที่ออนไลน์อยู่ในเว็บแล้ว'], 422);
            }
            $finder->retire($content, $live);

            return response()->json([
                'ok' => true,
                'message' => 'ปิด “'.$content->title.'” แล้ว — คนที่เปิดลิงก์เดิมจะถูกพาไปที่ “'.$live->title.'” (#'.$live->id.')',
            ]);
        }

        if (! $request->boolean('force')) {
            $check = $finder->verify($content, $st->source, $st->source_key);
            if (! $check['ok']) {
                return response()->json([
                    'ok' => false,
                    'need_confirm' => true,
                    'message' => $check['message'].' — จะใช้ลิงก์นี้เลยไหม?',
                ]);
            }
        }

        $finder->attach($content, $st->source, $st->source_key);

        return response()->json([
            'ok' => true,
            'message' => 'ใช้ลิงก์จาก '.($registry->get($st->source)?->displayName() ?? $st->source).' กับ “'.$content->title.'” แล้ว และเผยแพร่อีกครั้ง',
        ]);
    }
}
