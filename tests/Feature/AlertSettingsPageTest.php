<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\AdminAlerts;
use App\Support\Alerts\AlertCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * /admin/alerts — the owner's path from nothing to a working Telegram bot: paste the token (checked
 * with Telegram on save), press Start, pick the chat from a list, test. And the token must never
 * come back out: not in the page, not in the session.
 */
class AlertSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_the_page_renders_both_channels_and_never_the_token(): void
    {
        Setting::write('telegram_bot_token', self::TOKEN);
        Setting::write('telegram_bot_username', 'NetWixAlertBot');

        $this->actingAs($this->admin)->get(route('admin.alerts.index'))
            ->assertOk()
            ->assertSee('Telegram', false)
            ->assertSee('LINE Official Account', false)
            ->assertSee('@NetWixAlertBot', false)
            ->assertSee('แจ้งเรื่องอะไร เข้าช่องทางไหน', false)
            ->assertDontSee(self::TOKEN, false)
            ->assertDontSee('AAHdqTcv', false);
    }

    public function test_a_fresh_install_shows_the_setup_steps(): void
    {
        $this->actingAs($this->admin)->get(route('admin.alerts.index'))
            ->assertOk()
            ->assertSee('@BotFather', false)
            ->assertSee('ยังไม่ได้ตั้งค่า', false)
            ->assertSee('ยังไม่มีข้อความที่ส่งไป', false);
    }

    public function test_the_old_line_only_address_still_lands_on_the_page(): void
    {
        $this->actingAs($this->admin)->get('/admin/line-alerts')->assertRedirect('/admin/alerts');
    }

    public function test_saving_a_token_checks_it_with_telegram_and_remembers_the_bot(): void
    {
        Http::fake(['api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'NetWixAlertBot', 'first_name' => 'NetWix']])]);

        $this->actingAs($this->admin)->put(route('admin.alerts.telegram.update'), ['telegram_bot_token' => self::TOKEN])
            ->assertSessionHasNoErrors();

        $this->assertSame(self::TOKEN, Setting::get('telegram_bot_token'));
        $this->assertSame('NetWixAlertBot', Setting::get('telegram_bot_username'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/getMe'));
    }

    public function test_a_token_telegram_rejects_is_not_saved(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized'], 401)]);

        $this->actingAs($this->admin)->put(route('admin.alerts.telegram.update'), ['telegram_bot_token' => self::TOKEN])
            ->assertSessionHasErrors('telegram_bot_token');

        $this->assertNull(Setting::get('telegram_bot_token'));
    }

    public function test_a_malformed_token_is_refused_and_not_flashed_into_the_session(): void
    {
        $this->actingAs($this->admin)->from(route('admin.alerts.index'))
            ->put(route('admin.alerts.telegram.update'), ['telegram_bot_token' => 'not-a-token-SECRETISH'])
            ->assertSessionHasErrors('telegram_bot_token');

        $this->assertNull(session()->getOldInput('telegram_bot_token'), 'a pasted secret must not sit in the session store');
        Http::assertNothingSent();
    }

    public function test_the_owner_picks_the_chat_from_the_ones_that_talked_to_the_bot(): void
    {
        Setting::write('telegram_bot_token', self::TOKEN);
        Http::fake(['api.telegram.org/*/getUpdates' => Http::response(['ok' => true, 'result' => [
            ['update_id' => 1, 'message' => ['chat' => ['id' => 777111, 'type' => 'private', 'first_name' => 'เจ้าของ', 'last_name' => 'เว็บ']]],
            ['update_id' => 2, 'my_chat_member' => ['chat' => ['id' => -1009998887776, 'type' => 'supergroup', 'title' => 'ทีม NetWix']]],
        ]])]);

        $this->actingAs($this->admin)->from(route('admin.alerts.index'))
            ->post(route('admin.alerts.telegram.detect'))->assertSessionHas('tg_chats');
        $this->actingAs($this->admin)->get(route('admin.alerts.index'))
            ->assertSee('เจ้าของ เว็บ', false)->assertSee('ทีม NetWix', false)->assertSee('-1009998887776', false);

        $this->actingAs($this->admin)->post(route('admin.alerts.telegram.chat'), ['chat_id' => '-1009998887776'])->assertSessionHasNoErrors();
        $this->assertSame('-1009998887776', Setting::get('telegram_chat_id'));
        $this->assertTrue(Setting::flag('telegram_alerts_enabled'), 'picking the chat switches alerts on');
    }

    public function test_the_test_button_sends_a_live_status_card(): void
    {
        Setting::write('telegram_bot_token', self::TOKEN);
        Setting::write('telegram_chat_id', '777111');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        $this->actingAs($this->admin)->post(route('admin.alerts.telegram.test'))->assertSessionHas('status');

        Http::assertSent(fn (Request $r) => preg_match('~/(sendPhoto|sendMessage)$~', $r->url()) === 1);
    }

    public function test_the_routing_matrix_saves_what_was_ticked(): void
    {
        $this->actingAs($this->admin)->put(route('admin.alerts.routes'), ['routes' => [
            'telegram' => ['money', 'daily', 'not-a-category'],
        ]])->assertSessionHasErrors();

        $this->actingAs($this->admin)->put(route('admin.alerts.routes'), ['routes' => ['telegram' => ['money', 'daily']]])
            ->assertSessionHasNoErrors();

        $this->assertSame(['money', 'daily'], AdminAlerts::routes()['telegram']);
        $this->assertSame([], AdminAlerts::routes()['line'], 'an untouched column means nothing goes there');
    }

    public function test_the_card_preview_is_an_image(): void
    {
        if (! AlertCard::available()) {
            $this->markTestSkipped('GD with FreeType is not available here.');
        }

        $this->actingAs($this->admin)->get(route('admin.alerts.preview', 'critical'))
            ->assertOk()->assertHeader('Content-Type', 'image/png');
    }
}
