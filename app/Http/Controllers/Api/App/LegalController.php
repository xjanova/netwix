<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Support\LegalDocs;
use Illuminate\Http\JsonResponse;

/**
 * Serves the legal text as structured blocks so the app can render it ITSELF.
 *
 * The app used to open netwix.online/terms in a WebView, which meant the viewer got the whole
 * website: its footer alone links to /movies, /series, /anime, every /genre/*, /login and /register,
 * and the WebView followed all of them. One tap and the person was browsing the site inside the app
 * with none of the app's own navigation — "สับสนหมด". Handing over blocks instead of a page means
 * there is nothing to navigate away to.
 *
 * Blocks, not HTML, deliberately: the app renders with its own typography and needs no HTML parser
 * or WebView, and there is no way for markup to smuggle a link back in.
 */
class LegalController extends Controller
{
    public function show(string $doc): JsonResponse
    {
        if (! in_array($doc, LegalDocs::DOCS, true)) {
            return response()->json(['success' => false, 'message' => 'Unknown document'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'doc' => $doc,
                'title' => LegalDocs::title($doc),
                'updated' => LegalDocs::updated(),
                'blocks' => LegalDocs::blocks($doc),
            ],
        ]);
    }
}
