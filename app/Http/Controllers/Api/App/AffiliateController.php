<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\CoinTransaction;
use App\Models\User;
use App\Services\Membership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The member's affiliate downline for the "My Team" screen: members per level
 * and the dividend coins earned from each level. Read-only. (auth.apptoken)
 */
class AffiliateController extends Controller
{
    public function __construct(private Membership $m) {}

    public function team(Request $request): JsonResponse
    {
        $u = $request->user();
        $aff = $this->m->config()['affiliate'] ?? [];
        $pcts = array_values($aff['level_pct'] ?? []);
        $levels = count($pcts);

        $rows = [];
        $frontier = [$u->id];
        for ($lvl = 1; $lvl <= $levels; $lvl++) {
            $members = User::whereIn('referred_by', $frontier)
                ->orderByDesc('id')->get(['id', 'name', 'avatar', 'created_at']);

            $dividend = (int) CoinTransaction::where('user_id', $u->id)
                ->where('kind', 'dividend')->where('level', $lvl)->sum('amount');

            $rows[] = [
                'level' => $lvl,
                'pct' => (float) $pcts[$lvl - 1],
                'count' => $members->count(),
                'dividend' => $dividend,
                'members' => $members->map(fn (User $m) => [
                    'name' => $m->name,
                    'avatar' => $m->avatar,
                    'joined' => $m->created_at?->toDateString(),
                ])->all(),
            ];

            $frontier = $members->pluck('id')->all();
            if ($frontier === []) {
                break;
            }
        }

        $code = $this->m->ensureCode($u);

        return response()->json(['success' => true, 'data' => [
            'enabled' => (bool) ($aff['enabled'] ?? false),
            'referral_code' => $code,
            'share_url' => 'https://netwix.online/r/'.$code,
            'total_dividend' => (int) CoinTransaction::where('user_id', $u->id)->where('kind', 'dividend')->sum('amount'),
            'total_members' => array_sum(array_column($rows, 'count')),
            'levels' => $rows,
        ]]);
    }
}
