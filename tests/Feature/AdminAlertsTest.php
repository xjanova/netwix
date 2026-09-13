<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\SourceTitle;
use App\Models\UsdtOrder;
use App\Models\User;
use App\Support\AdminAlerts;
use App\Support\Alerts\Alert;
use App\Support\Alerts\AlertCard;
use App\Support\Alerts\ErrorAlert;
use App\Support\Alerts\PaymentAlerts;
use App\Support\Alerts\Redact;
use App\Support\SourceHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * The owner-alert pipeline: one Alert, fanned out to Telegram (drawn card) and LINE (text), claimed
 * once per throttle key, routed by category — and every event that feeds it.
 *
 * The cases here are the ways alerting fails in practice: it spams (throttle, digests), it goes
 * somewhere the owner didn't ask (routing), it leaks what it reports (redaction), or it quietly
 * reports nothing (each event actually reaching a channel).
 */
class AdminAlertsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
    }

    /**
     * Fake the chat APIs as healthy, plus whatever else a test needs. Registered per test, not in
     * setUp: the first matching stub wins, so a default here would mask a test's error responses.
     */
    private function fakeApis(array $extra = []): void
    {
        Http::fake($extra + [
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
            'api.line.me/*' => Http::response([], 200),
        ]);
    }

    private function telegramOn(): void
    {
        Setting::write('telegram_bot_token', self::TOKEN);
        Setting::write('telegram_chat_id', '555000');
        Setting::write('telegram_alerts_enabled', '1');
    }

    private function lineOn(): void
    {
        Setting::write('line_oa_token', 'line-channel-token-0123456789');
        Setting::write('line_oa_to', 'Uabcdef');
        Setting::write('line_alerts_enabled', '1');
    }

    /** A field of a Telegram request, whether it went as multipart (photo) or a form (text). */
    private static function field(Request $r, string $name): ?string
    {
        foreach ((array) $r->data() as $key => $part) {
            if (is_array($part) && ($part['name'] ?? null) === $name) {
                return (string) $part['contents'];
            }
            if ($key === $name) {
                return (string) $part;
            }
        }

        return null;
    }

    private static function isTelegramSend(Request $r): bool
    {
        return str_contains($r->url(), 'api.telegram.org') && preg_match('~/(sendPhoto|sendMessage)$~', $r->url());
    }

    /** @return array<int,Request> */
    private function telegramSends(): array
    {
        return Http::recorded()->map(fn ($pair) => $pair[0])->filter(fn (Request $r) => self::isTelegramSend($r))->values()->all();
    }

    private function alert(array $overrides = []): Alert
    {
        return new Alert(...array_merge([
            'key' => 'source-down:24hdx',
            'level' => Alert::CRITICAL,
            'title' => 'ดึงลิ้งค์จากแหล่ง "24hdx" ไม่ได้เลย',
            'body' => 'อาจเป็นเว็บต้นทางล่ม',
            'facts' => ['หนังที่กระทบ' => '6,512 เรื่อง'],
            'url' => 'https://netwix.online/admin',
            'category' => 'sources',
        ], $overrides));
    }

    // ------------------------------------------------------------------ delivery

    public function test_telegram_gets_a_card_with_an_html_caption_and_a_button(): void
    {
        $this->telegramOn();
        $this->fakeApis();

        $this->assertTrue(AdminAlerts::send($this->alert(['title' => 'แหล่ง <b>พัง</b> & ล่ม'])));

        $sends = $this->telegramSends();
        $this->assertCount(1, $sends);
        $r = $sends[0];
        $this->assertStringEndsWith(AlertCard::available() ? '/sendPhoto' : '/sendMessage', $r->url());
        if (AlertCard::available()) {
            $this->assertTrue($r->hasFile('photo'));
        }
        $this->assertSame('555000', self::field($r, 'chat_id'));
        $this->assertSame('HTML', self::field($r, 'parse_mode'));

        $caption = (string) (self::field($r, 'caption') ?? self::field($r, 'text'));
        $this->assertStringContainsString('<b>แหล่ง &lt;b&gt;พัง&lt;/b&gt; &amp; ล่ม</b>', $caption, 'title is escaped, then bolded');
        $this->assertStringContainsString('หนังที่กระทบ: <b>6,512 เรื่อง</b>', $caption);

        $markup = json_decode((string) self::field($r, 'reply_markup'), true);
        if (AlertCard::available()) {
            $this->assertSame('https://netwix.online/admin', $markup['inline_keyboard'][0][0]['url']);
        }

        $row = DB::table('line_alerts')->first();
        $this->assertSame('telegram', $row->channel);
        $this->assertEquals(1, $row->ok);
    }

    public function test_one_throttle_key_is_claimed_once_across_both_channels(): void
    {
        $this->telegramOn();
        $this->fakeApis();
        $this->lineOn();

        $this->assertTrue(AdminAlerts::send($this->alert(), 60));
        $this->assertFalse(AdminAlerts::send($this->alert(), 60), 'the same key inside its window is silent');

        $this->assertCount(1, $this->telegramSends());
        Http::assertSentCount(2);   // one Telegram + one LINE, not four
        $this->assertSame(['line', 'telegram'], DB::table('line_alerts')->orderBy('channel')->pluck('channel')->all());
    }

    public function test_categories_route_to_the_channels_the_admin_chose(): void
    {
        $this->telegramOn();
        $this->fakeApis();
        $this->lineOn();

        // By default LINE (metered) does not get the daily report.
        AdminAlerts::send($this->alert(['key' => 'daily-report:x', 'category' => 'daily']));
        $this->assertSame(['telegram'], DB::table('line_alerts')->pluck('channel')->all());

        // And a category switched off everywhere sends nothing — nor does it burn its throttle.
        AdminAlerts::saveRoutes(['telegram' => ['money'], 'line' => []]);
        $this->assertFalse(AdminAlerts::send($this->alert(['key' => 'k2'])));
        AdminAlerts::saveRoutes(['telegram' => ['sources'], 'line' => []]);
        $this->assertTrue(AdminAlerts::send($this->alert(['key' => 'k2'])), 'switching it back on is not blocked by a throttle it never used');
    }

    public function test_a_failure_is_recorded_in_thai_and_without_the_bot_token(): void
    {
        $this->telegramOn();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out for https://api.telegram.org/bot'.self::TOKEN.'/sendPhoto'));

        $this->assertFalse(AdminAlerts::send($this->alert()));

        $row = DB::table('line_alerts')->first();
        $this->assertEquals(0, $row->ok);
        $this->assertStringNotContainsString(self::TOKEN, (string) $row->error);
        $this->assertStringNotContainsString('AAHdqTcv', (string) $row->error);
    }

    public function test_chat_not_found_is_explained_and_not_retried_as_text(): void
    {
        $this->telegramOn();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found'], 400)]);

        AdminAlerts::send($this->alert());

        $this->assertCount(1, $this->telegramSends());
        $this->assertStringContainsString('ไม่พบแชท', (string) DB::table('line_alerts')->value('error'));
    }

    public function test_a_group_upgraded_to_a_supergroup_is_followed_to_its_new_id(): void
    {
        $this->telegramOn();
        Http::fake(['api.telegram.org/*' => Http::sequence()
            ->push(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: group chat was upgraded to a supergroup chat', 'parameters' => ['migrate_to_chat_id' => -1001234567890]], 400)
            ->push(['ok' => true, 'result' => []])]);

        $this->assertTrue(AdminAlerts::send($this->alert()));
        $this->assertSame('-1001234567890', Setting::get('telegram_chat_id'));
    }

    // ------------------------------------------------------------------ digests

    public function test_suspensions_and_signups_go_out_as_one_message_each(): void
    {
        $this->telegramOn();
        $this->fakeApis();
        foreach ([[1, 'เรื่อง ก', 'rongyok'], [2, 'เรื่อง ข', 'rongyok'], [3, 'เรื่อง ค', '24hdx'], [1, 'เรื่อง ก', 'rongyok']] as [$id, $t, $s]) {
            AdminAlerts::noteSuspended($id, $t, $s);
        }
        AdminAlerts::noteSignup(10, 'สมชาย', 'อีเมล');
        AdminAlerts::noteSignup(11, 'สมหญิง', 'Google');

        $this->assertSame(3, AdminAlerts::flushDigest(), 'the same title twice counts once');
        $this->assertSame(2, AdminAlerts::flushSignups());
        $this->assertSame(0, AdminAlerts::flushDigest(), 'a flushed digest is empty');

        $captions = array_map(fn (Request $r) => (string) (self::field($r, 'caption') ?? self::field($r, 'text')), $this->telegramSends());
        $this->assertCount(2, $captions);
        $this->assertStringContainsString('3 เรื่อง', $captions[0]);
        $this->assertStringContainsString('สมาชิกใหม่ 2 คน', $captions[1]);
    }

    // ------------------------------------------------------------------ events

    public function test_a_payment_that_lands_is_reported_with_what_the_member_got(): void
    {
        $this->telegramOn();
        $this->fakeApis();
        $user = User::factory()->create(['name' => 'ลูกค้าคนแรก']);
        $order = UsdtOrder::create([
            'reference' => 'NXPAID001', 'user_id' => $user->id, 'purpose' => 'gold', 'status' => 'paid',
            'wallet' => '0x'.str_repeat('a', 40), 'base_usdt' => 5, 'amount_usdt' => 5.001234, 'credited_gold' => 500,
            'tx_hash' => '0x'.str_repeat('b', 64), 'paid_at' => now(),
        ]);

        PaymentAlerts::paid($order);

        $caption = (string) (self::field($this->telegramSends()[0], 'caption') ?? self::field($this->telegramSends()[0], 'text'));
        $this->assertStringContainsString('มีเงินเข้า 5.001234 USDT', $caption);
        $this->assertStringContainsString('เหรียญทอง 500', $caption);
        $this->assertStringContainsString('ลูกค้าคนแรก', $caption);
    }

    public function test_money_that_no_open_order_claims_is_reported_with_the_order_it_likely_was(): void
    {
        $this->telegramOn();
        $wallet = '0x'.str_repeat('a', 40);
        Setting::write('usdt_wallet_address', $wallet);
        Setting::write('bscscan_api_key', 'TESTKEY123456');
        $user = User::factory()->create(['name' => 'จ่ายช้า']);
        UsdtOrder::create([
            'reference' => 'NXLATE001', 'user_id' => $user->id, 'purpose' => 'gold', 'status' => 'expired',
            'wallet' => $wallet, 'base_usdt' => 3, 'amount_usdt' => 3.0472, 'credited_gold' => 300, 'expires_at' => now()->subHour(),
        ]);
        $this->fakeApis([
            'api.bscscan.com/*' => Http::response(['status' => '1', 'message' => 'OK', 'result' => [[
                'hash' => '0x'.str_repeat('c', 64), 'from' => '0x'.str_repeat('d', 40), 'to' => $wallet,
                'contractAddress' => strtolower(app(\App\Services\UsdtPayment::class)->contract()),
                'value' => '3047200000000000000', 'tokenDecimal' => '18', 'confirmations' => '40',
                'timeStamp' => (string) now()->subMinutes(20)->timestamp,
            ]]]),
        ]);

        app(\App\Services\UsdtPayment::class)->watch();
        app(\App\Services\UsdtPayment::class)->watch();   // seen twice → still reported once

        $sends = $this->telegramSends();
        $this->assertCount(1, $sends);
        $caption = (string) (self::field($sends[0], 'caption') ?? self::field($sends[0], 'text'));
        $this->assertStringContainsString('จับคู่ออเดอร์ไม่ได้', $caption);
        $this->assertStringContainsString('NXLATE001', $caption);
    }

    public function test_a_blind_payment_watcher_is_reported_only_as_a_streak(): void
    {
        $this->telegramOn();
        Setting::write('usdt_wallet_address', '0x'.str_repeat('a', 40));
        Setting::write('bscscan_api_key', 'BADKEY123456');
        $user = User::factory()->create();
        UsdtOrder::create([
            'reference' => 'NXOPEN001', 'user_id' => $user->id, 'purpose' => 'gold', 'status' => 'pending',
            'wallet' => '0x'.str_repeat('a', 40), 'base_usdt' => 1, 'amount_usdt' => 1.000123, 'credited_gold' => 100, 'expires_at' => now()->addHour(),
        ]);
        $this->fakeApis([
            'api.bscscan.com/*' => Http::response(['status' => '0', 'message' => 'NOTOK', 'result' => 'Invalid API Key']),
        ]);

        for ($i = 0; $i < 4; $i++) {
            app(\App\Services\UsdtPayment::class)->watch();
        }
        $this->assertCount(0, $this->telegramSends(), 'four failed minutes is a blip');
        app(\App\Services\UsdtPayment::class)->watch();

        $caption = (string) (self::field($this->telegramSends()[0], 'caption') ?? self::field($this->telegramSends()[0], 'text'));
        $this->assertStringContainsString('ระบบตรวจยอดโอน USDT ใช้งานไม่ได้', $caption);
        $this->assertStringContainsString('Invalid API Key', $caption);
    }

    public function test_a_changed_receiving_wallet_is_a_security_alarm(): void
    {
        $this->telegramOn();
        $this->fakeApis();

        PaymentAlerts::walletChanged('0x'.str_repeat('a', 40), '0x'.str_repeat('e', 40), false, 'แอดมิน');
        PaymentAlerts::walletChanged('0xsame', '0xSAME', false, 'แอดมิน');   // case-only / no change → silent

        $sends = $this->telegramSends();
        $this->assertCount(1, $sends);
        $this->assertStringContainsString('0xeeee', (string) (self::field($sends[0], 'caption') ?? self::field($sends[0], 'text')));
    }

    public function test_a_recovered_source_is_announced_and_its_outage_can_alert_again(): void
    {
        $this->telegramOn();
        $this->fakeApis();
        SourceHealth::record('24hdx', 0, 6);
        AdminAlerts::send($this->alert(), 360);             // the outage alert, as the canary sends it
        SourceHealth::record('24hdx', 3, 3);

        $captions = array_map(fn (Request $r) => (string) (self::field($r, 'caption') ?? self::field($r, 'text')), $this->telegramSends());
        $this->assertStringContainsString('กลับมาดึงลิ้งค์ได้แล้ว', end($captions));
        $this->assertTrue(AdminAlerts::send($this->alert(), 360), 'a new outage after recovery is not muted by the old one');
    }

    public function test_watchdog_reports_failed_jobs_since_its_last_look_and_stale_catalogues(): void
    {
        $this->telegramOn();
        $this->fakeApis();
        Setting::write('auto_import_enabled', '1');
        Setting::write('auto_import_schedules', json_encode(['wowdrama' => ['enabled' => true, 'time' => '05:00']]));
        SourceTitle::create(['source' => 'wowdrama', 'source_key' => 'a', 'title' => 'x', 'synced_at' => now()->subDays(9)]);

        $this->artisan('netwix:watchdog')->assertSuccessful();     // first look: starts the failed-jobs clock
        DB::table('failed_jobs')->insert([
            'uuid' => 'u1', 'connection' => 'database', 'queue' => 'clips',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\GenerateMarketingClip']),
            'exception' => "RuntimeException: ffmpeg exited 1\n#0 trace", 'failed_at' => now(),
        ]);
        $this->artisan('netwix:watchdog')->assertSuccessful();

        $captions = implode("\n---\n", array_map(fn (Request $r) => (string) (self::field($r, 'caption') ?? self::field($r, 'text')), $this->telegramSends()));
        $this->assertStringContainsString('ไม่มีรายชื่อเรื่องใหม่มา 9 วัน', $captions);
        $this->assertStringContainsString('งานเบื้องหลังล้มเหลว 1 งาน', $captions);
        $this->assertSame(1, substr_count($captions, 'ไม่มีรายชื่อเรื่องใหม่'), 'freshness is checked once a day');
        $this->assertNotNull(Cache::get('scheduler:heartbeat'));
    }

    public function test_an_alert_raised_in_a_web_request_is_delivered_after_the_response(): void
    {
        $this->telegramOn();
        $this->fakeApis();
        Setting::write('scrape_guard_mode', 'enforce');

        // A credential scanner: banned by the guard (a probe scores 30, the ban line is 60, so it
        // takes two), and the ban reported — once the response is out.
        // (Not under /api — that prefix is where our catalogue lives, so it would read as harvesting.)
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/wp-login.php');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/.env');

        $sends = $this->telegramSends();
        $this->assertCount(1, $sends);
        $caption = (string) (self::field($sends[0], 'caption') ?? self::field($sends[0], 'text'));
        $this->assertStringContainsString('203.0.113.9', $caption);
        // Probing for secrets is catalogued as an attack, so it rings (only 'scan'-kind noise is silent).
        $this->assertStringContainsString('มีคนพยายามเจาะระบบ', $caption);
        $this->assertSame('false', self::field($sends[0], 'disable_notification'));
    }

    public function test_page_views_notice_a_dead_scheduler(): void
    {
        $this->telegramOn();
        $this->fakeApis();
        Cache::forever('scheduler:heartbeat', now()->subMinutes(45)->timestamp);

        $this->get('/')->assertOk();

        $caption = (string) (self::field($this->telegramSends()[0], 'caption') ?? self::field($this->telegramSends()[0], 'text'));
        $this->assertStringContainsString('cron', $caption);
    }

    public function test_the_daily_report_counts_yesterday_in_thai_time(): void
    {
        $this->telegramOn();
        $this->fakeApis();
        $user = User::factory()->create(['created_at' => now('Asia/Bangkok')->subDay()->setTime(10, 0)->utc()]);
        UsdtOrder::create([
            'reference' => 'NXDAY0001', 'user_id' => $user->id, 'purpose' => 'pro', 'status' => 'paid', 'wallet' => '0x1',
            'base_usdt' => 7, 'amount_usdt' => 7.5, 'pro_days' => 30, 'tx_hash' => '0x'.str_repeat('f', 64),
            'paid_at' => now('Asia/Bangkok')->subDay()->setTime(23, 30)->utc(),     // 23:30 Thai = still "yesterday"
        ]);

        $this->artisan('netwix:daily-report')->assertSuccessful();

        $caption = (string) (self::field($this->telegramSends()[0], 'caption') ?? self::field($this->telegramSends()[0], 'text'));
        $this->assertStringContainsString('สรุปประจำวัน', $caption);
        $this->assertStringContainsString('รายได้: <b>7.5 USDT</b>', $caption);
        $this->assertStringContainsString('สมาชิกใหม่: <b>1 คน</b>', $caption);
    }

    public function test_an_error_alerts_once_per_shape_and_never_carries_a_secret(): void
    {
        $this->telegramOn();
        $this->fakeApis();
        Setting::write('bscscan_api_key', 'SUPERSECRETKEY42');

        foreach ([7, 8] as $user) {     // one throw site, two different values — one error
            ErrorAlert::report(new RuntimeException("BscScan said no to https://api.bscscan.com/api?apikey=SUPERSECRETKEY42&page=1 for user {$user}"));
        }

        $sends = $this->telegramSends();
        $this->assertCount(1, $sends, 'the same error shape re-alerts at most every 6h');
        $caption = (string) (self::field($sends[0], 'caption') ?? self::field($sends[0], 'text'));
        $this->assertStringNotContainsString('SUPERSECRETKEY42', $caption);
        $this->assertStringContainsString('apikey=***', $caption);
    }

    public function test_redaction_covers_tokens_keys_credentials_and_emails(): void
    {
        $text = Redact::text('bot'.self::TOKEN.' Bearer abcdefghijklmnop mysql://root:hunter2@db somchai.k@gmail.com');

        $this->assertStringNotContainsString('AAHdqTcv', $text);
        $this->assertStringNotContainsString('abcdefghijklmnop', $text);
        $this->assertStringNotContainsString('hunter2', $text);
        $this->assertStringContainsString('s***@gmail.com', $text);
    }

    public function test_the_card_is_a_real_png(): void
    {
        if (! AlertCard::available()) {
            $this->markTestSkipped('GD with FreeType is not available here — Telegram falls back to text.');
        }
        $png = AlertCard::png($this->alert(['bars' => ['rongyok' => 21, 'wowdrama' => 11], 'chips' => ['24hdx' => false, 'rongyok' => true]]));

        $this->assertNotNull($png);
        $this->assertStringStartsWith("\x89PNG", $png);
        $this->assertSame(AlertCard::WIDTH, getimagesizefromstring($png)[0]);
    }
}
