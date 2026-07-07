<?php

namespace App\Http\Middleware;

use App\Support\Turnstile;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Human gate for public search — once per session, not per request. Search is the most abusable
 * read endpoint we have (every query = an unindexable double LIKE over the whole catalog + a
 * SearchQuery log row), so a guest must pass Turnstile once before their first real search;
 * after that the session is flagged and both the results page and the type-ahead flow freely.
 * Members skip it (their session already passed the login Turnstile), and the whole gate is a
 * no-op until the owner configures keys (same dormant pattern as VerifyTurnstile).
 */
class TurnstileSearchGate
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Turnstile::enabled()
            || $request->user() !== null
            || $request->session()->get('turnstile_human') === true
            || trim((string) $request->query('q', '')) === '') {   // empty page: no scan, no log
            return $next($request);
        }

        // Nav type-ahead fails soft: an unverified guest just gets no suggestions — the full
        // search they submit next hits the gate page below and unlocks the whole session.
        if ($request->routeIs('search.suggest')) {
            return response()->json(['results' => []]);
        }

        // 403 + noindex keeps crawlers from indexing or looping on the gate. Humans barely see
        // it: Managed mode usually solves invisibly and the page continues to the results.
        return response()->view('frontend.turnstile-gate', [
            'target' => route('search', ['q' => $request->query('q')]),
        ], 403);
    }
}
