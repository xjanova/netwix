<?php

namespace App\Http\Controllers;

use App\Models\AdBooking;
use App\Models\AdPlacement;
use App\Models\Setting;
use App\Services\UsdtPayment;
use App\Support\AdCreative;
use App\Support\AdMarketplace;
use App\Support\AdScreening;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Self-serve ad buying: pick a position, pick dates, upload a banner, pay in USDT/USDC (BEP20).
 *
 * Two rules from the owner shape the whole flow and are enforced here rather than trusted to the UI:
 *  - the destination link is SCREENED BEFORE the buyer is shown a payment QR ([AdScreening]), because
 *    the money is non-refundable and taking it for a link that was never going to be approved would
 *    be indefensible;
 *  - paying does not put the ad on screen. It queues it for admin review, and a rejection is not
 *    refunded — the buyer fixes the creative and resubmits. That is only fair if it was stated up
 *    front, so the rules and the no-refund warning must be acknowledged before an invoice exists
 *    (`terms_accepted_at`).
 *
 * Prices, capacity and reach come from [AdMarketplace]; nothing money-related is read from the request.
 */
class AdvertiseController extends Controller
{
    public function __construct(private AdMarketplace $market) {}

    /** The shop front: every position with its price, real reach, and how full its queue is. */
    public function index(): View
    {
        $placements = AdPlacement::active()->orderBy('sort')->orderBy('id')->get();

        $cards = $placements->map(fn (AdPlacement $p) => [
            'placement' => $p,
            'reach' => $this->market->reach($p),
            'pressure' => $this->market->queuePressure($p),
            'calendar' => $this->market->calendar($p, days: 30),
        ]);

        return view('frontend.advertise.index', [
            'cards' => $cards,
            'rules' => $this->rules(),
            'mine' => AdBooking::where('user_id', auth()->id())
                ->with('placement')->latest()->limit(5)->get(),
        ]);
    }

    /** The booking form for one position. */
    public function create(AdPlacement $placement): View
    {
        abort_unless($placement->is_active, 404);

        return view('frontend.advertise.create', [
            'p' => $placement,
            'reach' => $this->market->reach($placement),
            'calendar' => $this->market->calendar($placement),
            'rules' => $this->rules(),
        ]);
    }

