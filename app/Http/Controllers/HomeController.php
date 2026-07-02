<?php

namespace App\Http\Controllers;

use App\Models\Content;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('profiles.index');
        }

        // Real catalog data powers the logged-out landing (Netflix-style Top 10).
        $trending = Content::published()
            ->orderByDesc('views')
            ->with('genres')
            ->take(10)
            ->get();

        return view('frontend.landing', ['trending' => $trending]);
    }
}
