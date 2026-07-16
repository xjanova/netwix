<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin editor for the legal pages ("นโยบาย / ข้อตกลง"). Bodies are stored in
 * Settings as plain text ("## หัวข้อ" lines become headings, "- " lines become
 * bullets — see PageController::renderLegalBody). Leaving a body blank falls
 * back to the built-in Blade content. The web /terms + /privacy pages and the
 * app's in-app viewer both render from the same source.
 */
class LegalController extends Controller
{
    public function index(): View
    {
        return view('admin.legal.index', [
            'terms' => Setting::get('legal_terms_body', ''),
            'privacy' => Setting::get('legal_privacy_body', ''),
            'updated' => Setting::get('legal_updated_at', ''),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'legal_terms_body' => ['nullable', 'string', 'max:60000'],
            'legal_privacy_body' => ['nullable', 'string', 'max:60000'],
            'legal_updated_at' => ['nullable', 'string', 'max:60'],
        ]);

        Setting::write('legal_terms_body', $data['legal_terms_body'] ?? null);
        Setting::write('legal_privacy_body', $data['legal_privacy_body'] ?? null);
        Setting::write('legal_updated_at', $data['legal_updated_at'] ?? null);

        return back()->with('status', 'บันทึกนโยบาย/ข้อตกลงแล้ว — มีผลทั้งบนเว็บและในแอปทันที');
    }
}
