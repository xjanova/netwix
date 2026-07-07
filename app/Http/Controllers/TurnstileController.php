<?php

namespace App\Http\Controllers;

use App\Support\Turnstile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Session "I'm human" endpoint behind the search gate (TurnstileSearchGate). A verified token
 * flags the whole session, so a guest solves the widget at most once per visit.
 */
class TurnstileController extends Controller
{
    public function verify(Request $request): JsonResponse
    {
        if (Turnstile::passes($request->input('cf-turnstile-response'), $request->ip())) {
            $request->session()->put('turnstile_human', true);

            return response()->json(['ok' => true]);
        }

        return response()->json(['ok' => false, 'message' => 'ยืนยันว่าไม่ใช่บอทไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'], 422);
    }
}
