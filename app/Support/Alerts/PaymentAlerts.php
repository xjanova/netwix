<?php

namespace App\Support\Alerts;

use App\Models\AdBooking;
use App\Models\UsdtOrder;
use App\Support\AdminAlerts;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Money alerts: a payment landing, an ad waiting for review, a transfer we could not match, and the
 * payment watcher itself going blind — plus the one security alarm money needs, the receiving wallet
 * being changed.
 *
 * All real money flows through `usdt_orders` (gold, Pro, ad bookings), and settlement is fully
 * automatic — so before this, a payment, a stuck payment and a broken watcher all looked the same
 * from the owner's side: silence.
 *
 * Every method swallows its own failures. Nothing here may stop an order from settling.
 */
final class PaymentAlerts
{
    /** Consecutive failed chain reads (one a minute) before the watcher is called broken. */
    private const WATCH_STREAK = 5;

    private const PURPOSE = [
        'gold' => 'ซื้อเหรียญทอง',
        'pro' => 'สมัคร Pro',
        'ad' => 'ค่าโฆษณา',
    ];

    public static function paid(UsdtOrder $order): void
    {
        try {
            $o = $order->fresh(['user']) ?? $order;
            $ad = $o->purpose === 'ad';
            $got = match ($o->purpose) {
                'gold' => 'เหรียญทอง '.number_format((int) $o->credited_gold),
                'pro' => 'Pro '.(int) $o->pro_days.' วัน',
                default => 'โฆษณา (รออนุมัติ)',
            };
            $today = (float) UsdtOrder::whereNotNull('paid_at')
                ->where('paid_at', '>=', now('Asia/Bangkok')->startOfDay()->utc())->sum('amount_usdt');

            AdminAlerts::send(new Alert(
                key: 'paid:'.$o->reference,
                // An ad payment is money AND a job: the banner is not live until someone approves it.
                level: $ad ? Alert::WARNING : Alert::OK,
                title: $ad
                    ? 'มีเงินเข้า '.self::usdt($o->amount_usdt).' — โฆษณารออนุมัติ'
                    : 'มีเงินเข้า '.self::usdt($o->amount_usdt).' ('.(self::PURPOSE[$o->purpose] ?? $o->purpose).')',
                body: 'ออเดอร์ '.$o->reference.' · ธุรกรรม '.self::short((string) $o->tx_hash)
                    .($ad ? "\nแบนเนอร์ยังไม่ขึ้นจนกว่าจะกดอนุมัติในหน้าคิวตรวจโฆษณา" : "\nระบบเติมให้ลูกค้าอัตโนมัติแล้ว"),
                facts: [
                    'ยอดเงิน' => self::usdt($o->amount_usdt),
                    'สมาชิก' => mb_substr((string) ($o->user?->name ?? '#'.$o->user_id), 0, 32),
                    'ได้รับ' => $got,
                    'รวมวันนี้' => self::usdt($today),
                ],
                url: $ad ? route('admin.ad-market.review') : route('admin.payments.index'),
                urlLabel: $ad ? 'ไปหน้าอนุมัติโฆษณา' : 'ดูหน้าการชำระเงิน',
                category: 'money',
            ), 1440);
        } catch (Throwable) {
        }
    }

    /** A member fixed a rejected ad and sent it back — it is waiting in the review queue again. */
    public static function adResubmitted(AdBooking $booking): void
    {
        try {
            AdminAlerts::send(new Alert(
                key: 'ad-resubmit:'.$booking->id,
                level: Alert::WARNING,
                title: 'ลูกค้าแก้โฆษณาแล้วส่งมาใหม่ — รออนุมัติ',
                body: 'การจอง '.$booking->reference.' ถูกปฏิเสธไปก่อนหน้านี้ ลูกค้าแก้ไขและส่งกลับมาให้ตรวจอีกครั้ง (ไม่ต้องจ่ายซ้ำ)',
                facts: ['การจอง' => (string) $booking->reference, 'จำนวนวัน' => (int) $booking->days.' วัน'],
                url: route('admin.ad-market.review'),
                urlLabel: 'ไปหน้าอนุมัติโฆษณา',
                category: 'money',
            ), 30);
        } catch (Throwable) {
        }
    }

