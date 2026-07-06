<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\UsdtOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Real USDT (BEP20 / BSC) payments — buy gold or Pro with on-chain crypto.
 *
 * The server is RECEIVE-ONLY: it holds no private key. It hands the buyer a
 * receiving address + an EXACT amount (the price plus a tiny per-order offset),
 * then confirms the deposit by reading the chain via BscScan. A deposit settles
 * an order only when ALL of these hold, which is what makes it un-pumpable:
 *   1. paid to OUR wallet, in the USDT contract, for the EXACT unique amount;
 *   2. confirmed ≥ min_confirmations (final, not a droppable pending tx);
 *   3. the tx happened AFTER the order was created;
 *   4. the tx hash has never settled any order (UNIQUE column = replay guard).
 * Settling is row-locked + idempotent, so the watcher and the "check now" button
 * can both run without ever double-crediting.
 *
 * Config: pricing/policy lives in Membership config `usdt`; the wallet address is
 * Setting `usdt_wallet_address`, and the BscScan key is the SECRET Setting
 * `bscscan_api_key` (encrypted at rest — never in the exposed JSON config).
 */
class UsdtPayment
{
    public function __construct(private Membership $m) {}

    // ---- Config -------------------------------------------------------

    public function walletAddress(): string
    {
        return trim((string) Setting::get('usdt_wallet_address', ''));
    }

    private function apiKey(): string
    {
        return (string) Setting::get('bscscan_api_key', '');
    }

    private function apiBase(): string
    {
        return (string) Setting::get('bscscan_api_base', 'https://api.bscscan.com/api');
    }

    private function usdt(): array
    {
        return $this->m->config()['usdt'];
    }

    public function contract(): string
    {
        return (string) ($this->usdt()['contract'] ?? '');
    }

    public function decimals(): int
    {
        return (int) ($this->usdt()['decimals'] ?? 18);
    }

    public function minConfirmations(): int
    {
        return max(1, (int) ($this->usdt()['min_confirmations'] ?? 12));
    }

    public function ttlMinutes(): int
    {
        return max(5, (int) ($this->usdt()['order_ttl_minutes'] ?? 60));
    }

    /** Payments can be created only when enabled AND a receiving wallet is set. */
    public function enabled(): bool
    {
        return (bool) ($this->usdt()['enabled'] ?? false) && $this->walletAddress() !== '';
    }

    private function assertReady(): void
    {
        if (! $this->enabled()) {
            throw new RuntimeException('ระบบชำระเงิน USDT ยังไม่พร้อมใช้งาน');
        }
    }

    // ---- Create orders ------------------------------------------------

    /** Buy gold with $usdt USDT. Gold credited = floor(usdt × per_usdt). */
    public function createGoldOrder(User $u, float $usdt): UsdtOrder
    {
        $this->assertReady();
        $g = $this->m->config()['gold'];
        $min = (float) ($g['min_usdt'] ?? 1);
        if ($usdt < $min) {
            throw new RuntimeException("ขั้นต่ำ {$min} USDT");
        }
        $gold = (int) floor($usdt * (float) ($g['per_usdt'] ?? 0));
        if ($gold < 1) {
            throw new RuntimeException('จำนวนไม่ถูกต้อง');
        }

        return $this->makeOrder($u, 'gold', round($usdt, 6), $gold, 0);
    }

    /** Buy Pro with USDT (price + duration from config). */
    public function createProOrder(User $u): UsdtOrder
    {
        $this->assertReady();
        $cfg = $this->usdt();
        $price = (float) ($cfg['pro_price_usdt'] ?? 0);
        if ($price <= 0) {
            throw new RuntimeException('ยังไม่เปิดขาย Pro ผ่าน USDT');
        }

        return $this->makeOrder($u, 'pro', round($price, 6), 0, (int) ($cfg['pro_days'] ?? 30));
    }

    private function makeOrder(User $u, string $purpose, float $base, int $gold, int $days): UsdtOrder
    {
        $wallet = $this->walletAddress();

        return UsdtOrder::create([
            'reference' => $this->newReference(),
            'user_id' => $u->id,
            'purpose' => $purpose,
            'status' => 'pending',
            'wallet' => $wallet,
            'base_usdt' => $base,
            'amount_usdt' => $this->uniqueAmount($base, $wallet),
            'credited_gold' => $gold,
            'pro_days' => $days,
            'expires_at' => now()->addMinutes($this->ttlMinutes()),
        ]);
    }

