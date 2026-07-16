<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\AppDebugLog;
use App\Models\AppToken;
use App\Models\CoinTransaction;
use App\Models\EpisodeUnlock;
use App\Models\GoldTransaction;
use App\Models\MissionAttempt;
use App\Models\MissionCompletion;
use App\Models\UsdtOrder;
use App\Models\User;
use App\Models\VipUnlock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Self-service account deletion (DELETE /api/app/account) — the app's "Danger
 * Zone". Hard-deletes the user and every row they own; the PDPA-style "ลบข้อมูล"
 * the policy promises. The client must echo confirm=DELETE so a stray request
 * can never wipe an account, and the app shows its own typed-confirm dialog on
 * top of that. Admin accounts must be deleted from the admin panel, not an app.
 */
class AccountController extends Controller
{
    public function destroy(Request $request): JsonResponse
    {
        $request->validate(['confirm' => ['required', 'in:DELETE']]);

        /** @var User $user */
        $user = $request->user();

        if ($user->isAdmin()) {
            return response()->json(['success' => false, 'error' => 'admin_account'], 403);
        }

        DB::transaction(function () use ($user) {
            // Downline keeps their accounts; they just lose the referrer link.
            User::where('referred_by', $user->id)->update(['referred_by' => null]);

            // Owned rows. The FKs cascade at the DB level, but delete explicitly
            // so a missing cascade can never abort the wipe halfway. Comments,
            // ratings, my-list, likes and watch-progress key on profile_id and
            // cascade when the profiles rows go.
            MissionAttempt::where('user_id', $user->id)->delete();
            MissionCompletion::where('user_id', $user->id)->delete();
            EpisodeUnlock::where('user_id', $user->id)->delete();
            VipUnlock::where('user_id', $user->id)->delete();
            UsdtOrder::where('user_id', $user->id)->delete();
            GoldTransaction::where('user_id', $user->id)->delete();
            CoinTransaction::where('user_id', $user->id)->delete();
            AppToken::where('user_id', $user->id)->delete();   // revokes every device
            // Diagnostics rows have no FK and can carry identifiable context —
            // the privacy page promises a full wipe, so take them too.
            AppDebugLog::where('user_id', $user->id)->delete();
            $user->profiles()->delete();

            $user->delete();
        });

        return response()->json(['success' => true, 'data' => ['deleted' => true]]);
    }
}
