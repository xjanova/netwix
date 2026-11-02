<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Membership;
use App\Services\RewardCampaigns;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RewardCampaignController extends Controller
{
    public function update(Request $request, Membership $membership): RedirectResponse
    {
        $rules = [];
        foreach (RewardCampaigns::DEFAULTS as $key => $default) {
            $rules["campaigns.$key.title"] = ['required', 'string', 'max:100'];
            $rules["campaigns.$key.target"] = ['required', 'integer', 'between:1,'.($key === 'explorer' ? 3 : 20)];
            $rules["campaigns.$key.coins"] = ['required', 'integer', 'between:0,10000'];
            $rules["campaigns.$key.seconds"] = ['required', 'integer', 'between:30,7200'];
            $rules["campaigns.$key.repeat"] = ['required', 'in:daily,weekly,once'];
            $rules["campaigns.$key.enabled"] = ['required', 'boolean'];
        }
        $data = $request->validate($rules);
        $membership->mergeConfig(['engagement' => $data['campaigns']]);

        return back()->with('status', 'บันทึกแคมเปญเว็บและแอปแล้ว');
    }
}
