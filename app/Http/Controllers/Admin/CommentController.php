<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CommentController extends Controller
{
    public function index(): View
    {
        return view('admin.comments.index', [
            'comments' => Comment::with(['content:id,title,slug', 'profile:id,name,avatar_color'])
                ->latest()->paginate(30),
            'total' => Comment::count(),
        ]);
    }

    public function destroy(Comment $comment): RedirectResponse
    {
        $comment->delete();

        return back()->with('status', 'ลบความคิดเห็นแล้ว');
    }
}