    /**
     * Money arrived in our wallet that no open order claims — most often a customer who paid after
     * the order's timer ran out. Nothing credits them automatically, so a human has to.
     *
     * @param  array<string,mixed>  $t  the BscScan transfer row
     * @return bool true when this call reported it (false: already reported, or no channel)
     */
    public static function unmatched(array $t, float $amount, ?UsdtOrder $likely): bool
    {
        try {
            $hash = strtolower((string) ($t['hash'] ?? ''));
            $when = isset($t['timeStamp']) ? now()->setTimestamp((int) $t['timeStamp'])->setTimezone('Asia/Bangkok')->format('d/m H:i') : '—';

            return AdminAlerts::send(new Alert(
                key: 'unmatched:'.$hash,
                level: Alert::WARNING,
                title: 'มีเงินโอนเข้ากระเป๋า แต่จับคู่ออเดอร์ไม่ได้',
                body: ($likely
                    ? 'น่าจะเป็นออเดอร์ '.$likely->reference.' ของ '.mb_substr((string) ($likely->user?->name ?? '#'.$likely->user_id), 0, 32)
                        .' ('.(self::PURPOSE[$likely->purpose] ?? $likely->purpose).') ที่หมดเวลาไปก่อนเงินเข้า'
                    : 'ยอดไม่ตรงกับออเดอร์ใดเลย — อาจโอนผิดยอด หรือโอนเข้ามาเอง')
                    ."\nระบบไม่เติมให้อัตโนมัติ ต้องตรวจสอบแล้วจัดการให้ลูกค้าเอง"
                    ."\nธุรกรรม ".self::short($hash),
                facts: ['ยอดเงิน' => self::usdt($amount), 'จาก' => self::short((string) ($t['from'] ?? '')), 'เวลา' => $when],
                url: route('admin.payments.index'),
                urlLabel: 'ดูหน้าการชำระเงิน',
                category: 'money',
            ), 60 * 24 * 60);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * One chain read failed. Only a STREAK is worth an alarm — a single BscScan timeout is normal —
     * and only while someone is actually waiting on a payment.
     */
    public static function watchFailed(string $reason, int $openOrders): void
    {
        try {
            Cache::add('usdt:watch:fails', 0, now()->addDay());
            $streak = (int) Cache::increment('usdt:watch:fails');
            if ($streak < self::WATCH_STREAK || $openOrders < 1) {
                return;
            }
            AdminAlerts::send(new Alert(
                key: 'usdt-watch-broken',
                level: Alert::CRITICAL,
                title: 'ระบบตรวจยอดโอน USDT ใช้งานไม่ได้',
                body: 'สาเหตุ: '.Redact::text($reason)
                    ."\nลูกค้าที่โอนแล้วจะยังไม่ได้รับเหรียญ/Pro/โฆษณา จนกว่าจะแก้ — เงินไม่หาย แต่ระบบยังไม่เห็น",
                facts: ['ล้มเหลวติดกัน' => $streak.' ครั้ง', 'ออเดอร์ที่รออยู่' => $openOrders.' รายการ'],
                url: route('admin.payments.index'),
                urlLabel: 'ตรวจการตั้งค่าการชำระเงิน',
                category: 'money',
            ), 180);
        } catch (Throwable) {
        }
    }

    public static function watchOk(): void
    {
        try {
            Cache::forget('usdt:watch:fails');
        } catch (Throwable) {
        }
    }

    /**
     * The receiving wallet or the BscScan key was changed. If that wasn't the owner, every payment
     * from here on goes to someone else — the one change in the admin that must never be silent.
     */
    public static function walletChanged(string $old, string $new, bool $keyChanged, ?string $by): void
    {
        try {
            $facts = [];
            if (strtolower($old) !== strtolower($new)) {
                $facts['กระเป๋าเดิม'] = $old !== '' ? self::short($old) : '(ว่าง)';
                $facts['กระเป๋าใหม่'] = $new !== '' ? self::short($new) : '(ว่าง)';
            }
            if ($keyChanged) {
                $facts['BscScan API key'] = 'ถูกเปลี่ยน/ลบ';
            }
            if ($facts === []) {
                return;
            }
            $facts['โดย'] = mb_substr((string) ($by ?? 'ไม่ทราบ'), 0, 32);

            AdminAlerts::send(new Alert(
                key: 'wallet-change:'.sha1($old.'>'.$new.'|'.(int) $keyChanged.'|'.now()->timestamp),
                level: Alert::CRITICAL,
                title: 'มีการเปลี่ยนการตั้งค่ารับเงิน USDT',
                body: 'ถ้าไม่ได้เปลี่ยนเอง ให้รีบแก้กลับและเปลี่ยนรหัสผ่านแอดมินทันที — เงินที่ลูกค้าโอนหลังจากนี้จะเข้ากระเป๋าตามการตั้งค่าใหม่',
                facts: $facts,
                url: route('admin.payments.index'),
                urlLabel: 'ตรวจการตั้งค่าการชำระเงิน',
                category: 'security',
            ), 1);
        } catch (Throwable) {
        }
    }

    private static function usdt(float|string|null $v): string
    {
        $s = rtrim(rtrim(number_format((float) $v, 6, '.', ','), '0'), '.');

        return ($s === '' ? '0' : $s).' USDT';
    }

    /** 0x1234…abcd — enough to recognise, short enough for a tile. */
    private static function short(string $hex): string
    {
        return strlen($hex) > 14 ? substr($hex, 0, 6).'…'.substr($hex, -4) : ($hex !== '' ? $hex : '—');
    }
}
