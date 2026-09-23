<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        // is_active too: EndSuspendedSession normally catches a suspended admin first, but this gate
        // must not depend on another middleware's place in the stack.
        if (! $request->user() || ! $request->user()->isAdmin() || ! $request->user()->is_active) {
            abort(403, 'เฉพาะผู้ดูแลระบบเท่านั้น');
        }

        return $next($request);
    }
}
