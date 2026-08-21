<?php

namespace App\Http\Middleware;

use App\Support\ScrapeGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Catches credential scanning, and it has to live on the GLOBAL stack to do it.
 *
 * The behavioural rules ([DetectScraping]) sit on the web/api route groups, because they need to know
 * who is asking — a signed-in admin looks exactly like a bulk scraper, and on the global stack
 * `$request->user()` is always null. But group middleware only runs for a request that MATCHED a
 * route, and a scanner asks for things we have no route for: `/api/.env`, `/wp-login.php`,
 * `/vendor/phpunit/…`. Laravel answers 404 from the router, before any group is entered.
 *
 * That is not theoretical. With the rule sitting in the group, three live probes for `/api/.env`,
 * `/api/config.env` and `/api/aws/index.js` produced three clean 404s and **not one recorded event** —
 * the same blind spot that let one address spend 1,985 requests hunting our credentials unnoticed.
 *
 * Identity is not needed here, which is why the split is safe: no logged-in viewer, no admin and no
 * app has any reason to ask for a `.env`. Whoever is asking, the answer is the same.
 */
class DetectProbes
{
    public function handle(Request $request, Closure $next): Response
    {
        if (ScrapeGuard::inspectProbe($request)) {
            return response('Forbidden', 403);
        }

        return $next($request);
    }
}
