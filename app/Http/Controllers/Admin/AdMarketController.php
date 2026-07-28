<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdBooking;
use App\Models\AdPlacement;
use App\Models\Setting;
use App\Support\Ads;
use App\Support\AdMarketplace;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin side of the self-serve ad marketplace: what's for sale and at what price, the review queue,
 * and the booking calendar.
 *
 * The calendar here shows WHO booked each day. The public one deliberately shows only how full a day
 * is — the owner's rule: "ไม่บอกว่าใคร มีแต่แอดมินที่เห็น". Both read the same occupancy data; only this
 * view joins it back to advertisers.
 */
class AdMarketController extends Controller
{
    public function __construct(private AdMarketplace $market) {}

    public function index(): View
    {
        $placements = AdPlacement::orderBy('sort')->orderBy('id')->get();

        return view('admin.ad-market.index', [
            'placements' => $placements,
            'slots' => Ads::SLOTS,
            'paidShare' => max(0, min(100, (int) Setting::get('ad_paid_share', 70))),
            'pendingCount' => AdBooking::where('status', 'paid')->count(),
            'stats' => [
                'active' => AdBooking::runnable()->count(),
                'revenue' => (float) AdBooking::whereIn('status', ['paid', 'approved', 'finished'])->sum('price_usdt'),
            ],
            'reach' => $placements->mapWithKeys(fn ($p) => [$p->id => $this->market->reach($p)]),
        ]);
    }

    /** Price/limits per placement + the paid-vs-network split. */
    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ad_paid_share' => ['required', 'integer', 'between:0,100'],
            'ad_block_keywords' => ['nullable', 'string', 'max:5000'],
            'ad_block_domains' => ['nullable', 'string', 'max:5000'],
            'ad_rules_html' => ['nullable', 'string', 'max:20000'],
        ]);

        Setting::write('ad_paid_share', (string) $data['ad_paid_share']);
        Setting::write('ad_block_keywords', trim((string) ($data['ad_block_keywords'] ?? '')));
        Setting::write('ad_block_domains', trim((string) ($data['ad_block_domains'] ?? '')));
        Setting::write('ad_rules_html', trim((string) ($data['ad_rules_html'] ?? '')));

        return back()->with('status', 'บันทึกการตั้งค่าตลาดโฆษณาแล้ว');
    }

    public function storePlacement(Request $request): RedirectResponse
    {
        AdPlacement::create($this->placementData($request));

        return back()->with('status', 'เพิ่มตำแหน่งขายแล้ว');
    }

    public function updatePlacement(Request $request, AdPlacement $placement): RedirectResponse
    {
        $placement->update($this->placementData($request));

        return back()->with('status', 'บันทึกตำแหน่งแล้ว');
    }

    public function destroyPlacement(AdPlacement $placement): RedirectResponse
    {
        if ($placement->bookings()->exists()) {
            return back()->withErrors(['placement' => 'ลบไม่ได้ — ตำแหน่งนี้มีคนจองอยู่ ปิดใช้งานแทนได้']);
        }
        $placement->delete();

        return back()->with('status', 'ลบตำแหน่งแล้ว');
    }

    /** The review queue: paid ads waiting on a human, newest first. */
    public function review(): View
    {
        return view('admin.ad-market.review', [
            'bookings' => AdBooking::whereIn('status', ['paid', 'approved', 'rejected'])
                ->with(['placement', 'user'])
                ->orderByRaw("FIELD(status,'paid','rejected','approved')")
                ->latest()
                ->paginate(20),
        ]);
    }

    public function approve(AdBooking $booking): RedirectResponse
    {
        abort_unless(in_array($booking->status, ['paid', 'rejected'], true), 422);

        $booking->forceFill([
            'status' => 'approved',
            'review_note' => null,
            'reviewed_at' => now(),
            'reviewed_by' => auth()->id(),
        ])->save();

        return back()->with('status', 'อนุมัติโฆษณา '.$booking->reference.' แล้ว');
    }

    /**
     * Reject with a reason. NOT refunded — the buyer accepted that at checkout — so the reason has to
     * be specific enough to act on, which is why it's required rather than optional.
     */
    public function reject(Request $request, AdBooking $booking): RedirectResponse
    {
        $data = $request->validate([
            'review_note' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'review_note.required' => 'ต้องระบุเหตุผล เพื่อให้ลูกค้าแก้ไขได้ถูกจุด',
        ]);

        $booking->forceFill([
            'status' => 'rejected',
            'review_note' => $data['review_note'],
            'reviewed_at' => now(),
            'reviewed_by' => auth()->id(),
        ])->save();

        return back()->with('status', 'ปฏิเสธโฆษณา '.$booking->reference.' แล้ว');
    }

    /** Full booking calendar WITH advertiser identity — admin-only by design. */
    public function calendar(Request $request): View
    {
        $placement = AdPlacement::find($request->integer('placement')) ?: AdPlacement::orderBy('sort')->first();
        $from = CarbonImmutable::today();

        $bookings = $placement
            ? AdBooking::where('ad_placement_id', $placement->id)
                ->holdingCapacity()
                ->whereDate('ends_at', '>=', $from->toDateString())
                ->with('user')
                ->orderBy('starts_at')
                ->get()
            : collect();

        return view('admin.ad-market.calendar', [
            'placements' => AdPlacement::orderBy('sort')->get(),
            'placement' => $placement,
            'days' => $placement ? $this->market->calendar($placement) : [],
            'bookings' => $bookings,
        ]);
    }

    /** @return array<string,mixed> */
    private function placementData(Request $request): array
    {
        return $request->validate([
            'slot' => ['required', 'in:'.implode(',', Ads::SLOTS)],
            'name' => ['required', 'string', 'max:120'],
            'blurb' => ['nullable', 'string', 'max:255'],
            'width' => ['required', 'integer', 'between:100,2000'],
            'height' => ['required', 'integer', 'between:50,1200'],
            'price_usdt_per_day' => ['required', 'numeric', 'between:0.01,10000'],
            'max_concurrent' => ['required', 'integer', 'between:1,20'],
            'max_days' => ['required', 'integer', 'between:1,365'],
            'max_upload_kb' => ['required', 'integer', 'between:50,5000'],
            'sort' => ['nullable', 'integer', 'between:0,10000'],
        ]) + ['is_active' => $request->boolean('is_active')];
    }
}