    private function newReference(): string
    {
        do {
            $ref = 'NX'.strtoupper(Str::random(8));
        } while (UsdtOrder::where('reference', $ref)->exists());

        return $ref;
    }

    /**
     * The exact amount to send = base + a tiny random offset (≤ ~0.01 USDT), kept
     * distinct from every other OPEN order to the same wallet so a deposit maps to
     * exactly one order.
     */
    private function uniqueAmount(float $base, string $wallet): float
    {
        for ($i = 0; $i < 10; $i++) {
            $amount = round($base + random_int(1, 9999) / 1_000_000, 6);
            // Compare as a fixed 6-dp string so the decimal column match is exact (never a float mismatch).
            $taken = UsdtOrder::open()->where('wallet', $wallet)
                ->where('amount_usdt', number_format($amount, 6, '.', ''))->exists();
            if (! $taken) {
                return $amount;
            }
        }

        return round($base + random_int(1, 99999) / 1_000_000, 6);
    }

    // ---- Verify / settle ---------------------------------------------

    /** Live-check ONE order against the chain (the "ตรวจสอบเดี๋ยวนี้" button). */
    public function verify(UsdtOrder $order): UsdtOrder
    {
        if (! $order->isPending()) {
            return $order;
        }
        if ($order->isExpired()) {
            $order->update(['status' => 'expired']);

            return $order;
        }

        $wallet = strtolower($this->walletAddress());
        if ($wallet === '') {
            return $order;
        }

        $match = $this->matchTransfer($order, $this->fetchTransfers(), $wallet);
        if ($match) {
            $this->settle($order, $match);
        }

        return $order->fresh();
    }

    /** Batch: expire stale orders, then settle every open order that got paid. Used by the cron watcher. */
    public function watch(): array
    {
        $expired = $this->expireStale();

        $wallet = strtolower($this->walletAddress());
        if ($wallet === '') {
            return ['settled' => 0, 'open' => 0, 'expired' => $expired];
        }

        $transfers = $this->fetchTransfers();
        $open = UsdtOrder::open()->orderBy('id')->get();
        $settled = 0;
        foreach ($open as $order) {
            $match = $this->matchTransfer($order, $transfers, $wallet);
            if ($match && $this->settle($order, $match)) {
                $settled++;
            }
        }

        return ['settled' => $settled, 'open' => $open->count(), 'expired' => $expired];
    }

    public function expireStale(): int
    {
        return UsdtOrder::where('status', 'pending')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired']);
    }

    /**
     * Find a chain transfer that legitimately settles $order. Returns the raw
     * BscScan row or null. All four anti-spoof checks live here.
     *
     * @param  array<int,array<string,mixed>>  $transfers
     */
    private function matchTransfer(UsdtOrder $order, array $transfers, string $walletLower): ?array
    {
        $contract = strtolower($this->contract());
        $minConf = $this->minConfirmations();
        $expectedMicros = (int) round(((float) $order->amount_usdt) * 1_000_000);
        $notBefore = $order->created_at->getTimestamp() - 120;   // small clock skew

        foreach ($transfers as $t) {
            if (strtolower((string) ($t['to'] ?? '')) !== $walletLower) {
                continue;                                        // must pay OUR wallet
            }
            if (strtolower((string) ($t['from'] ?? '')) === $walletLower) {
                continue;                                        // ignore our own outgoing
            }
            if (isset($t['contractAddress']) && $contract !== '' && strtolower((string) $t['contractAddress']) !== $contract) {
                continue;                                        // must be the USDT token
            }
            if ((int) ($t['confirmations'] ?? 0) < $minConf) {
                continue;                                        // not final yet
            }
            $ts = (int) ($t['timeStamp'] ?? 0);
            if ($ts && $ts < $notBefore) {
                continue;                                        // predates the order → not for it
            }
            $dec = (int) ($t['tokenDecimal'] ?? $this->decimals());
            if ($this->valueToMicros((string) ($t['value'] ?? '0'), $dec) !== $expectedMicros) {
                continue;                                        // amount must match EXACTLY
            }
            $hash = strtolower((string) ($t['hash'] ?? ''));
            if ($hash === '' || UsdtOrder::where('tx_hash', $hash)->exists()) {
                continue;                                        // already settled some order → replay
            }

            return $t;
        }

        return null;
    }

