<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Support\PlaybackHealth;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * "หยุดเผยแพร่ (ปัญหา)" — titles auto-unpublished because too many distinct viewers couldn't play
 * them (see [App\Support\PlaybackHealth]). The admin reviews each: re-publish (link came back / was
 * a blip), or delete (dead for good — to be re-sourced from another site).
 */
class SuspendedController extends Controller
{
    public function index(): View
    {
        $items = Content::suspended()
            ->with('genres')
            ->orderByDesc('suspended_at')
            ->paginate(30);

        return view('admin.suspended.index', ['items' => $items]);
    }

    public function republish(Content $content): RedirectResponse
    {
        PlaybackHealth::republish($content);

        return back()->with('status', 'เผยแพร่ “'.$content->title.'” อีกครั้งแล้ว (มีช่วงผ่อนผัน 12 ชม. ก่อนจะถูกหยุดซ้ำ)');
    }
}
