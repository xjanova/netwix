<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Genre;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class GenreController extends Controller
{
    public function index(): View
    {
        return view('admin.genres.index', [
            'genres' => Genre::withCount('contents')->orderBy('sort')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'sort' => ['nullable', 'integer', 'between:0,999'],
        ], ['name.required' => 'กรุณากรอกชื่อหมวด']);

        Genre::create([
            'name' => $data['name'],
            'slug' => Str::slug($data['name']) ?: Str::random(6),
            'sort' => $data['sort'] ?? 0,
        ]);

        return back()->with('status', 'เพิ่มหมวดแล้ว');
    }

    public function update(Request $request, Genre $genre): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'sort' => ['nullable', 'integer', 'between:0,999'],
        ]);

        $genre->update(['name' => $data['name'], 'sort' => $data['sort'] ?? $genre->sort]);

        return back()->with('status', 'บันทึกหมวดแล้ว');
    }

    public function destroy(Genre $genre): RedirectResponse
    {
        $genre->delete();

        return back()->with('status', 'ลบหมวดแล้ว');
    }
}