    /**
     * Credit an order from a matched transfer. Row-locked + idempotent: only a
     * still-pending order is ever credited, and the UNIQUE tx_hash column is the
     * last-line guard against one tx paying two orders.
     */
    private function settle(UsdtOrder $order, array $t): bool
    {
        try {
            return DB::transaction(function () use ($order, $t) {
                $o = UsdtOrder::whereKey($order->id)->lockForUpdate()->first();
                if (! $o || ! $o->isPending()) {
                    return false;
                }
                $hash = strtolower((string) $t['hash']);
                if (UsdtOrder::where('tx_hash', $hash)->where('id', '!=', $o->id)->exists()) {
                    return false;
                }

                $o->forceFill([
                    'status' => 'paid',
                    'tx_hash' => $hash,
                    'from_address' => strtolower((string) ($t['from'] ?? '')),
                    'confirmations' => (int) ($t['confirmations'] ?? 0),
                    'paid_at' => now(),
                ])->save();

                $u = $o->user;
                if ($o->purpose === 'gold' && $o->credited_gold > 0) {
                    app(GoldWallet::class)->addGold($u, (int) $o->credited_gold, 'purchase', [
                        'order' => $o->reference, 'tx' => $hash,
                    ]);
                } elseif ($o->purpose === 'pro' && $o->pro_days > 0) {
                    $this->m->grantProDays($u, (int) $o->pro_days);
                    $this->m->distributeProDividend($u);
                }

                return true;
            });
        } catch (Throwable $e) {
            report($e);   // e.g. a unique-hash race → left pending, retried next tick

            return false;
        }
    }

    // ---- Chain read ---------------------------------------------------

    /**
     * Recent USDT transfers involving our wallet, via BscScan's tokentx endpoint.
     * Returns [] on any error/misconfig — a failed read never settles anything.
     *
     * @return array<int,array<string,mixed>>
     */
    private function fetchTransfers(): array
    {
        $wallet = $this->walletAddress();
        if ($wallet === '' || $this->apiKey() === '') {
            return [];
        }

        try {
            $res = Http::timeout(15)->get($this->apiBase(), [
                'module' => 'account',
                'action' => 'tokentx',
                'contractaddress' => $this->contract(),
                'address' => $wallet,
                'page' => 1,
                'offset' => 100,
                'sort' => 'desc',
                'apikey' => $this->apiKey(),
            ]);
            if (! $res->ok()) {
                return [];
            }
            $result = $res->json('result');

            return is_array($result) ? $result : [];   // "0" status returns a string message
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * Convert a raw token value (base units string) to integer micro-USDT (6 dp),
     * truncating, without float/overflow. e.g. "3047200000000000000" @18 → 3047200.
     */
    private function valueToMicros(string $value, int $decimals): int
    {
        $value = ltrim($value, '0');
        if ($value === '') {
            return 0;
        }
        $drop = $decimals - 6;
        if ($drop > 0) {
            $value = strlen($value) > $drop ? substr($value, 0, strlen($value) - $drop) : '0';
        } elseif ($drop < 0) {
            $value .= str_repeat('0', -$drop);
        }

        return (int) $value;
    }

    // ---- Client payload ----------------------------------------------

    /** The shape the app/web needs to render + poll a payment. */
    public function payload(UsdtOrder $o): array
    {
        return [
            'reference' => $o->reference,
            'purpose' => $o->purpose,
            'status' => $o->isExpired() ? 'expired' : $o->status,
            'wallet' => $o->wallet,
            'network' => 'BEP20 (BSC)',
            'qr' => $o->wallet,                                              // QR encodes the receiving address
            'amount_usdt' => number_format((float) $o->amount_usdt, 6, '.', ''),
            'base_usdt' => number_format((float) $o->base_usdt, 2, '.', ''),
            'credited_gold' => (int) $o->credited_gold,
            'pro_days' => (int) $o->pro_days,
            'tx_hash' => $o->tx_hash,
            'confirmations' => (int) $o->confirmations,
            'min_confirmations' => $this->minConfirmations(),
            'paid_at' => optional($o->paid_at)->toIso8601String(),
            'expires_at' => optional($o->expires_at)->toIso8601String(),
        ];
    }
}
