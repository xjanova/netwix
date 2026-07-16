<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\AppRelease;
use Illuminate\Support\HtmlString;
use Illuminate\View\View;

class PageController extends Controller
{
    /** App download / install landing page. */
    public function download(AppRelease $release): View
    {
        return view('frontend.download', ['release' => $release->latest()]);
    }

    /** Help centre — routes the user to the NetWix LINE Official Account. */
    public function help(): View
    {
        return view('frontend.help', [
            'lineUrl' => config('services.support.line_url'),
            'email' => config('services.support.email'),
        ]);
    }

    /** Privacy policy (นโยบายความเป็นส่วนตัว). Admin override wins; built-in Blade is the fallback. */
    public function privacy(): View
    {
        return view('frontend.legal.privacy', [
            'updated' => Setting::get('legal_updated_at') ?: '3 กรกฎาคม 2568',
            'custom' => self::renderLegalBody(Setting::get('legal_privacy_body')),
        ]);
    }

    /** Terms of service (ข้อตกลงและเงื่อนไขการใช้งาน). Admin override wins; built-in Blade is the fallback. */
    public function terms(): View
    {
        return view('frontend.legal.terms', [
            'updated' => Setting::get('legal_updated_at') ?: '3 กรกฎาคม 2568',
            'custom' => self::renderLegalBody(Setting::get('legal_terms_body')),
        ]);
    }

    /**
     * Render an admin-authored plain-text legal body to safe HTML: "## หัวข้อ"
     * lines become <h2>, "- " lines become bullets, blank-line-separated blocks
     * become paragraphs. Everything is escaped — admins write text, not HTML.
     */
    public static function renderLegalBody(?string $body): ?HtmlString
    {
        $body = trim((string) $body);
        if ($body === '') {
            return null;
        }

        $html = '';
        $list = false;
        $closeList = function () use (&$html, &$list) {
            if ($list) {
                $html .= "</ul>\n";
                $list = false;
            }
        };

        foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
            $line = trim($line);
            if ($line === '') {
                $closeList();
            } elseif (str_starts_with($line, '## ')) {
                $closeList();
                $html .= '<h2>'.e(substr($line, 3))."</h2>\n";
            } elseif (str_starts_with($line, '- ')) {
                if (! $list) {
                    $html .= "<ul>\n";
                    $list = true;
                }
                $html .= '<li>'.e(substr($line, 2))."</li>\n";
            } else {
                $closeList();
                $html .= '<p>'.e($line)."</p>\n";
            }
        }
        $closeList();

        return new HtmlString($html);
    }
}
