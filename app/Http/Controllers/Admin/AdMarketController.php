<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdBooking;
use App\Models\AdPlacement;
use App\Models\Setting;
use App\Models\User;
use App\Support\AdCreative;
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
            'placements' => AdPlacement::active()->orderBy('sort')->get(),
            'users' => User::orderBy('name')->limit(200)->get(['id', 'name', 'email']),
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

    /**
     * Place an ad by hand. Needed because not every sale happens on-chain: a customer may pay by
     * bank transfer or LINE, or the owner may comp a slot. Screening is skipped deliberately — an
     * admin typing the URL themselves IS the review — and the booking lands already approved.
     */
    public function storeBooking(Request $request, AdCreative $creative): RedirectResponse
    {
        $data = $request->validate([
            'ad_placement_id' => ['required', 'exists:ad_placements,id'],
            'user_id' => ['required', 'exists:users,id'],
            'title' => ['nullable', 'string', 'max:120'],
            'link_url' => ['required', 'url:http,https', 'max:2048'],
            'starts_at' => ['required', 'date'],
            'days' => ['required', 'integer', 'min:1', 'max:365'],
            'price_usdt' => ['required', 'numeric', 'min:0'],
            'image_file' => ['required', 'file', 'max:5000', 'mimes:jpg,jpeg,png,gif,webp'],
        ]);

        $placement = AdPlacement::findOrFail($data['ad_placement_id']);
        $from = CarbonImmutable::parse($data['starts_at'])->startOfDay();

        try {
            $path = $creative->store($request->file('image_file'), $placement, null);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['image_file' => $e->getMessage()]);
        }

        AdBooking::create([
            'reference' => AdBooking::newReference(),
            'user_id' => $data['user_id'],
            'ad_placement_id' => $placement->id,
            'title' => $data['title'] ?? null,
            'image_path' => $path,
            'link_url' => $data['link_url'],
            'starts_at' => $from->toDateString(),
            'ends_at' => $from->addDays((int) $data['days'] - 1)->toDateString(),
            'days' => (int) $data['days'],
            'price_usdt' => (float) $data['price_usdt'],
            'status' => 'approved',
            'reviewed_at' => now(),
            'reviewed_by' => auth()->id(),
            'terms_accepted_at' => now(),
        ]);

        return back()->with('status', 'เพิ่มโฆษณาให้ลูกค้าแล้ว (ขึ้นแสดงตามวันที่กำหนด)');
    }

    /**
     * Edit any booking — swap the creative a customer sent over chat, correct a typo'd link, shift
     * dates. This is the tool that makes "ไม่ผ่านให้แก้แล้วส่งใหม่" workable from the staff side too.
     */
    public function updateBooking(Request $request, AdBooking $booking, AdCreative $creative): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'link_url' => ['required', 'url:http,https', 'max:2048'],
            'starts_at' => ['required', 'date'],
            'days' => ['required', 'integer', 'min:1', 'max:365'],
            'image_file' => ['nullable', 'file', 'max:5000', 'mimes:jpg,jpeg,png,gif,webp'],
        ]);

        $from = CarbonImmutable::parse($data['starts_at'])->startOfDay();
        $path = $booking->image_path;

        if ($request->hasFile('image_file')) {
            try {
                $new = $creative->store($request->file('image_file'), $booking->placement, null);
            } catch (\RuntimeException $e) {
                return back()->withErrors(['image_file' => $e->getMessage()]);
            }
            $creative->delete($path);
            $path = $new;
        }

        $booking->forceFill([
            'title' => $data['title'] ?? null,
            'image_path' => $path,
            'link_url' => $data['link_url'],
            'starts_at' => $from->toDateString(),
            'ends_at' => $from->addDays((int) $data['days'] - 1)->toDateString(),
            'days' => (int) $data['days'],
        ])->save();

        return back()->with('status', 'แก้ไขโฆษณา '.$booking->reference.' แล้ว');
    }

    /** Take a booking off the air. Kept (not deleted) so the money and the history stay auditable. */
    public function cancelBooking(AdBooking $booking): RedirectResponse
    {
        $booking->forceFill([
            'status' => 'finished',
            'review_note' => 'ยกเลิกโดยแอดมิน',
            'reviewed_at' => now(),
            'reviewed_by' => auth()->id(),
        ])->save();

        return back()->with('status', 'ยกเลิกโฆษณา '.$booking->reference.' แล้ว');
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
