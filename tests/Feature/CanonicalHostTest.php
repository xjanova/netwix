<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every page must exist at exactly one URL. Before this, `http://netwix.online/…` answered 200 with an
 * http canonical, so Google indexed http twins of real pages next to the https originals.
 */
class CanonicalHostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://netwix.online']);
    }

    public function test_plain_http_is_moved_to_https_with_the_query_intact(): void
    {
        $this->get('http://netwix.online/movies?page=2')
            ->assertStatus(301)
            ->assertRedirect('https://netwix.online/movies?page=2');
    }

    public function test_www_alias_still_folds_onto_the_apex_over_either_scheme(): void
    {
        $this->get('http://www.netwix.online/title/x')->assertStatus(301)->assertRedirect('https://netwix.online/title/x');
        $this->get('https://www.netwix.online/title/x')->assertStatus(301)->assertRedirect('https://netwix.online/title/x');
    }

    public function test_https_is_served_without_a_redirect(): void
    {
        $this->get('https://netwix.online/up')->assertOk();
    }

    /**
     * Cloudflare Flexible mode reaches the origin over http even for https visitors. Trusting the hop
     * instead of the visitor would bounce every https visitor to https forever.
     */
    public function test_https_visitor_arriving_over_an_http_hop_is_not_looped(): void
    {
        $this->get('http://netwix.online/up', ['CF-Visitor' => '{"scheme":"https"}'])->assertOk();
        $this->get('http://netwix.online/up', ['X-Forwarded-Proto' => 'https'])->assertOk();
    }

    public function test_http_visitor_behind_cloudflare_is_still_moved(): void
    {
        $this->get('http://netwix.online/up', ['CF-Visitor' => '{"scheme":"http"}', 'X-Forwarded-Proto' => 'http'])
            ->assertStatus(301)
            ->assertRedirect('https://netwix.online/up');
    }

    public function test_acme_challenge_and_unsafe_methods_are_left_alone(): void
    {
        $this->assertNotSame(301, $this->get('http://netwix.online/.well-known/acme-challenge/token')->getStatusCode());
        $this->assertNotSame(301, $this->post('http://netwix.online/login')->getStatusCode());
    }

    public function test_an_http_app_url_never_redirects(): void
    {
        config(['app.url' => 'http://localhost']);

        $this->get('http://localhost/up')->assertOk();
    }
}
