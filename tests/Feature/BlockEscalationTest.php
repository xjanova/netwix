<?php

namespace Tests\Feature;

use App\Models\BlockedIp;
use App\Models\IpOffence;
use App\Models\Setting;
use App\Models\User;
use App\Support\ScrapeGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The escalating penalty: same client, same behaviour, heavier sentence each time they come back.
 *
 * Written because every defect this guard has shipped was found by running it, never by reading it —
 * a rule registered where 404s never reach it, a counter whose window never expired, a firewall
 * filter that silently dropped every IPv6 block. The dangerous half of an escalating ban is not that
 * it fails to escalate; it is that it escalates too fast and hands a real viewer a permanent ban.
 */
class BlockEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::write('scrape_guard_mode', 'enforce');
        Setting::write('scrape_block_hours', '6');
        Setting::write('scrape_block_repeat_hours', '720');
        Cache::flush();
    }

    /** Drive one probe request through the guard as a given address. */
    private function probe(string $ip): void
    {
        $request = Request::create('/api/.env', 'GET', server: ['REMOTE_ADDR' => $ip]);
        ScrapeGuard::inspectProbe($request);
    }

    /**
     * Behave badly enough to earn a ban, once.
     *
     * A probe is worth 30 and the block score is 60, so a single one is deliberately not enough —
     * the guard wants a second offence before it acts. Tests that mean "this client got banned" have
     * to actually cross that line rather than assume one request does it.
     */
    private function earnBan(string $ip): void
    {
        $this->probe($ip);
        $this->probe($ip);
    }

    /** Let the current ban run out, so the next offence is judged as a return visit. */
    private function serveSentence(string $ip): void
    {
        BlockedIp::where('ip', ScrapeGuard::blockKey($ip))->update(['expires_at' => now()->subMinute()]);
        Cache::flush();
    }

    public function test_first_offence_uses_the_admin_default(): void
    {
        $this->earnBan('203.0.113.10');

        $block = BlockedIp::where('ip', '203.0.113.10')->firstOrFail();
        $this->assertFalse($block->manual);
        $this->assertNotNull($block->expires_at);
        $this->assertEqualsWithDelta(6, $block->expires_at->diffInHours(now(), true), 0.2);
        $this->assertSame(1, IpOffence::where('ip', '203.0.113.10')->value('offences'));
    }

    /**
     * The one that matters. inspect() records BEFORE it consults the blocklist, and a scanner does
     * not stop when it gets a 403 — so a burst re-crosses the block score every few seconds. Without
     * the "already banned = still the same offence" guard, this test would find a PERMANENT ban after
     * a few seconds of traffic from a client that has only ever visited once.
     */
    public function test_a_burst_while_already_banned_does_not_climb_the_ladder(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->probe('203.0.113.11');
        }

        $block = BlockedIp::where('ip', '203.0.113.11')->firstOrFail();
        $this->assertNotNull($block->expires_at, 'a single visit must never earn a permanent ban');
        $this->assertEqualsWithDelta(6, $block->expires_at->diffInHours(now(), true), 0.2);
        $this->assertSame(1, IpOffence::where('ip', '203.0.113.11')->value('offences'));
    }

    /** Serving the ban and coming back to do it again is the second offence: a month. */
    public function test_second_offence_after_the_first_expired_is_a_month(): void
    {
        $this->earnBan('203.0.113.12');
        $this->serveSentence('203.0.113.12');
        $this->earnBan('203.0.113.12');

        $block = BlockedIp::where('ip', '203.0.113.12')->firstOrFail();
        $this->assertEqualsWithDelta(720, $block->expires_at->diffInHours(now(), true), 0.5);
        $this->assertSame(2, IpOffence::where('ip', '203.0.113.12')->value('offences'));
    }

    /** Third time, it stops having an end date. */
    public function test_third_offence_is_permanent(): void
    {
        // Two full bans served, then a third return. The sentence is only expired between rounds —
        // expiring it after the third would overwrite the very NULL this test exists to check.
        $this->earnBan('203.0.113.13');
        $this->serveSentence('203.0.113.13');
        $this->earnBan('203.0.113.13');
        $this->serveSentence('203.0.113.13');
        $this->earnBan('203.0.113.13');

        $block = BlockedIp::where('ip', '203.0.113.13')->firstOrFail();
        $this->assertSame(3, IpOffence::where('ip', '203.0.113.13')->value('offences'));
        $this->assertNull($block->expires_at, 'the third offence must not expire');
        $this->assertFalse($block->manual, 'permanent is not the same as admin-set');
        $this->assertTrue($block->active);
    }

    /**
     * A permanent AUTOMATIC ban is `manual = 0, expires_at = NULL`, which matches neither arm of the
     * old hand-written `manual = 1 OR expires_at > now()` filter — NULL > now() is NULL, not true.
     * It would have been listed on the admin page and quietly missing from the firewall.
     */
    public function test_a_permanent_automatic_block_counts_as_active_in_queries(): void
    {
        BlockedIp::create(['ip' => '203.0.113.14', 'reason' => 'probe', 'score' => 90, 'expires_at' => null, 'manual' => false]);

        $this->assertSame(1, BlockedIp::active()->count());
        $this->assertTrue(ScrapeGuard::isBlocked('203.0.113.14'));
    }

    /** An expired ban is over: it must not keep being enforced or written to the firewall. */
    public function test_an_expired_block_is_not_active(): void
    {
        BlockedIp::create(['ip' => '203.0.113.15', 'reason' => 'probe', 'score' => 90, 'expires_at' => now()->subHour(), 'manual' => false]);

        $this->assertSame(0, BlockedIp::active()->count());
        $this->assertFalse(ScrapeGuard::isBlocked('203.0.113.15'));
    }

    /**
     * A hand-placed block with a duration must actually end when that duration is up.
     *
     * The admin form offers 1 ชม. through 30 วัน beside ถาวร and writes the expiry it is told to, but
     * `active` used to short-circuit on `manual` and ignore it. Every timed manual block was a
     * permanent one, and the admin who chose six hours was never told otherwise.
     */
    public function test_a_timed_manual_block_expires_but_a_permanent_one_does_not(): void
    {
        BlockedIp::create(['ip' => '203.0.113.30', 'reason' => 'manual', 'score' => 0, 'expires_at' => now()->subHour(), 'manual' => true]);
        BlockedIp::create(['ip' => '203.0.113.31', 'reason' => 'manual', 'score' => 0, 'expires_at' => null, 'manual' => true]);

        $this->assertFalse(ScrapeGuard::isBlocked('203.0.113.30'), 'a manual block that ran out is over');
        $this->assertTrue(ScrapeGuard::isBlocked('203.0.113.31'), 'ถาวร is a NULL expiry, and still means forever');
        $this->assertSame(1, BlockedIp::active()->count());
    }

    /** Old history is not held against anyone forever — past the horizon the ladder starts over. */
    public function test_offences_older_than_the_memory_window_reset_the_ladder(): void
    {
        IpOffence::create([
            'ip' => '203.0.113.16', 'offences' => 2, 'last_reason' => 'probe',
            'first_at' => now()->subDays(200), 'last_at' => now()->subDays(120),
        ]);

        $this->earnBan('203.0.113.16');

        $this->assertSame(1, IpOffence::where('ip', '203.0.113.16')->value('offences'));
        $block = BlockedIp::where('ip', '203.0.113.16')->firstOrFail();
        $this->assertEqualsWithDelta(6, $block->expires_at->diffInHours(now(), true), 0.2);
    }

    /** IPv6 escalates per subscriber (/64), not per address a carrier happens to be rotating. */
    public function test_ipv6_escalation_follows_the_prefix_not_the_address(): void
    {
        $this->earnBan('2405:9800:baa0:5f11::1');
        $this->serveSentence('2405:9800:baa0:5f11::1');

        // Same household, different address in the same /64.
        $this->earnBan('2405:9800:baa0:5f11::99ff');

        $this->assertSame(2, IpOffence::where('ip', '2405:9800:baa0:5f11::/64')->value('offences'));
    }

    /** Forgiving a client wipes the ladder — the escape hatch for a rule that was wrong. */
    public function test_admin_can_clear_a_clients_ban_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $row = IpOffence::create(['ip' => '203.0.113.17', 'offences' => 2, 'first_at' => now(), 'last_at' => now()]);

        $this->actingAs($admin)
            ->delete(route('admin.security.forgive', $row))
            ->assertRedirect();

        $this->assertDatabaseMissing('ip_offences', ['ip' => '203.0.113.17']);
    }

    public function test_security_page_renders_with_repeat_offenders(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        IpOffence::create(['ip' => '203.0.113.18', 'offences' => 3, 'last_reason' => 'probe', 'first_at' => now(), 'last_at' => now()]);

        $this->actingAs($admin)->get(route('admin.security.index'))
            ->assertStatus(200)
            ->assertSee('ประวัติผู้ทำผิดซ้ำ', false)
            ->assertSee('203.0.113.18', false);
    }

    /**
     * The state prod is in the minute this ships: blocks that already exist, and a history table with
     * nothing in it. Every one of those rows renders a badge lookup against an empty collection, so
     * this is the page working at all, not a detail.
     */
    public function test_security_page_renders_for_blocks_that_predate_the_history_table(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        BlockedIp::create(['ip' => '203.0.113.19', 'reason' => 'probe', 'score' => 60, 'expires_at' => now()->addHours(6), 'manual' => false]);
        BlockedIp::create(['ip' => '203.0.113.20', 'reason' => 'manual', 'score' => 0, 'expires_at' => null, 'manual' => true]);

        $this->actingAs($admin)->get(route('admin.security.index'))
            ->assertStatus(200)
            ->assertSee('203.0.113.19', false)
            ->assertSee('203.0.113.20', false)
            ->assertDontSee('ทำผิดครั้งที่', false)          // no history yet, so no badge
            ->assertDontSee('ประวัติผู้ทำผิดซ้ำ', false);    // and no section at all
    }

    /** With history, the row says which time this is. */
    public function test_a_repeat_offenders_block_row_is_labelled(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        BlockedIp::create(['ip' => '203.0.113.21', 'reason' => 'probe', 'score' => 60, 'expires_at' => null, 'manual' => false]);
        IpOffence::create(['ip' => '203.0.113.21', 'offences' => 3, 'last_reason' => 'probe', 'first_at' => now()->subMonth(), 'last_at' => now()]);

        $this->actingAs($admin)->get(route('admin.security.index'))
            ->assertStatus(200)
            ->assertSee('ทำผิดครั้งที่ 3', false)
            ->assertSee('ถาวร', false);
    }

    /**
     * A hand-placed block is the admin's judgement, and an automatic rule must not quietly rewrite
     * the reason recorded on it while the client keeps knocking.
     */
    public function test_an_automatic_rule_does_not_overwrite_a_manual_block(): void
    {
        BlockedIp::create(['ip' => '203.0.113.22', 'reason' => 'manual', 'score' => 0, 'expires_at' => null, 'manual' => true]);

        $this->earnBan('203.0.113.22');

        $block = BlockedIp::where('ip', '203.0.113.22')->firstOrFail();
        $this->assertSame('manual', $block->reason);
        $this->assertTrue($block->manual);
        $this->assertSame(0, IpOffence::where('ip', '203.0.113.22')->count(), 'a manual block is not a rung on the ladder');
    }

    /** Refusals accumulate across bans: the count is evidence, and a new sentence does not erase it. */
    public function test_refusal_count_survives_a_new_offence(): void
    {
        $this->earnBan('203.0.113.23');
        BlockedIp::where('ip', '203.0.113.23')->update(['hits' => 5000]);
        $this->serveSentence('203.0.113.23');

        $this->earnBan('203.0.113.23');

        // Greater than, not equal to: the request that earns the new ban is itself refused, and that
        // refusal is counted too. What matters is that the 5,000 from the previous ban are still here.
        $this->assertGreaterThanOrEqual(5000, BlockedIp::where('ip', '203.0.113.23')->value('hits'));
    }

    /** A history the ladder no longer counts must not be shown predicting a penalty it will not apply. */
    public function test_a_stale_history_is_not_listed_as_a_repeat_offender(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        IpOffence::create([
            'ip' => '203.0.113.25', 'offences' => 3, 'last_reason' => 'probe',
            'first_at' => now()->subDays(300), 'last_at' => now()->subDays(150),
        ]);

        $this->actingAs($admin)->get(route('admin.security.index'))
            ->assertStatus(200)
            ->assertDontSee('203.0.113.25', false);
    }

    /** And the row itself is eventually swept, since it decides nothing. */
    public function test_prune_removes_only_histories_past_the_window(): void
    {
        IpOffence::create(['ip' => '203.0.113.26', 'offences' => 2, 'first_at' => now()->subDays(300), 'last_at' => now()->subDays(150)]);
        IpOffence::create(['ip' => '203.0.113.27', 'offences' => 2, 'first_at' => now()->subDays(10), 'last_at' => now()->subDay()]);

        $this->artisan('netwix:security:prune-offences')->assertSuccessful();

        $this->assertDatabaseMissing('ip_offences', ['ip' => '203.0.113.26']);
        $this->assertDatabaseHas('ip_offences', ['ip' => '203.0.113.27']);
    }

    /** Nothing is ever refused in observe mode — including a client that would have escalated. */
    public function test_observe_mode_records_but_never_bans(): void
    {
        Setting::write('scrape_guard_mode', 'observe');
        Cache::flush();

        $this->earnBan('203.0.113.24');

        $this->assertSame(0, BlockedIp::count());
        $this->assertSame(0, IpOffence::count());
        $this->assertDatabaseHas('security_events', ['ip' => '203.0.113.24', 'reason' => 'probe']);
    }
}