    /**
     * Create the booking. Everything that can refuse the buyer runs BEFORE an invoice is issued:
     * capacity, the terms acknowledgement, the link screen, then the image. Only then do we ask for
     * money.
     */
    public function store(Request $request, AdPlacement $placement, AdScreening $screen, AdCreative $creative, UsdtPayment $usdt): RedirectResponse
    {
        abort_unless($placement->is_active, 404);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'link_url' => ['required', 'url:http,https', 'max:2048'],
            'starts_at' => ['required', 'date', 'after_or_equal:today'],
            'days' => ['required', 'integer', 'min:1', 'max:'.max(1, (int) $placement->max_days)],
            'image_file' => ['required', 'file', 'max:'.max(1, (int) $placement->max_upload_kb), 'mimes:jpg,jpeg,png,gif,webp'],
            'crop' => ['nullable', 'string', 'max:200'],
            'accept_terms' => ['accepted'],
        ], [
            'accept_terms.accepted' => 'ต้องยอมรับกติกาและเงื่อนไขการไม่คืนเงินก่อน',
            'image_file.max' => 'ไฟล์ใหญ่เกิน '.$placement->max_upload_kb.' KB',
            'starts_at.after_or_equal' => 'วันเริ่มต้องเป็นวันนี้หรือหลังจากนี้',
        ]);

        $from = CarbonImmutable::parse($data['starts_at'])->startOfDay();
        $to = $from->addDays((int) $data['days'] - 1);

        if (! $this->market->hasRoom($placement, $from, $to)) {
            return back()->withInput()->withErrors([
                'starts_at' => 'ช่วงวันที่เลือกมีคนจองเต็มแล้ว — ลองเลื่อนวันเริ่ม หรือลดจำนวนวัน',
            ]);
        }

        // The gate the owner asked for: refuse obvious no-hopers while the buyer still has their money.
        $verdict = $screen->check($data['link_url']);
        if (! $verdict['ok']) {
            return back()->withInput()->withErrors(['link_url' => $verdict['reason'] ?? 'ลิงก์ไม่ผ่านการตรวจสอบ']);
        }

        try {
            $path = $creative->store($request->file('image_file'), $placement, $this->crop($data['crop'] ?? null));
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['image_file' => $e->getMessage()]);
        }

        $booking = AdBooking::create([
            'reference' => AdBooking::newReference(),
            'user_id' => $request->user()->id,
            'ad_placement_id' => $placement->id,
            'title' => $data['title'] ?? null,
            'image_path' => $path,
            'link_url' => $data['link_url'],
            'link_final_url' => $verdict['final_url'] ?? null,
            'starts_at' => $from->toDateString(),
            'ends_at' => $to->toDateString(),
            'days' => (int) $data['days'],
            'price_usdt' => $this->market->price($placement, (int) $data['days']),
            'status' => 'awaiting_payment',
            'terms_accepted_at' => now(),
            'screen_result' => $verdict,
        ]);

        try {
            $order = $usdt->createAdOrder($request->user(), (float) $booking->price_usdt, $booking->id);
        } catch (\Throwable $e) {
            // No invoice → the booking must not sit around holding capacity.
            $booking->forceFill(['status' => 'draft'])->save();

            return back()->withInput()->withErrors(['link_url' => 'ยังเปิดรับชำระเงินไม่ได้ในตอนนี้ — '.$e->getMessage()]);
        }

        $booking->forceFill(['usdt_order_id' => $order->id])->save();

        return redirect()->route('advertise.checkout', $booking);
    }

    /** Payment page: wallet + exact amount + QR, polled until the watcher settles it. */
    public function checkout(AdBooking $booking, UsdtPayment $usdt): View
    {
        abort_unless($booking->user_id === auth()->id(), 403);
        abort_unless($booking->order !== null, 404);

        return view('frontend.advertise.checkout', [
            'booking' => $booking->load('placement'),
            'pay' => $usdt->payload($booking->order),
        ]);
    }

    /** Poll target for the checkout page — re-checks the chain on demand. */
    public function status(AdBooking $booking, UsdtPayment $usdt)
    {
        abort_unless($booking->user_id === auth()->id(), 403);
        abort_unless($booking->order !== null, 404);

        $order = $usdt->verify($booking->order);

        return response()->json([
            'paid' => $order->status === 'paid',
            'booking_status' => $booking->fresh()->status,
            'pay' => $usdt->payload($order),
        ]);
    }

    /**
     * Fix a REJECTED booking. This is the other half of "ไม่ผ่านไม่คืนเงิน แต่แก้แล้วส่งใหม่ได้": without
     * it, a rejection would be a dead end for money already taken, which would make the no-refund
     * rule indefensible rather than merely strict.
     */
    public function edit(AdBooking $booking): View
    {
        abort_unless($booking->user_id === auth()->id(), 403);
        abort_unless($booking->status === 'rejected', 404);

        return view('frontend.advertise.edit', [
            'booking' => $booking->load('placement'),
            'p' => $booking->placement,
            'calendar' => $this->market->calendar($booking->placement),
            'rules' => $this->rules(),
        ]);
    }

    /**
     * Resubmit. The PLACEMENT and the number of DAYS are fixed — those are what was paid for, and
     * letting them change would turn a rejection into a free upgrade. The creative, the link and the
     * start date may change: the last one because a rejection can easily outlive the original window,
     * and burning the purchase on a scheduling technicality would be its own kind of unfair.
     */
    public function resubmit(Request $request, AdBooking $booking, AdScreening $screen, AdCreative $creative): RedirectResponse
    {
        abort_unless($booking->user_id === auth()->id(), 403);
        abort_unless($booking->status === 'rejected', 404);

        $placement = $booking->placement;

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'link_url' => ['required', 'url:http,https', 'max:2048'],
            'starts_at' => ['required', 'date', 'after_or_equal:today'],
            'image_file' => ['nullable', 'file', 'max:'.max(1, (int) $placement->max_upload_kb), 'mimes:jpg,jpeg,png,gif,webp'],
            'crop' => ['nullable', 'string', 'max:200'],
        ]);

        $from = CarbonImmutable::parse($data['starts_at'])->startOfDay();
        $to = $from->addDays((int) $booking->days - 1);

        // Its own row still holds capacity for the old window, so exclude it from the check.
        if (! $this->hasRoomExcluding($placement, $from, $to, $booking->id)) {
            return back()->withInput()->withErrors(['starts_at' => 'ช่วงวันที่เลือกเต็มแล้ว — ลองเลื่อนวันเริ่ม']);
        }

        $verdict = $screen->check($data['link_url']);
        if (! $verdict['ok']) {
            return back()->withInput()->withErrors(['link_url' => $verdict['reason'] ?? 'ลิงก์ไม่ผ่านการตรวจสอบ']);
        }

        $path = $booking->image_path;
        if ($request->hasFile('image_file')) {
            try {
                $new = $creative->store($request->file('image_file'), $placement, $this->crop($data['crop'] ?? null));
            } catch (RuntimeException $e) {
                return back()->withInput()->withErrors(['image_file' => $e->getMessage()]);
            }
            $creative->delete($path);
            $path = $new;
        }

        $booking->forceFill([
            'title' => $data['title'] ?? null,
            'image_path' => $path,
            'link_url' => $data['link_url'],
            'link_final_url' => $verdict['final_url'] ?? null,
            'starts_at' => $from->toDateString(),
            'ends_at' => $to->toDateString(),
            'screen_result' => $verdict,
            // Back into the queue, NOT live — a resubmission is reviewed like any other.
            'status' => 'paid',
            'review_note' => null,
            'reviewed_at' => null,
            'reviewed_by' => null,
        ])->save();

        return redirect()->route('advertise.mine')
            ->with('status', 'ส่งตรวจใหม่แล้ว — ทีมงานจะตรวจอีกครั้ง ไม่มีค่าใช้จ่ายเพิ่ม');
    }

    /** Capacity check that ignores one booking's own hold (used when rescheduling it). */
    private function hasRoomExcluding(AdPlacement $placement, CarbonImmutable $from, CarbonImmutable $to, int $ignoreId): bool
    {
        $cap = max(1, (int) $placement->max_concurrent);

        $overlapping = AdBooking::where('ad_placement_id', $placement->id)
            ->whereKeyNot($ignoreId)
            ->holdingCapacity()
            ->whereDate('ends_at', '>=', $from->toDateString())
            ->whereDate('starts_at', '<=', $to->toDateString())
            ->get(['starts_at', 'ends_at']);

        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $taken = $overlapping->filter(fn ($b) => $d->betweenIncluded(
                CarbonImmutable::parse($b->starts_at), CarbonImmutable::parse($b->ends_at)
            ))->count();
            if ($taken >= $cap) {
                return false;
            }
        }

        return true;
    }

    /** The advertiser's own campaigns, with the admin's rejection note when there is one. */
    public function mine(): View
    {
        return view('frontend.advertise.mine', [
            'bookings' => AdBooking::where('user_id', auth()->id())
                ->with('placement')->latest()->paginate(20),
        ]);
    }

    /** Admin-editable rules text; a sane default so the page is never blank on a fresh install. */
    private function rules(): string
    {
        return (string) Setting::get('ad_rules_html', '') ?: view('frontend.advertise._default-rules')->render();
    }

    /** @return array{x:float,y:float,w:float,h:float}|null */
    private function crop(?string $raw): ?array
    {
        if (! $raw) {
            return null;
        }
        $v = json_decode($raw, true);
        if (! is_array($v) || ! isset($v['x'], $v['y'], $v['w'], $v['h'])) {
            return null;
        }

        return [
            'x' => (float) $v['x'], 'y' => (float) $v['y'],
            'w' => (float) $v['w'], 'h' => (float) $v['h'],
        ];
    }
}
