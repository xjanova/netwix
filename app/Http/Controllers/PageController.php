<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class PageController extends Controller
{
    /** App download / install landing page. */
    public function download(): View
    {
        return view('frontend.download');
    }

    /** Help centre — routes the user to the NetWix LINE Official Account. */
    public function help(): View
    {
        return view('frontend.help', [
            'lineUrl' => config('services.support.line_url'),
            'email' => config('services.support.email'),
        ]);
    }

    /** Privacy policy (นโยบายความเป็นส่วนตัว). */
    public function privacy(): View
    {
        return view('frontend.legal.privacy', ['updated' => '3 กรกฎาคม 2568']);
    }

    /** Terms of service (ข้อตกลงและเงื่อนไขการใช้งาน). */
    public function terms(): View
    {
        return view('frontend.legal.terms', ['updated' => '3 กรกฎาคม 2568']);
    }
}
